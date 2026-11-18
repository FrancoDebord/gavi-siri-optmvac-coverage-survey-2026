<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\Team;
use App\Models\User;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Base nettoyée : registre des corrections, source ODK intacte, résolution des queries constatée par le moteur. */
class CleanDataTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->submit('uuid:1', []);
        $this->submit('uuid:2', [], 'TCH-SEB-01-002');
        app(QualityEngine::class)->run('test');

        $this->manager = User::factory()->create();
        $this->manager->assignRole(User::ROLE_DATA_MANAGER);
        $this->account = User::factory()->create(['team_id' => Team::where('code', '46-47')->value('id')]);
        $this->account->assignRole(User::ROLE_TEAM);
    }

    /** Soumission telle que reçue d'ODK (même UUID = resynchronisation). */
    private function submit(string $uuid, array $extra, string $code = 'TCH-SEB-01-001'): string
    {
        return (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
            'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
            'household' => $code, 'household_survey_status' => '0', 'visit_num' => '1',
        ] + $extra, [], ['source' => 'odk', 'uuid' => $uuid]), ['source_type' => 'demo']);
    }

    private function gpsQuery(): DataQuery
    {
        $query = DataQuery::where('household_code', 'TCH-SEB-01-001')->where('rule_code', 'GPS_MISSING')->firstOrFail();
        $this->actingAs($this->manager)->post(route('queries.assign', $query), ['assigned_to' => $this->account->id]);

        return $query->fresh();
    }

    private function propose(DataQuery $query, array $corrections)
    {
        return $this->actingAs($this->account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => $corrections]);
    }

    public function test_validated_correction_updates_clean_base_and_resolves_the_query(): void
    {
        AppSetting::put(AppSetting::AUTO_APPLY, false);
        $query = $this->gpsQuery();
        $this->propose($query, [['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite', 'source_reference' => 'Revisite du 17/11']])
            ->assertSessionHas('success');
        $correction = DataCorrection::where('data_query_id', $query->id)->firstOrFail();
        $this->assertSame(DataCorrection::STATUS_PROPOSED, $correction->status);
        $this->assertNull($correction->source_value);
        $this->assertNull(Household::where('submission_uuid', 'uuid:1')->value('latitude'), 'Pas appliquée avant validation');

        $this->actingAs($this->manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHas('success');

        // Base nettoyée corrigée ; source ODK et données brutes intactes.
        $household = Household::where('submission_uuid', 'uuid:1')->firstOrFail();
        $this->assertSame(9.31, $household->latitude);
        $this->assertSame('9.31 2.49 0 5', $household->data['current_location']);
        $this->assertArrayNotHasKey('current_location', $household->source_data['data']);
        $this->assertSame(['source' => 'odk', 'uuid' => 'uuid:1'], $household->raw_data);
        $correction->refresh();
        $this->assertSame(DataCorrection::STATUS_VALIDATED, $correction->status);
        $this->assertSame($this->manager->id, $correction->decided_by);
        $this->assertNotNull($correction->applied_at);

        // Le moteur ne retrouve plus l'anomalie : query résolue, validée par le data manager.
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->status);
        $this->assertSame($this->manager->id, $query->validated_by);
        $this->assertNotNull($query->resolved_at);
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'type' => 'correction']);

        // Resynchronisation ODK inchangée : la correction est conservée.
        $this->assertSame(SubmissionProcessor::UNCHANGED, $this->submit('uuid:1', []));
        $this->assertSame(9.31, Household::where('submission_uuid', 'uuid:1')->value('latitude'));

        // Valeur modifiée dans ODK : la correction est suspendue (conflit) et la source l'emporte.
        $this->submit('uuid:1', ['current_location' => '7.5 2.1 0 5']);
        $this->assertSame(7.5, Household::where('submission_uuid', 'uuid:1')->value('latitude'));
        $this->assertSame(DataCorrection::STATUS_CONFLICT, $correction->fresh()->status);

        // Registre et fiche ménage.
        $this->actingAs($this->manager)->get(route('corrections.index'))->assertOk()->assertSee('current_location')->assertSee('En conflit avec la source');
        $this->actingAs($this->manager)->get(route('households.show', $household))->assertOk()->assertSee('Corrections (1)');
        $supervisor = User::factory()->create();
        $supervisor->assignRole(User::ROLE_SUPERVISOR);
        $this->actingAs($supervisor)->get(route('corrections.index'))->assertRedirect();
    }

    public function test_correction_that_does_not_remove_the_anomaly_sends_the_query_back(): void
    {
        AppSetting::put(AppSetting::AUTO_APPLY, false);
        $query = $this->gpsQuery();
        $this->propose($query, [['variable' => 'visit_num', 'new_value' => '2', 'reason' => 'saisie']])->assertSessionHas('success');
        $this->actingAs($this->manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHas('success');

        $this->assertSame('2', Household::where('submission_uuid', 'uuid:1')->firstOrFail()->data['visit_num']);
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_SENT, $query->status);
        $this->assertNull($query->resolved_at);
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'type' => 'system', 'to_status' => DataQuery::STATUS_SENT]);
    }

    public function test_correction_that_removes_the_error_is_applied_at_once_and_can_be_refused(): void
    {
        $query = $this->gpsQuery();
        $this->propose($query, [['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite']])
            ->assertSessionHas('success', "Correction prise en compte : l'erreur est levée.");

        // Pris en compte sans attendre : base nettoyée corrigée, query résolue, correction à revoir.
        $this->assertSame(9.31, Household::where('submission_uuid', 'uuid:1')->value('latitude'));
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
        $correction = DataCorrection::firstOrFail();
        $this->assertSame(DataCorrection::STATUS_VALIDATED, $correction->status);
        $this->assertTrue($correction->auto_applied);
        $this->assertNull($correction->reviewed_at);
        $this->actingAs($this->manager)->get(route('corrections.index'))->assertOk()->assertSee('à revoir');
        $this->actingAs($this->account)->post(route('corrections.refuse', $correction), ['reason' => 'x'])->assertRedirect(route('team.queries'));

        // Refus par le data manager : valeur ODK rétablie, query rouverte et rendue à l'assigné.
        $this->actingAs($this->manager)->post(route('corrections.refuse', $correction), ['reason' => 'Photo illisible, revisite nécessaire'])->assertSessionHas('success');
        $this->assertNull(Household::where('submission_uuid', 'uuid:1')->value('latitude'));
        $this->assertSame(DataCorrection::STATUS_REJECTED, $correction->fresh()->status);
        $this->assertSame(DataQuery::STATUS_SENT, $query->fresh()->status);
    }

    public function test_correction_that_does_not_remove_the_error_waits_for_the_data_manager(): void
    {
        $query = $this->gpsQuery();
        $this->propose($query, [['variable' => 'visit_num', 'new_value' => '2', 'reason' => 'saisie']])->assertSessionHas('success');

        // Rien n'est appliqué : la correction attend le data manager.
        $this->assertSame('1', Household::where('submission_uuid', 'uuid:1')->firstOrFail()->data['visit_num']);
        $this->assertSame(DataQuery::STATUS_CORRECTED, $query->fresh()->status);
        $this->assertSame(DataCorrection::STATUS_PROPOSED, DataCorrection::firstOrFail()->status);
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'type' => 'system']);

        // Acceptation a posteriori d'une correction immédiate : marquée revue.
        $other = DataQuery::where('household_code', 'TCH-SEB-01-002')->where('rule_code', 'GPS_MISSING')->firstOrFail();
        $this->actingAs($this->manager)->post(route('queries.status', $other), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'current_location', 'new_value' => '9.32 2.49 0 5', 'reason' => 'revisite'],
        ]]);
        $applied = DataCorrection::where('data_query_id', $other->id)->firstOrFail();
        $this->actingAs($this->manager)->post(route('corrections.accept-all', ['a_revoir' => 1]))->assertSessionHas('success', '1 correction(s) acceptée(s).');
        $this->assertNotNull($applied->fresh()->reviewed_at);
    }

    public function test_invalid_corrections_are_refused(): void
    {
        $query = $this->gpsQuery();
        foreach ([
            [['variable' => 'current_location', 'new_value' => 'abc', 'reason' => 'saisie']], // type geopoint
            [['variable' => 'visit_num', 'new_value' => '9', 'reason' => 'saisie']], // hors liste de choix
            [['variable' => 'visit_num', 'new_value' => '1', 'reason' => 'saisie']], // identique
            [['variable' => 'visit_num', 'new_value' => '2', 'reason' => '']], // motif manquant
            [['variable' => 'visit_num', 'new_value' => '2', 'reason' => 'autre']], // « autre » sans précision
            [['variable' => 'Penta1date', 'new_value' => '2025-01-10', 'reason' => 'carnet']], // membre (enfant) non précisé
            [['variable' => 'inconnue', 'new_value' => '1', 'reason' => 'saisie']],
        ] as $corrections) {
            $this->propose($query, $corrections)->assertSessionHasErrors('status');
        }
        $this->assertSame(0, DataCorrection::count());
        $this->assertSame(DataQuery::STATUS_SENT, $query->fresh()->status);
    }

    public function test_paths_address_caregivers_and_children_like_the_engine(): void
    {
        $child = fn ($dob) => ['key' => null, 'data' => ['child_dob' => $dob], 'repeats' => []];
        $submission = new NormalizedSubmission('uuid:x', ['household' => 'X'], [
            'cg' => [
                ['key' => null, 'data' => ['firstname' => 'AA'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [$child('2024-01-01')]]],
                ['key' => null, 'data' => ['firstname' => 'BB'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [$child('2024-02-02'), $child('2024-03-03')]]],
            ],
        ], []);

        $this->assertSame('X', SubmissionPaths::get($submission, 'household', null));
        $this->assertSame('BB', SubmissionPaths::get($submission, 'firstname', 'cg[2]'));
        $this->assertSame('2024-03-03', SubmissionPaths::get($submission, 'child_dob', 'cg[2]/smc_cov_hh_cg_child_2023[2]'));

        SubmissionPaths::set($submission, 'child_dob', 'cg[2]/smc_cov_hh_cg_child_2023[2]', '2024-04-04');
        $this->assertSame('2024-04-04', $submission->repeats['cg'][1]['repeats']['smc_cov_hh_cg_child_2023'][1]['data']['child_dob']);
        $this->assertSame('2024-02-02', $submission->repeats['cg'][1]['repeats']['smc_cov_hh_cg_child_2023'][0]['data']['child_dob']);
        $this->assertFalse(SubmissionPaths::exists($submission, 'cg[3]'));
    }
}
