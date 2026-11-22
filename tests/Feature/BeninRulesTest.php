<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\User;
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

    public function test_children_declared_versus_listed_shows_the_values_read_and_the_odk_account(): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:C', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-003', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], [
            'smc_cov_roster_2023' => [
                ['key' => null, 'data' => ['age_group' => '8'], 'repeats' => []],
                ['key' => null, 'data' => ['age_group' => '0'], 'repeats' => []],
            ],
            'cg' => [
                ['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '2', 'age_years' => '30'], 'repeats' => []],
                ['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '1', 'age_years' => '25'], 'repeats' => []],
            ],
        ], [], submitter: 'binome-tch-01'), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        $query = DataQuery::where('rule_code', 'MAJ_09')->firstOrFail();
        $this->assertSame('3 enfant(s) déclaré(s) par les caregivers (caregiver n°1 : 2, caregiver n°2 : 1), mais liste des membres : 1 membre(s) de moins de 5 ans (n°2) sur 2.', $query->message);
        $this->assertSame('Déclarés : 3 (caregiver n°1 : 2, caregiver n°2 : 1) · Liste des membres : 1 membre(s) de moins de 5 ans (n°2)', $query->observed_value);

        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Valeurs lues (ODK)')->assertSee('Déclarés : 3 (caregiver n°1 : 2, caregiver n°2 : 1)')
            ->assertSee('Compte ODK')->assertSee('binome-tch-01')
            ->assertDontSee('name="corrections[0][variable]" value="caregiver_num_children"', false);
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

    /** Ménage d'un caregiver avec les enfants donnés (Tchaourou, l1 = 1). */
    private function household(string $code, array $children): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:'.$code, [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => $code, 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => (string) count($children), 'age_years' => '30'],
            'repeats' => ['smc_cov_hh_cg_child_2023' => array_map(fn ($data) => ['key' => null, 'data' => $data + ['child_dob' => '2025-01-10'], 'repeats' => []], $children)]]],
        ], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');
    }

    private function manager(): User
    {
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);

        return $manager;
    }

    public function test_smc_cycles_out_of_order_list_every_date_and_offer_the_dates_in_cause(): void
    {
        $this->household('TCH-SEB-01-010', [[
            'treated_1' => '1', 'date_1' => '2026-07-10', 'treated_2' => '1', 'date_2' => '2026-08-10',
            'treated_3' => '1', 'date_3' => '2026-08-01', 'treated_4' => '1', 'treated_5' => '1', 'date_5' => '2026-10-05',
        ]]);

        $query = DataQuery::where('rule_code', 'MAJ_37')->firstOrFail();
        $this->assertSame('Enfant n°1 (caregiver n°1) : dates des cycles CPS dans le désordre – C3 (01/08/2026) avant C2 (10/08/2026). '
            .'Dates saisies : C1 : 10/07/2026 · C2 : 10/08/2026 · C3 : 01/08/2026 · C4 : traité, sans date · C5 : 05/10/2026.', $query->message);

        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[1][variable]" value="date_2"', false)
            ->assertSee('name="corrections[2][variable]" value="date_3"', false)
            ->assertDontSee('value="date_5"', false);
    }

    public function test_recall_dose_too_early_names_the_vaccine_and_the_dose(): void
    {
        $this->household('TCH-SEB-01-011', [['age_months_est' => '10', 'meas_rec' => '1', 'measmth9' => '1', 'measmth15' => '1', 'rtss_rec' => '1', 'rtss4_rec' => '1']]);

        $query = DataQuery::where('rule_code', 'MAJ_32')->firstOrFail();
        $this->assertStringStartsWith('Enfant n°1 (caregiver n°1), 10 mois : trop jeune pour les doses déclarées au rappel – Rougeole (RR 2, dose de 15 mois) (q. 171, « Oui ») : âge minimal 15 mois ; '
            .'VAP 4 (4e dose du vaccin antipaludique) (q. 168, « Oui ») : âge minimal 18 mois à Tchaourou.', $query->message);
        $this->assertSame('Rougeole (RR 2, dose de 15 mois) : 15 mois ou plus ; VAP 4 (4e dose du vaccin antipaludique) : 18 mois ou plus', $query->expected);

        // Âge calculé, non modifiable : la correction porte sur la date de naissance ou les réponses au rappel.
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[0][variable]" value="child_dob"', false)
            ->assertSee('value="measmth15"', false)->assertSee('value="rtss4_rec"', false);
    }

    public function test_three_malaria_doses_review_is_not_queried_for_a_fully_vaccinated_stamped_card(): void
    {
        $this->household('TCH-SEB-01-012', [
            // Carnet « Enfant complètement vacciné » : aucune case VAP cochée, enfant déclaré vacciné.
            ['age_months_est' => '20', 'receive_vaccine' => '1', 'see_card' => '1', 'mal1' => '0', 'mal2' => '0', 'mal3' => '0', 'mal4' => '0',
                'rtss_rec' => '1', 'rtss3_rec' => '0', 'three_MV_doses' => '1'],
            // VAP 1 et 2 sur la carte, pas VAP 3 : contradiction.
            ['age_months_est' => '20', 'receive_vaccine' => '1', 'see_card' => '1', 'mal1' => '1', 'mal1date' => '2025-07-10', 'mal2' => '1', 'mal3' => '0',
                'rtss_rec' => '1', 'rtss1_rec' => '1', 'rtss2_rec' => '1', 'rtss3_rec' => '0', 'three_MV_doses' => '1'],
        ]);

        $queries = DataQuery::where('rule_code', 'MAJ_34')->get();
        $this->assertCount(1, $queries);
        $this->assertSame('Enfant n°2 (caregiver n°1), 20 mois : revue « 3 doses de VAP la 1re année » = Oui, mais la carte indique VAP 3 non reçu '
            .'(VAP 1 : Oui le 10/07/2025, VAP 2 : Oui, VAP 3 : Non) ; au rappel, le caregiver déclare la 3e dose non reçue (1re dose : Oui, 2e dose : Oui, 3e dose : Non).', $queries->first()->message);
        $this->assertStringContainsString('Enfant complètement vacciné', $queries->first()->suggestion);
    }
}
