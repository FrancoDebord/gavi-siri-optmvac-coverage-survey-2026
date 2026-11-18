<?php

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\DataLock;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Gel de la base (versions signées et archivées) et piste d'audit unifiée. */
class DataLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->submit('uuid:1', 'TCH-SEB-01-001');
        app(QualityEngine::class)->run('test');
        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);
        $this->manager = User::factory()->create();
        $this->manager->assignRole(User::ROLE_DATA_MANAGER);
    }

    private function submit(string $uuid, string $code): string
    {
        return (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
            'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
            'household' => $code, 'household_survey_status' => '0', 'visit_num' => '1',
        ], [], []), ['source_type' => 'demo']);
    }

    private function propose(): TestResponse
    {
        $query = DataQuery::where('rule_code', 'GPS_MISSING')->firstOrFail();

        return $this->actingAs($this->manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite'],
        ]]);
    }

    public function test_freeze_locks_data_creates_an_archived_version_and_unfreeze_resumes(): void
    {
        $this->actingAs($this->manager)->get(route('locks.index'))->assertRedirect();
        $this->actingAs($this->admin)->post(route('locks.store'), ['reason' => 'Fin de la collecte', 'password' => 'mauvais'])->assertSessionHasErrors('password');
        $this->assertFalse(DataLock::isFrozen());

        $this->actingAs($this->admin)->post(route('locks.store'), ['reason' => 'Fin de la collecte, base prête pour l\'analyse', 'password' => 'password'])
            ->assertRedirect(route('locks.index'));
        $lock = DataLock::current();
        $this->assertNotNull($lock);
        $this->assertSame('V1', $lock->label());
        $this->assertTrue($lock->signed);
        $this->assertSame(1, $lock->stats['menages']);
        // Version archivée : paquet d'archivage généré, avec empreinte.
        $this->assertSame(DataExport::STATUS_DONE, $lock->export->status, (string) $lock->export->error);
        $this->assertSame(64, strlen($lock->export->sha256));
        $this->assertDatabaseHas('audit_logs', ['action' => 'data.gel']);

        // Base gelée : bandeau, aucune correction, synchronisation sans effet.
        $this->actingAs($this->manager)->get(route('queries.index'))->assertOk()->assertSee('Base gelée · version V1');
        $this->propose()->assertSessionHasErrors('status');
        $this->assertSame(SubmissionProcessor::UNCHANGED, $this->submit('uuid:2', 'TCH-SEB-01-002'));
        $this->assertSame(0, Household::where('submission_uuid', 'uuid:2')->count());

        // Dégel signé et motivé : tout reprend ; la soumission reçue pendant le gel entre au prochain passage.
        $this->actingAs($this->admin)->post(route('locks.store'), ['reason' => 'Correction demandée par le comité', 'password' => 'password'])->assertRedirect();
        $this->assertFalse(DataLock::isFrozen());
        $this->assertDatabaseHas('data_locks', ['action' => DataLock::UNFREEZE, 'version' => 1]);
        $this->propose()->assertSessionHas('success');
        $this->assertSame(SubmissionProcessor::NEW, $this->submit('uuid:2', 'TCH-SEB-01-002'));

        // Gel suivant : version 2.
        $this->actingAs($this->admin)->post(route('locks.store'), ['reason' => 'Nouvelle version après corrections', 'password' => 'password']);
        $this->assertSame('V2', DataLock::current()->label());
        $this->actingAs($this->admin)->get(route('locks.index'))->assertOk()->assertSee('V2')->assertSee('Correction demandée par le comité');
    }

    public function test_unified_audit_trail_by_user_with_csv_and_household_history(): void
    {
        $this->propose()->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('audit.index', ['user_id' => $this->manager->id]))->assertOk()
            ->assertSee('Correction proposée')->assertSee('Statut → Correction proposée – à valider');
        $this->actingAs($this->admin)->get(route('audit.index', ['source' => 'correction']))->assertOk()->assertSee('C-000001');
        $this->actingAs($this->manager)->get(route('audit.index'))->assertRedirect();

        $csv = $this->actingAs($this->admin)->get(route('audit.export', ['user_id' => $this->manager->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Correction proposée', $csv);
        $this->assertStringContainsString($this->manager->name, $csv);

        $household = Household::firstOrFail();
        $this->actingAs($this->manager)->get(route('households.show', $household))->assertOk()
            ->assertSee('Historique de la soumission')->assertSee('Reçue par la plateforme')->assertSee('Correction proposée : current_location');
    }
}
