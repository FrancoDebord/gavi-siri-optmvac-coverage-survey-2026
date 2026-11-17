<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\SupervisionObservation;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Services\Quality\RuleCatalog;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gestion des queries (GCP) : observations, affectation, réponse, validation par le data manager, double validation signée. */
class QueryManagementTest extends TestCase
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

        $processor = new SubmissionProcessor;
        foreach ([
            ['uuid:1', 'TCH-SEB-01-001', '46', ['1', '1', '5', '66']],
            ['uuid:2', 'TCH-SEB-01-002', '47', ['1', '1', '5', '66']],
            ['uuid:3', 'DAG-ASS-01-001', '33', ['2', '2', '13', '57']],
        ] as [$uuid, $code, $collector, $zone]) {
            $processor->process(new NormalizedSubmission($uuid, [
                'today' => now()->toDateString(), 'l1' => $zone[0], 'l2' => $zone[1], 'l3' => $zone[2], 'village' => $zone[3],
                'fieldworker' => $collector, 'household' => $code, 'household_survey_status' => '0',
            ], [], []), ['source_type' => 'demo']);
        }
        app(QualityEngine::class)->run('test');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function teamQuery(): DataQuery
    {
        return DataQuery::where('household_code', 'TCH-SEB-01-001')->where('rule_code', 'GPS_MISSING')->firstOrFail();
    }

    public function test_paradata_rules_are_supervision_observations_not_queries(): void
    {
        foreach (RuleCatalog::OBSERVATIONS as $code) {
            $this->assertSame(QualityRule::KIND_OBSERVATION, QualityRule::where('code', $code)->value('kind'), $code);
        }
        $this->assertSame(QualityRule::KIND_QUERY, QualityRule::where('code', 'VAC_DOSE_ORDER')->value('kind'));

        // Une règle passée en observation : ses anomalies quittent les queries au prochain passage du moteur.
        $count = DataQuery::where('rule_code', 'GPS_MISSING')->count();
        $this->assertGreaterThan(0, $count);
        QualityRule::where('code', 'GPS_MISSING')->update(['kind' => QualityRule::KIND_OBSERVATION]);
        app(QualityEngine::class)->run('test');
        $this->assertSame(0, DataQuery::where('rule_code', 'GPS_MISSING')->count());
        $observation = SupervisionObservation::where('rule_code', 'GPS_MISSING')->firstOrFail();
        $this->assertSame($count, SupervisionObservation::where('rule_code', 'GPS_MISSING')->count());

        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $this->actingAs($manager)->get(route('queries.show', $observation->id))->assertNotFound();
        $data = $this->actingAs($manager)->getJson(route('queries.datatable', ['draw' => 1, 'length' => 100]))->json('data');
        $this->assertNotContains('GPS_MISSING', array_column($data, 'rule_code'));
        $this->actingAs($manager)->get(route('observations.index', ['rule_code' => 'GPS_MISSING']))->assertOk()
            ->assertSee('Observations de supervision')->assertSee('TCH-SEB-01-001');
    }

    public function test_assigned_team_account_responds_and_data_manager_validates(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $query = $this->teamQuery();

        // Affectation : Ouverte → Assignée, échéance par défaut (3 jours), tracée.
        $this->actingAs($manager)->post(route('queries.assign', $query), ['assigned_to' => $account->id])->assertSessionHas('success');
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_SENT, $query->status);
        $this->assertSame($account->id, $query->assigned_to);
        $this->assertSame(today()->addDays(3)->toDateString(), $query->due_on->toDateString());
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'type' => 'assignment', 'user_id' => $manager->id]);

        // Le binôme voit sa query et y répond ; il ne peut pas la valider.
        $this->actingAs($account)->get(route('queries.show', $query))->assertOk()->assertSee('query-status-form', false)->assertSee('corrections[0][new_value]', false);
        // Correction sans valeur : refusée.
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'comment' => 'x'])->assertSessionHasErrors('status');
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite', 'source_reference' => 'Revisite du 17/11'],
        ]])->assertSessionHas('success');
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_CORRECTED, $query->status);
        $this->assertSame($account->id, $query->responded_by);
        $this->assertNull($query->resolved_at);
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHasErrors('status');

        // Rejet par le data manager (motif obligatoire) : retour à l'assigné.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_OPEN])->assertSessionHasErrors('status');
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_OPEN, 'comment' => 'Préciser la source'])->assertSessionHas('success');
        $this->assertSame(DataQuery::STATUS_SENT, $query->fresh()->status);
        // La correction proposée est rejetée, mais reste au registre.
        $this->assertDatabaseHas('data_corrections', ['data_query_id' => $query->id, 'status' => 'rejetee', 'decided_by' => $manager->id]);

        // Nouvelle réponse puis validation (sévérité majeure : pas de signature).
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_JUSTIFIED, 'comment' => 'GPS en panne, vérifié par le superviseur']);
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHas('success');
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->status);
        $this->assertSame($manager->id, $query->validated_by);
        $this->assertNotNull($query->resolved_at);
        $this->assertSame(DataQuery::RESOLUTION_RESOLVED, $query->resolution());
    }

    public function test_assignee_must_have_access_to_the_query(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $otherTeam = Team::where('code', '!=', $this->team->code)->firstOrFail();
        $outsider = $this->user(User::ROLE_TEAM, ['team_id' => $otherTeam->id]);
        $query = $this->teamQuery();

        $this->actingAs($manager)->post(route('queries.assign', $query), ['assigned_to' => $outsider->id])->assertSessionHasErrors('assigned_to');
        $this->assertNull($query->fresh()->assigned_to);
    }

    public function test_critical_queries_need_an_independent_signed_validation(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $second = $this->user(User::ROLE_DATA_MANAGER);
        $query = $this->teamQuery();
        $query->update(['severity' => 'critique']);

        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_JUSTIFIED, 'comment' => 'Vérifié d\'après la photo']);
        // L'auteur de la réponse ne peut pas la valider, même avec son mot de passe.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])
            ->assertSessionHasErrors('status');
        // Un second data manager : signature obligatoire et vérifiée.
        $this->actingAs($second)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHasErrors('status');
        $this->actingAs($second)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'mauvais'])->assertSessionHasErrors('status');
        $this->actingAs($second)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])->assertSessionHas('success');

        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'to_status' => DataQuery::STATUS_VALIDATED, 'user_id' => $second->id, 'signed' => true]);

        // Paramétrable : sans double validation pour les critiques, la signature n'est plus demandée.
        AppSetting::put(AppSetting::DOUBLE_VALIDATION, []);
        $this->assertFalse(AppSetting::requiresDoubleValidation('critique'));
    }

    public function test_bulk_validation_signs_once_and_skips_own_responses(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $queries = DataQuery::where('household_code', 'like', 'TCH-%')->get();
        $this->assertGreaterThanOrEqual(2, $queries->count());
        $queries->each->update(['severity' => 'critique']);
        $ids = $queries->pluck('id')->all();

        $this->actingAs($supervisor)->postJson(route('queries.bulk'), ['ids' => $ids, 'status' => DataQuery::STATUS_JUSTIFIED, 'comment' => 'Vérifié'])
            ->assertOk()->assertJsonPath('updated', count($ids));
        // Le superviseur ne valide pas.
        $this->actingAs($supervisor)->postJson(route('queries.bulk'), ['ids' => $ids, 'status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])
            ->assertOk()->assertJsonPath('updated', 0);
        // Validation en bloc par le data manager, une seule signature.
        $this->actingAs($manager)->postJson(route('queries.bulk'), ['ids' => $ids, 'status' => DataQuery::STATUS_VALIDATED])
            ->assertOk()->assertJsonPath('updated', 0);
        $this->actingAs($manager)->postJson(route('queries.bulk'), ['ids' => $ids, 'status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])
            ->assertOk()->assertJsonPath('updated', count($ids));
        $this->assertSame(count($ids), DataQuery::whereIn('id', $ids)->where('status', DataQuery::STATUS_VALIDATED)->count());
    }

    public function test_settings_are_admin_only_and_drive_auto_assignment(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $this->actingAs($this->user(User::ROLE_DATA_MANAGER))->get(route('settings.edit'))->assertRedirect();

        $this->actingAs($admin)->get(route('settings.edit'))->assertOk()->assertSee('Double validation');
        $this->actingAs($admin)->put(route('settings.update'), ['auto_assign' => 'team', 'due_days' => 5, 'double_validation' => ['critique', 'majeure']])
            ->assertSessionHas('success');
        $this->assertTrue(AppSetting::requiresDoubleValidation('majeure'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        // Nouvelles queries : affectées au compte du binôme de l'agent, échéance 5 jours.
        DataQuery::query()->delete();
        app(QualityEngine::class)->run('test');
        $query = $this->teamQuery();
        $this->assertSame($account->id, $query->assigned_to);
        $this->assertSame(DataQuery::STATUS_SENT, $query->status);
        $this->assertSame(today()->addDays(5)->toDateString(), $query->due_on->toDateString());
        // Agent sans compte binôme : la query reste non assignée.
        $this->assertNull(DataQuery::where('household_code', 'DAG-ASS-01-001')->firstOrFail()->assigned_to);

        // Filtre « mes queries ».
        $data = $this->actingAs($admin)->getJson(route('queries.datatable', ['draw' => 1, 'length' => 100, 'assigned' => $account->id]))->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame([$account->name], array_values(array_unique(array_column($data, 'assignee'))));
    }
}
