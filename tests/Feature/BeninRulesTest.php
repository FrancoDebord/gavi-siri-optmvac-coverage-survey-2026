<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\BeninRuleSet;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Règles du document qualité Bénin (cov26c4v2) ajoutées aux règles existantes. */
class BeninRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function rules(string $code): int
    {
        return DataQuery::withoutGlobalScope('kind')->where('rule_code', $code)->count();
    }

    public function test_rules_are_created_once_with_the_noisy_ones_inactive(): void
    {
        $this->assertSame(count(BeninRuleSet::rules()), QualityRule::whereIn('code', array_keys(BeninRuleSet::rules()))->count());
        $this->assertFalse(QualityRule::where('code', 'MAJ_47')->value('is_active'));
        $this->assertTrue(QualityRule::where('code', 'CRI_10')->value('is_active'));
        $this->assertSame(QualityRule::KIND_OBSERVATION, QualityRule::where('code', 'OBS_28')->value('kind'));
        $this->assertSame('repeat', QualityRule::where('code', 'MAJ_11')->value('scope'));
    }

    public function test_household_with_errors_raises_the_expected_rules(): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:B', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0', 'num_slept_last_night' => '2', 'hh_caregivers_num' => '1',
            'c_low' => '12', 'c_high' => '35',
        ], [
            'smc_cov_roster_2023' => [
                ['key' => null, 'data' => ['person_caregiver' => '1', 'age_group' => '6'], 'repeats' => []], // caregiver de 10–14 ans (MAJ-11)
                ['key' => null, 'data' => ['person_caregiver' => '0', 'age_group' => '0'], 'repeats' => []],
            ],
            'SES' => [['key' => null, 'data' => ['mud_walls' => '0', 'cement_walls' => '0', 'mud_and_cement_walls' => '0'], 'repeats' => []]], // OBS-28
            'cg' => [['key' => null, 'data' => [
                'participation_agreed' => '1', 'caregiver_num_children' => '1', 'age_years' => '30',
                'cg_rel_hh' => '2', 'caregiver_sex' => '0', // « Mère » de sexe masculin (MAJ-14)
            ], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
                'child_dob' => '2026-12-01', // dans le futur (CRI-10)
                'age_months_est' => '20', 'child_sleep_last_night' => '1', 'receive_vaccine' => '1',
                'meas1' => '0', 'meas2' => '1', // RR 2 sans RR 1 (MAJ-26)
            ], 'repeats' => []]]]]],
        ], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        foreach (['CRI_10', 'MAJ_11', 'MAJ_14', 'MAJ_26', 'OBS_28'] as $code) {
            $this->assertSame(1, $this->rules($code), "règle {$code}");
        }
        foreach (['CRI_07', 'MAJ_12', 'MAJ_18', 'CRI_13'] as $code) {
            $this->assertSame(0, $this->rules($code), "règle {$code} (données conformes)");
        }
        // Observation de supervision : hors des queries à corriger.
        $this->assertSame(0, DataQuery::where('rule_code', 'OBS_28')->count());
        $this->assertSame('Caregiver n°1 : Le sexe du caregiver ne correspond pas au lien déclaré.', DataQuery::where('rule_code', 'MAJ_14')->value('message'));
        $this->assertSame('Corriger le sexe ou le lien de parenté.', DataQuery::where('rule_code', 'MAJ_14')->value('suggestion'));
    }
}
