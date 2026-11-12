<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Services\Connectors\ConnectorFactory;
use App\Services\Import\NormalizedSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private function odkSource(): DataSource
    {
        return DataSource::create([
            'name' => 'ODK test', 'type' => DataSource::TYPE_ODK, 'server_url' => 'https://odk.test',
            'credentials' => ['email' => 'a@b.c', 'password' => 'secret'], 'project_id' => '3', 'form_id' => 'cov26c4v2',
        ]);
    }

    private function koboSource(): DataSource
    {
        return DataSource::create([
            'name' => 'Kobo test', 'type' => DataSource::TYPE_KOBO, 'server_url' => 'https://kf.kobotoolbox.org',
            'credentials' => ['token' => 'tok123'], 'asset_uid' => 'aAbBcC',
        ]);
    }

    private static function odkSubmission(string $id): array
    {
        return [
            '__id' => $id,
            '__system' => ['submissionDate' => '2026-10-20T10:00:00.000Z', 'reviewState' => null, 'formVersion' => '3', 'submitterName' => 'tab01'],
            'today' => '2026-10-20',
            'household' => 'HH-1',
            'current_location' => ['type' => 'Point', 'coordinates' => [2.6, 8.88, 210], 'properties' => ['accuracy' => 4.5]],
            'section2' => [
                'num_slept_last_night' => 6,
                'cg' => [[
                    '__id' => 'cg1',
                    'participation_agreed' => '1',
                    'cg_consent' => ['firstname' => 'AB', 'smc_cov_hh_cg_child_2023' => [['__id' => 'ch1', 'child_sex' => '1']]],
                ]],
                'cg@odata.navigationLink' => "Submissions('{$id}')/section2/cg",
            ],
        ];
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $source = $this->odkSource();
        $raw = \DB::table('data_sources')->where('id', $source->id)->value('credentials');
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertSame('secret', $source->fresh()->credential('password'));
    }

    public function test_odk_authenticates_paginates_and_normalizes(): void
    {
        config(['gavi.http.page_size' => 1]);
        Http::fake([
            'odk.test/v1/sessions' => Http::response(['token' => 'T1', 'expiresAt' => now()->addDay()->toIso8601String()]),
            'odk.test/v1/projects/3/forms/cov26c4v2.svc/Submissions*' => Http::sequence()
                ->push(['value' => [self::odkSubmission('uuid:1')]])
                ->push(['value' => []]),
        ]);

        $source = $this->odkSource();
        $pages = [];
        $count = app(ConnectorFactory::class)->make($source)->fetchSubmissions(CarbonImmutable::parse('2026-10-19 08:00'), function ($page) use (&$pages) {
            $pages[] = $page;
        });

        $this->assertSame(1, $count);
        /** @var NormalizedSubmission $submission */
        $submission = $pages[0][0];
        $this->assertSame('uuid:1', $submission->uuid);
        $this->assertSame('8.88 2.6 210 4.5', $submission->data['current_location']);
        $this->assertSame('6', $submission->data['num_slept_last_night']);
        $this->assertSame('1', $submission->repeats['cg'][0]['data']['participation_agreed']);
        $this->assertSame('AB', $submission->repeats['cg'][0]['data']['firstname']);
        $this->assertSame('1', $submission->repeats['cg'][0]['repeats']['smc_cov_hh_cg_child_2023'][0]['data']['child_sex']);
        $this->assertSame('3', $submission->formVersion);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/sessions') && $r['email'] === 'a@b.c');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Submissions')
            && $r->hasHeader('Authorization', 'Bearer T1')
            && str_contains(urldecode($r->url()), '__system/submissionDate gt 2026-10-19T07:00:00.000Z')
            && str_contains(urldecode($r->url()), '$skip=0'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '$skip=1'));
        $this->assertSame('T1', $source->fresh()->auth_token);
    }

    public function test_odk_session_token_is_reused_then_renewed_on_401(): void
    {
        Http::fake([
            'odk.test/v1/sessions' => Http::sequence()->push(['token' => 'NEW', 'expiresAt' => now()->addDay()->toIso8601String()]),
            'odk.test/v1/projects/3/forms/cov26c4v2.svc/Submissions*' => Http::sequence()
                ->push(['message' => 'expired'], 401)
                ->push(['value' => []]),
        ]);
        $source = $this->odkSource();
        $source->forceFill(['auth_token' => 'OLD', 'auth_token_expires_at' => now()->addHours(5)])->save();

        app(ConnectorFactory::class)->make($source)->fetchSubmissions(null, fn () => null);

        Http::assertSentInOrder([
            fn (Request $r) => str_contains($r->url(), 'Submissions') && $r->hasHeader('Authorization', 'Bearer OLD'),
            fn (Request $r) => str_contains($r->url(), '/v1/sessions'),
            fn (Request $r) => str_contains($r->url(), 'Submissions') && $r->hasHeader('Authorization', 'Bearer NEW'),
        ]);
    }

    public function test_odk_connection_test_reports_errors(): void
    {
        Http::fake(['odk.test/v1/sessions' => Http::response(['message' => 'Identifiants invalides.'], 401)]);

        $result = app(ConnectorFactory::class)->make($this->odkSource())->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Authentification ODK Central', $result['message']);
        $this->assertStringContainsString('Identifiants invalides.', $result['message']);
    }

    public function test_odk_connection_test_success(): void
    {
        Http::fake([
            'odk.test/v1/sessions' => Http::response(['token' => 'T', 'expiresAt' => now()->addDay()->toIso8601String()]),
            'odk.test/v1/projects/3/forms/cov26c4v2' => Http::response(['xmlFormId' => 'cov26c4v2', 'name' => 'Country Coverage 2026', 'version' => '3', 'submissions' => 42]),
        ]);

        $result = app(ConnectorFactory::class)->make($this->odkSource())->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame(42, $result['details']['Soumissions']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Extended-Metadata', 'true'));
    }

    public function test_kobo_paginates_with_token_and_incremental_query(): void
    {
        config(['gavi.http.page_size' => 1]);
        $row = fn (string $uuid) => [
            '_uuid' => $uuid, '_submission_time' => '2026-10-20T10:00:00', '__version__' => 'vX',
            'household' => 'HH', 'section2/num_slept_last_night' => '5', 'current_location' => '8.8 2.6 200 5',
            'section2/cg' => [['section2/cg/participation_agreed' => '1', 'section2/cg/cg_consent/smc_cov_hh_cg_child_2023' => [['section2/cg/cg_consent/smc_cov_hh_cg_child_2023/child_sex' => '0']]]],
        ];
        Http::fake([
            'kf.kobotoolbox.org/api/v2/assets/aAbBcC/data.json*' => Http::sequence()
                ->push(['count' => 2, 'next' => 'https://kf.kobotoolbox.org/next', 'results' => [$row('u1')]])
                ->push(['count' => 2, 'next' => null, 'results' => [$row('u2')]]),
        ]);

        $all = [];
        $count = app(ConnectorFactory::class)->make($this->koboSource())->fetchSubmissions(CarbonImmutable::parse('2026-10-19 12:00'), function ($page) use (&$all) {
            array_push($all, ...$page);
        });

        $this->assertSame(2, $count);
        $this->assertSame('uuid:u1', $all[0]->uuid);
        $this->assertSame('5', $all[0]->data['num_slept_last_night']);
        $this->assertSame('0', NormalizedSubmission::findRepeat($all[0]->repeats, 'smc_cov_hh_cg_child_2023')[0]['data']['child_sex']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Token tok123')
            && str_contains(urldecode($r->url()), '"_submission_time":{"$gt":"2026-10-19T11:00:00"}')
            && str_contains($r->url(), 'start=0'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start=1'));
    }

    public function test_kobo_dictionary_import(): void
    {
        Http::fake([
            'kf.kobotoolbox.org/api/v2/assets/aAbBcC/*' => Http::response([
                'name' => 'Couverture', 'version_id' => 'v42',
                'content' => [
                    'survey' => [['type' => 'integer', 'name' => 'num', 'label' => ['Nombre'], 'required' => true]],
                    'choices' => [],
                    'translations' => [null],
                ],
            ]),
        ]);

        $version = app(ConnectorFactory::class)->make($this->koboSource())->importDictionary();

        $this->assertSame('aAbBcC', $version->form_id);
        $this->assertSame('v42', $version->version);
        $this->assertTrue($version->is_active);
        $this->assertSame(1, $version->variables()->where('name', 'num')->where('required', true)->count());
    }
}
