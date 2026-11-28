<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\SupervisionObservation;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Observations de supervision envoyées dans les queries par l'administrateur, puis résolues par les binômes. */
class ObservationEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->team = Team::where('code', '46-47')->firstOrFail();

        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:1', [
            'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66',
            'fieldworker' => '46', 'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0',
        ], [], []), ['source_type' => 'demo']);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /** Observation de paradonnées (durée d'entretien) sur le ménage du binôme. */
    private function paradataObservation(): SupervisionObservation
    {
        $household = Household::where('household_code', 'TCH-SEB-01-001')->firstOrFail();
        $rule = QualityRule::where('code', 'PARA_DURATION')->firstOrFail();

        return SupervisionObservation::create([
            'fingerprint' => 'test-para-duration', 'quality_rule_id' => $rule->id, 'rule_code' => 'PARA_DURATION', 'severity' => 'mineure',
            'household_id' => $household->id, 'household_code' => $household->household_code, 'submission_uuid' => $household->submission_uuid,
            'health_zone_id' => $household->health_zone_id, 'commune_id' => $household->commune_id, 'village_id' => $household->village_id,
            'collector_id' => $household->collector_id, 'collection_date' => now()->toDateString(), 'variable' => 'end',
            'observed_value' => '4 min', 'message' => 'Interview de 4 min (minimum 10).', 'status' => DataQuery::STATUS_OPEN,
            'detected_at' => now(), 'last_detected_at' => now(),
        ]);
    }

    public function test_admin_sends_observations_to_queries_assigned_to_the_team(): void
    {
        AppSetting::put(AppSetting::AUTO_ASSIGN, 'team');
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $admin = $this->user(User::ROLE_ADMIN);
        $observation = $this->paradataObservation();

        $this->actingAs($admin)->get(route('observations.index'))->assertOk()->assertSee('Envoyer la sélection');
        $this->actingAs($admin)->post(route('observations.escalate'), ['ids' => [$observation->id], 'note' => 'Expliquer la durée'])
            ->assertRedirect()->assertSessionHas('success');

        $query = DataQuery::findOrFail($observation->id);
        $this->assertTrue($query->isEscalatedObservation());
        $this->assertTrue($query->isParadataObservation());
        $this->assertSame($admin->id, $query->escalated_by);
        $this->assertSame($account->id, $query->assigned_to);
        $this->assertSame(DataQuery::STATUS_SENT, $query->status);
        $this->assertSame(0, SupervisionObservation::whereKey($observation->id)->count());
        $this->assertTrue($query->events()->where('comment', 'like', '%Consigne : Expliquer la durée%')->exists());

        // Liste des queries : filtre « observations envoyées », espace du binôme.
        $data = $this->actingAs($admin)->getJson(route('queries.datatable', ['draw' => 1, 'origine' => 'observation']))->json('data');
        $this->assertSame([$query->id], array_column($data, 'id'));
        $this->assertTrue($data[0]['escalated']);
        $this->actingAs($account)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Observation de supervision')->assertSee('Que s\'est-il passé ?', false)->assertDontSee('Correction proposée – en attente');
    }

    public function test_only_central_roles_can_send_observations(): void
    {
        $observation = $this->paradataObservation();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $this->actingAs($supervisor)->post(route('observations.escalate'), ['ids' => [$observation->id]])->assertRedirect();
        $this->assertSame(1, SupervisionObservation::whereKey($observation->id)->count());
    }

    public function test_team_explains_a_paradata_observation_instead_of_correcting_it(): void
    {
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $observation = $this->paradataObservation();
        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('observations.escalate'), ['ids' => [$observation->id]]);
        $query = DataQuery::findOrFail($observation->id);

        // Pas de correction possible : paradonnée.
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [['variable' => 'end', 'new_value' => 'x']]])
            ->assertSessionHasErrors('status');
        // « Autre explication » sans précision : refusée.
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_JUSTIFIED, 'explanation' => SupervisionObservation::OTHER])
            ->assertSessionHasErrors('status');
        $this->assertSame(DataQuery::STATUS_OPEN, $query->fresh()->status);

        $this->actingAs($account)->post(route('queries.status', $query), [
            'status' => DataQuery::STATUS_JUSTIFIED, 'explanation' => 'Ménage sans enfant éligible : questionnaire court', 'comment' => 'Vérifié avec l\'agent.',
        ])->assertSessionHasNoErrors();
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $query->status);
        $this->assertSame($account->id, $query->responded_by);
        $this->assertSame('Explication : Ménage sans enfant éligible : questionnaire court. Vérifié avec l\'agent.', $query->events()->where('type', 'status')->value('comment'));
    }

    public function test_engine_keeps_sent_observations_among_queries(): void
    {
        app(QualityEngine::class)->run('test');
        QualityRule::where('code', 'GPS_MISSING')->update(['kind' => QualityRule::KIND_OBSERVATION]);
        app(QualityEngine::class)->run('test');
        $observation = SupervisionObservation::where('rule_code', 'GPS_MISSING')->firstOrFail();

        $this->actingAs($this->user(User::ROLE_DATA_MANAGER))->post(route('observations.escalate', ['all' => 1, 'rule_code' => 'GPS_MISSING']))
            ->assertSessionHas('success');
        app(QualityEngine::class)->run('test');

        $query = DataQuery::findOrFail($observation->id);
        $this->assertTrue($query->isEscalatedObservation());
        $this->assertFalse($query->isParadataObservation());
        $this->assertSame(0, SupervisionObservation::where('rule_code', 'GPS_MISSING')->count());
    }
}
