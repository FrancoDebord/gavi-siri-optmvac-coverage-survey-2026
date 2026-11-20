<?php

namespace Tests\Feature;

use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validation en deux temps : réponse du binôme → vérification du superviseur de la zone → validation finale de
 * l'administrateur (à l'unité ou en bloc), où les corrections sont appliquées.
 */
class TwoStepValidationTest extends TestCase
{
    use RefreshDatabase;

    private DataQuery $query;

    private User $team;

    private User $supervisor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        // Penta 2 avant Penta 1, ménage de Tchaourou collecté par l'agent 46 (binôme 46-47).
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:A', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0', 'num_slept_last_night' => '3', 'hh_caregivers_num' => '1',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
            'child_dob' => '2025-01-10', 'receive_vaccine' => '1', 'see_card' => '1',
            'Penta1' => '1', 'Penta1date' => '2025-03-25', 'Penta2' => '1', 'Penta2date' => '2025-02-20',
        ], 'repeats' => []]]]]]], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');
        $this->query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();

        $tchaourou = HealthZone::where('code', '1')->firstOrFail();
        $this->team = $this->user(User::ROLE_TEAM, ['team_id' => $this->query->collector->team_id]);
        $this->supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $tchaourou->id]);
        $this->admin = $this->user(User::ROLE_ADMIN);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function move(User $user, string $status, array $extra = [])
    {
        return $this->actingAs($user)->post(route('queries.status', $this->query), ['status' => $status] + $extra);
    }

    private function respond(): void
    {
        $this->move($this->team, DataQuery::STATUS_CORRECTED, ['corrections' => [
            ['variable' => 'Penta1date', 'instance_path' => $this->query->instance_path, 'new_value' => '2025-01-25', 'reason' => 'carnet'],
        ]])->assertSessionHas('success');
        $this->assertSame(DataQuery::STATUS_CORRECTED, $this->query->fresh()->status);
    }

    public function test_team_response_goes_to_the_zone_supervisor_then_to_the_administrator(): void
    {
        $this->respond();

        // Le binôme ne peut pas valider sa propre réponse ; un superviseur d'une autre zone non plus.
        $this->move($this->team, DataQuery::STATUS_SUPERVISED)->assertSessionHasErrors('status');
        $other = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '2')->value('id')]);
        $this->move($other, DataQuery::STATUS_SUPERVISED)->assertForbidden();

        // Superviseur de la zone : la réponse est dans « À vérifier », il la valide ; rien n'est encore appliqué.
        $this->actingAs($this->supervisor)->get(route('queries.show', $this->query))->assertOk()
            ->assertSee('Valider (vérification du superviseur)')->assertDontSee('Validation finale et fermeture');
        $this->move($this->supervisor, DataQuery::STATUS_SUPERVISED, ['comment' => 'Vérifié sur la photo du carnet'])
            ->assertSessionHas('success', 'Réponse vérifiée : elle part à l\'administrateur pour la validation finale.');
        $query = $this->query->fresh();
        $this->assertSame(DataQuery::STATUS_SUPERVISED, $query->status);
        $this->assertSame($this->supervisor->id, $query->supervised_by);
        $this->assertSame(DataCorrection::STATUS_PROPOSED, DataCorrection::where('data_query_id', $query->id)->value('status'), 'correction pas encore appliquée');

        // Le superviseur ne fait pas la validation finale.
        $this->move($this->supervisor, DataQuery::STATUS_VALIDATED)->assertSessionHasErrors('status');

        // Administrateur : validation finale en bloc ; la correction est appliquée et la query fermée.
        $this->actingAs($this->admin)->postJson(route('queries.bulk'), ['ids' => [$query->id], 'status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(DataCorrection::STATUS_VALIDATED, DataCorrection::where('data_query_id', $query->id)->value('status'));
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
    }

    public function test_rejections_send_the_query_back_to_the_team(): void
    {
        $this->respond();
        // Rejet par le superviseur (motif obligatoire) : retour au binôme.
        $this->move($this->supervisor, DataQuery::STATUS_OPEN)->assertSessionHasErrors('status');
        $this->move($this->supervisor, DataQuery::STATUS_OPEN, ['comment' => 'Date illisible : revérifier le carnet'])->assertSessionHasNoErrors();
        $this->assertTrue(in_array($this->query->fresh()->status, DataQuery::UNRESOLVED, true));
        $this->assertSame(DataCorrection::STATUS_REJECTED, DataCorrection::where('data_query_id', $this->query->id)->value('status'));

        // Nouvelle réponse vérifiée, puis rejet par l'administrateur à la validation finale : retour au binôme.
        $this->respond();
        $this->move($this->supervisor, DataQuery::STATUS_SUPERVISED)->assertSessionHasNoErrors();
        $this->move($this->admin, DataQuery::STATUS_OPEN, ['comment' => 'Incohérent avec la date de naissance'])->assertSessionHasNoErrors();
        $query = $this->query->fresh();
        $this->assertTrue(in_array($query->status, DataQuery::UNRESOLVED, true));
        $this->assertNull($query->supervised_by);
        $this->actingAs($this->team)->get(route('team.queries'))->assertSee($query->code);
    }

    public function test_menu_counters_lead_to_each_step(): void
    {
        $this->respond();
        $this->actingAs($this->supervisor)->get(route('queries.index', ['resolution' => DataQuery::RESOLUTION_PENDING]))->assertOk()
            ->assertSee('À vérifier (superviseur)');
        $this->move($this->supervisor, DataQuery::STATUS_SUPERVISED);
        $this->actingAs($this->admin)->get(route('queries.index'))->assertSee('Validation finale')
            ->assertSee('À valider (validation finale)');
    }
}
