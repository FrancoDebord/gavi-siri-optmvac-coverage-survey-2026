<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Date de naissance fausse : deux queries (Penta 1 et Penta 2 avant la naissance). La date corrigée dans l'une,
 * le rechargement résout l'autre, qui suit la correction : fermée à sa validation, renvoyée à son refus.
 */
class ReloadProposedCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private DataQuery $origin;

    private DataQuery $dependent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:R', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '1',
            'household' => 'HH-R', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
            'num_slept_last_night' => '5', 'hh_caregivers_num' => '1',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
            'child_dob' => '2025-06-10', 'receive_vaccine' => '1', 'see_card' => '1',
            'Penta1' => '1', 'Penta1date' => '2025-03-25', 'Penta2' => '1', 'Penta2date' => '2025-04-25',
        ], 'repeats' => []]]]]]], []));
        app(QualityEngine::class)->run('test');

        $this->agent = User::factory()->create();
        $this->agent->assignRole(User::ROLE_ADMIN);
        [$this->origin, $this->dependent] = DataQuery::where('rule_code', 'VAC_BEFORE_DOB')->orderBy('id')->get()->all();

        // La date de naissance est corrigée dans la première query (en attente de validation).
        $this->actingAs($this->agent)->post(route('queries.status', $this->origin), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'child_dob', 'instance_path' => $this->origin->instance_path, 'new_value' => '2025-01-10', 'reason' => 'carnet'],
        ]])->assertSessionHasNoErrors();
    }

    public function test_reload_resolves_the_dependent_query_with_the_proposed_correction(): void
    {
        $this->actingAs($this->agent)->get(route('queries.show', $this->dependent))->assertOk()->assertSee('Recharger les données du ménage');

        $this->actingAs($this->agent)->post(route('queries.reload', $this->dependent))->assertSessionHas('success');

        $dependent = $this->dependent->fresh();
        $this->assertSame(DataQuery::STATUS_CORRECTED, $dependent->status);
        $this->assertSame($this->origin->id, $dependent->resolved_by_query_id);
        // Rien n'est appliqué tant que la correction n'est pas validée.
        $this->assertSame('2025-06-10', $this->origin->household->fresh()->children->first()->data['child_dob'] ?? null);
        $this->actingAs($this->agent)->get(route('queries.show', $dependent))->assertSee('Résolue par')->assertSee($this->origin->code);

        // Validation de la correction d'origine : la query dépendante est fermée.
        $validator = User::factory()->create();
        $validator->assignRole(User::ROLE_ADMIN);
        $this->actingAs($validator)->post(route('queries.status', $this->origin), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame(DataQuery::STATUS_VALIDATED, $this->origin->fresh()->status);
        $this->assertSame(DataQuery::STATUS_VALIDATED, $dependent->fresh()->status);
    }

    public function test_refused_correction_sends_the_dependent_query_back(): void
    {
        $this->actingAs($this->agent)->post(route('queries.reload', $this->dependent))->assertSessionHas('success');

        $validator = User::factory()->create();
        $validator->assignRole(User::ROLE_ADMIN);
        $this->actingAs($validator)->post(route('queries.status', $this->origin), ['status' => DataQuery::STATUS_OPEN, 'comment' => 'Date illisible'])->assertSessionHasNoErrors();

        $dependent = $this->dependent->fresh();
        $this->assertContains($dependent->status, [DataQuery::STATUS_OPEN, DataQuery::STATUS_SENT]);
        $this->assertNull($dependent->resolved_by_query_id);
    }
}
