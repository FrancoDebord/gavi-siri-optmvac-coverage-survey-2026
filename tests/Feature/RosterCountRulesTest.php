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

/** Rosters (membres, caregivers, enfants) conformes au nombre déclaré auparavant. */
class RosterCountRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function submit(string $code, array $data, int $members, array $caregivers): void
    {
        $child = fn () => ['key' => null, 'data' => ['child_dob' => '2025-01-10', 'receive_vaccine' => '0'], 'repeats' => []];
        (new SubmissionProcessor)->process(new NormalizedSubmission("uuid:{$code}", $data + [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => $code, 'household_survey_status' => '0', 'num_bednets' => '0',
        ], [
            'smc_cov_roster_2023' => array_map(fn ($i) => ['key' => null, 'data' => ['bednet_number' => '0'], 'repeats' => []], range(1, $members) ?: []),
            'cg' => array_map(fn ($cg) => ['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => (string) $cg[0]],
                'repeats' => ['smc_cov_hh_cg_child_2023' => array_map(fn () => $child(), $cg[1] > 0 ? range(1, $cg[1]) : [])]], $caregivers),
        ], []), ['source_type' => 'demo']);
    }

    public function test_rosters_must_match_the_declared_numbers(): void
    {
        // Conforme : 3 personnes, 1 caregiver déclaré avec 1 enfant.
        $this->submit('TCH-SEB-01-001', ['num_slept_last_night' => '3', 'hh_caregivers_num' => '1'], 3, [[1, 1]]);
        // Non conforme : 5 personnes déclarées / 4 saisies ; 1 caregiver déclaré / 2 saisis ; caregiver 2 : 2 enfants déclarés / 1 saisi.
        $this->submit('TCH-SEB-01-002', ['num_slept_last_night' => '5', 'hh_caregivers_num' => '1'], 4, [[1, 1], [2, 1]]);
        app(QualityEngine::class)->run('test');

        $this->assertSame(0, DataQuery::where('household_code', 'TCH-SEB-01-001')->whereIn('rule_code', ['ROSTER_MEMBERS', 'ROSTER_CAREGIVERS', 'ROSTER_CHILDREN'])->count());

        $members = DataQuery::where('rule_code', 'ROSTER_MEMBERS')->sole();
        $this->assertSame('num_slept_last_night', $members->variable);
        $this->assertSame('5', $members->observed_value);
        $this->assertStringContainsString('5 personne(s) déclaré(s) mais 4 saisi(s) dans le roster des membres', $members->message);

        $caregivers = DataQuery::where('rule_code', 'ROSTER_CAREGIVERS')->sole();
        $this->assertSame('hh_caregivers_num', $caregivers->variable);
        $this->assertStringContainsString('1 caregiver(s) déclaré(s) mais 2 saisi(s)', $caregivers->message);

        $children = DataQuery::where('rule_code', 'ROSTER_CHILDREN')->sole();
        $this->assertSame('caregiver_num_children', $children->variable);
        $this->assertSame('cg[2]', $children->instance_path);
        $this->assertStringContainsString('Caregiver n°2 : 2 enfant(s) de moins de 5 ans déclaré(s) mais 1 saisi(s)', $children->message);
        $this->assertNotEmpty($children->suggestion);
    }

    public function test_quality_check_can_run_only_the_roster_rules(): void
    {
        $this->submit('TCH-SEB-01-002', ['num_slept_last_night' => '5', 'hh_caregivers_num' => '1'], 4, [[1, 1]]);
        app(QualityEngine::class)->run('test');
        $others = DataQuery::whereNotIn('rule_code', ['ROSTER_MEMBERS', 'ROSTER_CAREGIVERS', 'ROSTER_CHILDREN'])->whereIn('status', DataQuery::UNRESOLVED)->count();
        DataQuery::where('rule_code', 'ROSTER_MEMBERS')->delete();

        $this->artisan('gavi:run-quality-checks', ['--regles' => 'roster_members,ROSTER_CAREGIVERS'])->assertSuccessful();
        $this->assertSame(1, DataQuery::where('rule_code', 'ROSTER_MEMBERS')->count(), 'règle demandée exécutée');
        $this->assertSame($others, DataQuery::whereNotIn('rule_code', ['ROSTER_MEMBERS', 'ROSTER_CAREGIVERS', 'ROSTER_CHILDREN'])->whereIn('status', DataQuery::UNRESOLVED)->count(), 'autres règles ni fermées ni rouvertes');
        $this->artisan('gavi:run-quality-checks', ['--regles' => 'INCONNUE'])->assertFailed();

        // Code de la règle violée affiché sur la fiche de la query.
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('queries.show', DataQuery::where('rule_code', 'ROSTER_MEMBERS')->sole()))->assertOk()
            ->assertSee('<i class="bi bi-shield-exclamation me-1"></i>ROSTER_MEMBERS', false);
    }
}
