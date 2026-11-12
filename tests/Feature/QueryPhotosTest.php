<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\DataSource;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\PhotoCheck;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueryPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        $source = DataSource::create([
            'name' => 'ODK test', 'type' => DataSource::TYPE_ODK, 'server_url' => 'https://odk.test',
            'credentials' => ['email' => 'a@b.c', 'password' => 'secret'], 'project_id' => '3', 'form_id' => 'cov26c4v2',
        ]);
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:p', [
            'today' => '2026-11-06', 'l1' => '2', 'l2' => '2', 'l3' => '28', 'village' => '1', 'fieldworker' => '34',
            'household' => 'DAG-MIN-03-001', 'household_survey_status' => '0',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => [
            'smc_cov_hh_cg_child_2023' => [['key' => null, 'repeats' => [], 'data' => [
                'child_dob' => '2025-05-02', 'receive_vaccine' => '1', 'mal1date' => '2025-11-30',
                'dob_doc_image' => 'dob.jpg', 'chb_image1' => 'carnet.jpg',
            ]]],
        ]]]], []), ['data_source_id' => $source->id, 'source_type' => 'odk_central']);
        $this->household = Household::firstOrFail();
        File::deleteDirectory(storage_path('app/private/attachments/'.$this->household->id));

        PhotoCheck::create(['submission_uuid' => 'uuid:p', 'instance_path' => 'cg[1]/smc_cov_hh_cg_child_2023[1]', 'variable' => 'mal1date',
            'expected' => '2025-11-03', 'photo' => 'carnet.jpg', 'message' => 'VAP 1 : le carnet porte 03/11/2025']);
        app(QualityEngine::class)->run('test');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/attachments/'.$this->household->id));
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(User::ROLE_ADMIN);

        return $user;
    }

    public function test_query_page_shows_child_photos_with_checked_photo_first(): void
    {
        $query = DataQuery::where('rule_code', 'PHOTO_CHECK')->firstOrFail();

        $html = $this->actingAs($this->admin())->get(route('queries.show', $query))->assertOk()
            ->assertSee('Photo vérifiée')->assertSee('Prenez une photo de la première page des vaccinations')->getContent();

        $carnet = route('households.photo', [$this->household, 'carnet.jpg']);
        $dob = route('households.photo', [$this->household, 'dob.jpg']);
        $this->assertTrue(strpos($html, $carnet) < strpos($html, $dob), 'la photo vérifiée est affichée en premier');
    }

    public function test_same_card_query_shows_photos_of_both_children(): void
    {
        $query = DataQuery::create([
            'fingerprint' => 'dup-card-test', 'rule_code' => 'DUP_CARD', 'severity' => 'majeure', 'household_id' => $this->household->id,
            'child_id' => $this->household->children()->value('id'), 'submission_uuid' => 'uuid:p', 'message' => 'Même carnet',
            'status' => DataQuery::STATUS_OPEN, 'detected_at' => now(), 'last_detected_at' => now(),
        ]);
        $twin = $this->household->children()->first()->replicate();
        $twin->fill(['position' => 2, 'data' => ['child_dob' => '2025-05-02', 'chb_image1' => 'carnet2.jpg']])->save();

        $this->actingAs($this->admin())->get(route('queries.show', $query))->assertOk()
            ->assertSee('Photos des enfants nés le même jour')
            ->assertSee(route('households.photo', [$this->household, 'carnet.jpg']))
            ->assertSee(route('households.photo', [$this->household, 'carnet2.jpg']))
            ->assertSee('Enfant n°2 – ');
    }

    public function test_photo_is_downloaded_once_from_odk_then_served_from_cache(): void
    {
        Http::fake([
            'odk.test/v1/sessions' => Http::response(['token' => 'T', 'expiresAt' => now()->addDay()->toIso8601String()]),
            'odk.test/v1/projects/3/forms/cov26c4v2/submissions/uuid%3Ap/attachments/carnet.jpg' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $admin = $this->admin();

        // Deux consultations : la seconde est servie depuis le cache local.
        for ($i = 0; $i < 2; $i++) {
            $response = $this->actingAs($admin)->get(route('households.photo', [$this->household, 'carnet.jpg']))->assertOk();
            $this->assertSame('JPEGDATA', $response->streamedContent());
        }
        Http::assertSentCount(2); // session + une seule pièce jointe
    }

    public function test_only_photos_of_the_submission_and_in_scope_are_served(): void
    {
        Http::fake();
        $this->actingAs($this->admin())->get(route('households.photo', [$this->household, 'autre.jpg']))->assertNotFound();

        $supervisor = User::factory()->create(['health_zone_id' => HealthZone::where('code', '1')->value('id')]);
        $supervisor->assignRole(User::ROLE_SUPERVISOR);
        $this->actingAs($supervisor)->get(route('households.photo', [$this->household, 'carnet.jpg']))->assertForbidden();
        Http::assertNothingSent();
    }
}
