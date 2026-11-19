<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\User;
use App\Services\Attachments\PhotoReader;
use App\Services\Attachments\SubmissionPhotos;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Lecture de la valeur sur les photos par l'IA : suggestion « à vérifier », jamais appliquée d'office. */
class PhotoReaderTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private DataQuery $query;

    private User $manager;

    /** @var list<array<string, mixed>> contenu envoyé à l'API (simulée) */
    public static array $sent = [];

    public static array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:1', [
            'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0', 'visit_num' => '1', 'current_location' => '9.31 2.49 0 5',
        ], [], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');
        $this->household = Household::where('submission_uuid', 'uuid:1')->firstOrFail();
        $this->household->update(['data' => ['chb_image1' => 'carnet.jpg'] + $this->household->data]);
        $this->query = DataQuery::where('submission_uuid', 'uuid:1')->whereNotNull('variable')->firstOrFail();
        $this->query->update(['variable' => 'visit_num']);
        $this->manager = User::factory()->create();
        $this->manager->assignRole(User::ROLE_DATA_MANAGER);

        // Photo locale (4000 px : doit être réduite avant l'envoi).
        $path = storage_path('app/private/attachments/'.$this->household->id.'/carnet.jpg');
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor(4000, 3000);
        imagejpeg($image, $path);

        self::$sent = [];
        $this->app->bind(PhotoReader::class, fn ($app) => new class($app->make(SubmissionPhotos::class)) extends PhotoReader
        {
            protected function ask(array $content, array $schema): array
            {
                PhotoReaderTest::$sent = $content;

                return PhotoReaderTest::$answer;
            }
        });
        config(['services.anthropic.key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/attachments/'.$this->household->id));
        parent::tearDown();
    }

    public function test_disabled_until_the_administrator_allows_it(): void
    {
        $this->actingAs($this->manager)->get(route('queries.show', $this->query))->assertOk()->assertDontSee('Lire la valeur avec l\'IA', false);
        $this->actingAs($this->manager)->post(route('queries.suggest-ai', $this->query))->assertForbidden();

        config(['services.anthropic.key' => null]);
        AppSetting::put(AppSetting::AI_READING, true);
        $this->assertFalse(PhotoReader::enabled());
    }

    public function test_value_read_by_ai_is_proposed_to_check(): void
    {
        AppSetting::put(AppSetting::AI_READING, true);
        self::$answer = ['found' => true, 'value' => '2', 'photo' => 1, 'confidence' => 'haute', 'note' => 'Visite 2 cochée sur la fiche.'];

        $this->actingAs($this->manager)->get(route('queries.show', $this->query))->assertOk()->assertSee('Lire la valeur avec l\'IA', false);
        $this->actingAs($this->manager)->post(route('queries.suggest-ai', $this->query))->assertSessionHas('success');

        $query = $this->query->fresh();
        $this->assertSame(['2', 'ia'], [$query->suggested_value, $query->suggested_source]);
        $this->assertStringContainsString('Confiance haute', $query->suggested_note);
        // Rien n'est appliqué : la valeur saisie reste celle de l'enquêteur.
        $this->assertSame('1', $this->household->fresh()->data['visit_num']);

        // Photo réduite et instructions avec la question et la valeur saisie.
        $image = collect(self::$sent)->firstWhere('type', 'image');
        [$width] = getimagesizefromstring(base64_decode($image['source']['data']));
        $this->assertLessThanOrEqual(1568, $width);
        $this->assertStringContainsString('visit_num', collect(self::$sent)->last()['text']);

        $this->actingAs($this->manager)->get(route('queries.show', $this->query))->assertSee('lue par l\'IA, à vérifier');
    }

    public function test_unreadable_or_invalid_values_are_not_proposed(): void
    {
        AppSetting::put(AppSetting::AI_READING, true);
        self::$answer = ['found' => false, 'value' => null, 'photo' => null, 'confidence' => 'faible', 'note' => 'Photo floue.'];
        $this->actingAs($this->manager)->post(route('queries.suggest-ai', $this->query))->assertSessionHas('danger');
        $this->assertNull($this->query->fresh()->suggested_source);

        self::$answer = ['found' => true, 'value' => '9', 'photo' => 1, 'confidence' => 'moyenne', 'note' => ''];
        $this->actingAs($this->manager)->post(route('queries.suggest-ai', $this->query))->assertSessionHas('danger');
        $this->assertNull($this->query->fresh()->suggested_source);
    }
}
