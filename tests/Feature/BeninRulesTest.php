<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\QueryEvent;
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
            // Chaque valeur en cause est corrigeable dans son membre : enfants déclarés par caregiver, tranche d'âge
            // des membres de 5 ans ou plus (jamais au niveau du ménage).
            ->assertSee('Valeurs en cause (caregivers, membres de 5 ans ou plus)')
            ->assertSee('Caregiver n°1 – enfants de moins de 5 ans déclarés')
            ->assertSee('name="corrections[0][instance_path]" value="cg[1]"', false)
            ->assertSee('name="corrections[1][instance_path]" value="cg[2]"', false)
            ->assertSee('name="corrections[2][variable]" value="age_group"', false)
            ->assertSee('name="corrections[2][instance_path]" value="smc_cov_roster_2023[1]"', false)
            ->assertDontSee('name="corrections[0][instance_path]" value=""', false);

        // Le caregiver n°1 a en fait un seul enfant de moins de 5 ans : la correction est enregistrée sur son membre.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'caregiver_num_children', 'instance_path' => 'cg[1]', 'new_value' => '1', 'optional' => '1', 'group' => 'paire', 'reason' => 'carnet'],
            ['variable' => 'caregiver_num_children', 'instance_path' => 'cg[2]', 'new_value' => '1', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $correction = DataCorrection::where('data_query_id', $query->id)->sole();
        $this->assertSame(['caregiver_num_children', 'cg[1]', '2', '1'], [$correction->variable, $correction->instance_path, $correction->old_value, $correction->new_value]);
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

        // MAJ_26 : la query nomme la série en cause (RR, pas Penta), porte sur RR 2 et liste les réponses de la série.
        $doses = DataQuery::where('rule_code', 'MAJ_26')->firstOrFail();
        $this->assertSame('Enfant n°1 (caregiver n°1) : dose reçue sans la dose précédente sur la carte – RR 2 (q. 129) cochée « Oui », mais RR 1 (q. 126) cochée « Non ».', $doses->message);
        $this->assertSame(['meas2', 'meas1 = 0 ; meas2 = 1'], [$doses->variable, $doses->observed_value]);
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $doses))->assertOk()
            ->assertSee('<code class="text-muted">meas1</code>', false)->assertDontSee('<code class="text-muted">Penta1</code>', false)
            ->assertSee('name="corrections[0][variable]" value="meas2"', false)
            ->assertSee('name="corrections[1][variable]" value="meas1"', false)
            ->assertSee('name="pair_choice"', false);
    }

    public function test_recall_dose_without_the_previous_one_names_the_series_in_cause(): void
    {
        $this->household('TCH-SEB-01-041', [
            ['age_months_est' => '20', 'penta1_rec' => '1', 'penta2_rec' => '1', 'penta3_rec' => '0', 'rtss1_rec' => '0', 'rtss2_rec' => '1'],
            // « Ne sais pas » pour la dose précédente : pas relevé.
            ['age_months_est' => '20', 'rtss1_rec' => '2', 'rtss2_rec' => '1'],
        ]);

        $query = DataQuery::where('rule_code', 'MAJ_31')->firstOrFail();
        $this->assertSame(1, $this->rules('MAJ_31'));
        $this->assertMatchesRegularExpression('/^Enfant n°1 \(caregiver n°1\) : au rappel, dose déclarée reçue sans la dose précédente – VAP 2 \(q\. \d+\) répondu « Oui », mais VAP 1 \(q\. \d+\) répondu « Non »\.$/u', $query->message);
        $this->assertSame(['rtss2_rec', 'rtss1_rec = 0 ; rtss2_rec = 1 ; rtss3_rec = ∅ ; rtss4_rec = ∅'], [$query->variable, $query->observed_value]);
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertDontSee('<code class="text-muted">penta1_rec</code>', false)
            ->assertSee('name="corrections[0][variable]" value="rtss2_rec"', false)
            ->assertSee('name="corrections[1][variable]" value="rtss1_rec"', false);
    }

    public function test_dose_series_migration_moves_existing_queries_to_the_dose_in_cause(): void
    {
        $this->household('TCH-SEB-01-040', [['receive_vaccine' => '1', 'Penta1' => '1', 'Penta2' => '1', 'Penta3' => '0', 'mal3' => '0', 'mal4' => '1']]);
        $query = DataQuery::where('rule_code', 'MAJ_26')->firstOrFail();
        $this->assertStringContainsString('VAP 4 (q. 113) cochée « Oui », mais VAP 3 (q. 109) cochée « Non »', $query->message);
        $this->assertSame('mal1 = ∅ ; mal2 = ∅ ; mal3 = 0 ; mal4 = 1', $query->observed_value);

        // Query créée par l'ancienne règle : portée par Penta1, avec une réponse en cours.
        $old = hash('sha256', implode('|', ['MAJ_26', $query->household_id, $query->instance_path ?? '', 'Penta1', '']));
        $query->update(['variable' => 'Penta1', 'fingerprint' => $old, 'status' => DataQuery::STATUS_JUSTIFIED]);
        (require database_path('migrations/2026_11_25_150000_detailed_dose_series_rule.php'))->up();

        $query->refresh();
        $this->assertSame('mal4', $query->variable);
        // Le contrôle suivant retrouve la même query (même empreinte) : pas de doublon, réponse conservée.
        app(QualityEngine::class)->run('test');
        $this->assertSame(1, $this->rules('MAJ_26'));
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $query->fresh()->status);
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

    public function test_young_caregiver_shows_the_member_age_group_and_the_caregiver_age(): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:YC', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-021', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], [
            'smc_cov_roster_2023' => [
                ['key' => null, 'data' => ['person_initials' => 'BS', 'person_caregiver' => '0', 'age_group' => '9'], 'repeats' => []],
                ['key' => null, 'data' => ['person_initials' => 'AK', 'person_caregiver' => '1', 'age_group' => '6'], 'repeats' => []],
            ],
            'cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'firstname' => 'AK', 'age_years' => '30', 'caregiver_num_children' => '1'], 'repeats' => []]],
        ], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        $query = DataQuery::where('rule_code', 'MAJ_11')->firstOrFail();
        $this->assertSame('Membre n°2 AK : noté caregiver (q. 27), mais tranche d\'âge cochée 10-14 (moins de 15 ans) ; caregiver interrogé correspondant : n°1 AK, 30 ans (q. 34).', $query->message);
        $this->assertSame('smc_cov_roster_2023[2]', $query->instance_path);

        // Trois valeurs en cause, chacune corrigeable dans son membre.
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[0][variable]" value="person_caregiver"', false)
            ->assertSee('name="corrections[1][variable]" value="age_group"', false)
            ->assertSee('name="corrections[1][instance_path]" value="smc_cov_roster_2023[2]"', false)
            ->assertSee('name="corrections[2][variable]" value="age_years"', false)
            ->assertSee('name="corrections[2][instance_path]" value="cg[1]"', false)
            ->assertSee('Caregiver n°1 – âge (années)');
    }

    public function test_household_without_adult_shows_the_roster_ages_and_can_be_confirmed(): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:NA', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-020', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], ['smc_cov_roster_2023' => [
            ['key' => null, 'data' => ['person_initials' => 'AK', 'person_sex' => '1', 'age_group' => '6'], 'repeats' => []],
            ['key' => null, 'data' => ['person_initials' => 'BS', 'person_sex' => '0', 'age_group' => '0'], 'repeats' => []],
        ]], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        $query = DataQuery::where('rule_code', 'MAJ_10')->firstOrFail();
        $this->assertSame('Aucun membre de 15 ans ou plus parmi les 2 membres. Tranches d\'âge cochées : n°1 AK (F) : 10-14 ; n°2 BS (M) : moins de 5 ans.', $query->message);

        $manager = $this->manager();
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Membres du ménage (tranche d&#039;âge cochée)', false)
            ->assertSee('Membre n°1 AK (F) – tranche d&#039;âge', false)
            ->assertSee('6 – 10-14')
            ->assertSee('name="corrections[0][instance_path]" value="smc_cov_roster_2023[1]"', false)
            ->assertSee('name="corrections[1][instance_path]" value="smc_cov_roster_2023[2]"', false)
            ->assertSee('name="pair_choice" id="pair-none" value="none"', false);

        // Rien à changer : ménage réellement sans adulte, query confirmée sans correction.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => 'none', 'comment' => 'Enfants seuls, parents en exode.', 'corrections' => [
            ['variable' => 'age_group', 'instance_path' => 'smc_cov_roster_2023[1]', 'new_value' => '6', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $query->fresh()->status);
        $this->assertSame(0, DataCorrection::where('data_query_id', $query->id)->count());
        $this->assertTrue(QueryEvent::where('data_query_id', $query->id)->where('comment', 'like', '%aucun membre de 15 ans ou plus. Enfants seuls%')->exists());
    }

    /** Ménage avec un enfant de 5 ans ou plus (MAJ_18), âge venant de la date de naissance documentée ou de l'âge déclaré. */
    private function olderChild(string $code, array $child): DataQuery
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:'.$code, [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => $code, 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '1', 'age_years' => '30'],
            'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => $child, 'repeats' => []]]]]],
        ], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        return DataQuery::where('rule_code', 'MAJ_18')->where('household_code', $code)->firstOrFail();
    }

    public function test_child_five_or_older_is_solved_by_correcting_the_date_of_birth_and_the_age_follows(): void
    {
        $query = $this->olderChild('TCH-SEB-01-030', ['child_dob_documented' => '1', 'child_dob' => '2020-05-01', 'age_months_est' => '77']);
        $manager = $this->manager();

        // Traitement : la question d'où vient l'âge (date documentée), ou « âge juste ».
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('L&#039;âge de l&#039;enfant est-il faux ?', false)
            ->assertSee('name="corrections[0][variable]" value="child_dob"', false)
            ->assertSee('name="pair_choice" id="pair-none" value="none"', false)
            ->assertDontSee('id="pair-all"', false);

        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => '0', 'corrections' => [
            ['variable' => 'child_dob', 'instance_path' => $query->instance_path, 'new_value' => '2024-05-01', 'reason' => 'acte', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();

        // L'âge calculé suit la date corrigée (20/10/2026 − 01/05/2024 = 902 jours ≈ 29 mois).
        $corrections = DataCorrection::where('data_query_id', $query->id)->pluck('new_value', 'variable');
        $this->assertSame('2024-05-01', $corrections['child_dob']);
        $this->assertSame('29', $corrections['age_months_est']);
        $this->assertSame('29', $corrections['calc_dob_months']);

        // Validation : correction appliquée, l'anomalie disparaît, la query est fermée.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'comment' => 'OK'])->assertSessionHasNoErrors();
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
        $this->assertSame(29, Child::where('household_id', $query->household_id)->value('age_months'));
    }

    public function test_child_five_or_older_declared_age_can_be_corrected_in_years_and_months(): void
    {
        $query = $this->olderChild('TCH-SEB-01-031', ['child_dob_documented' => '0', 'child_dob_caregiver_known' => '0', 'child_years' => '5', 'child_months' => '2', 'age_months_est' => '62']);
        $manager = $this->manager();
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[0][variable]" value="child_years"', false)
            ->assertSee('name="corrections[1][variable]" value="child_months"', false)
            ->assertSee('id="pair-all"', false);

        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => '0', 'corrections' => [
            ['variable' => 'child_years', 'instance_path' => $query->instance_path, 'new_value' => '2', 'reason' => 'revisite', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $corrections = DataCorrection::where('data_query_id', $query->id)->pluck('new_value', 'variable');
        $this->assertSame(['2', '26', '26'], [$corrections['child_years'], $corrections['child_age_reported'], $corrections['age_months_est']]);
    }

    public function test_child_five_or_older_confirmed_by_the_team_is_closed_as_unresolvable_at_final_validation(): void
    {
        $query = $this->olderChild('TCH-SEB-01-032', ['child_dob_documented' => '1', 'child_dob' => '2020-05-01', 'age_months_est' => '77']);
        // Réponse d'un compte qui ne valide pas (superviseur à qui la query est assignée).
        $responder = User::factory()->create(['health_zone_id' => $query->health_zone_id]);
        $responder->assignRole(User::ROLE_SUPERVISOR);
        $query->update(['assigned_to' => $responder->id, 'status' => DataQuery::STATUS_SENT]);

        $this->withoutVite()->actingAs($responder)->get(route('queries.show', $query))->assertOk()
            ->assertSee('la validation finale fermera la query comme irrésoluble');
        $this->actingAs($responder)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => 'none', 'comment' => 'Vu sur l\'acte de naissance.', 'corrections' => [
            ['variable' => 'child_dob', 'instance_path' => $query->instance_path, 'new_value' => '2020-05-01', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $query->refresh();
        $this->assertSame([DataQuery::STATUS_JUSTIFIED, DataQuery::STATUS_UNRESOLVABLE], [$query->status, $query->closes_as]);
        $this->assertSame(0, DataCorrection::where('data_query_id', $query->id)->count());

        // Data manager : l'action de validation annonce la fermeture comme irrésoluble, puis la query est fermée ainsi.
        $manager = $this->manager();
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Valider directement : fermer comme irrésoluble')->assertSee('La validation finale fermera la query comme');
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])->assertSessionHasNoErrors();
        $this->assertSame(DataQuery::STATUS_UNRESOLVABLE, $query->fresh()->status);
        $this->assertNotNull($query->fresh()->closed_at);

        // Le moteur ne la rouvre pas.
        app(QualityEngine::class)->run('test');
        $this->assertSame(DataQuery::STATUS_UNRESOLVABLE, $query->fresh()->status);
    }

    public function test_child_five_or_older_confirmed_by_the_data_manager_is_closed_at_once(): void
    {
        $query = $this->olderChild('TCH-SEB-01-033', ['child_dob_documented' => '1', 'child_dob' => '2020-05-01', 'age_months_est' => '77']);
        $this->actingAs($this->manager())->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => 'none', 'corrections' => [
            ['variable' => 'child_dob', 'instance_path' => $query->instance_path, 'new_value' => '2020-05-01', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_UNRESOLVABLE, $query->status);
        $this->assertNull($query->closes_as);
        $this->assertTrue(QueryEvent::where('data_query_id', $query->id)->where('comment', 'like', 'Âge confirmé%')->exists());
    }

    public function test_vap4_too_close_to_vap3_gives_both_dates_and_offers_either_correction(): void
    {
        $this->household('TCH-SEB-01-050', [[
            'see_card' => '1', 'mal3' => '1', 'mal3dat_known' => '1', 'mal3date' => '2026-03-10',
            'mal4' => '1', 'mal4dat_known' => '1', 'mal4date' => '2026-06-01',
        ]]);

        $query = DataQuery::where('rule_code', 'MAJ_29')->firstOrFail();
        $this->assertSame('Enfant n°1 (caregiver n°1) : VAP 4 (01/06/2026) n\'est que 83 jours après VAP 3 (10/03/2026) ; il faut au moins 150 jours entre les deux doses. '
            .'L\'une des deux dates est fausse (jour et mois inversés, année, dose).', $query->message);
        $this->assertSame('mal3date', $query->variable);

        // « Quelle valeur est fausse ? » : VAP 3 ou VAP 4.
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="pair_choice"', false)
            ->assertSee('name="corrections[0][variable]" value="mal3date"', false)
            ->assertSee('name="corrections[1][variable]" value="mal4date"', false);
    }

    public function test_smc_cycles_too_close_offer_every_date_for_correction(): void
    {
        $this->household('TCH-SEB-01-051', [[
            'child_still_have_card' => '1',
            'treated_1' => '1', 'date_1' => '2026-07-10', 'treated_2' => '1', 'date_2' => '2026-08-10',
            'treated_3' => '1', 'date_3' => '2026-08-20', 'treated_4' => '1', 'date_4' => '2026-09-25',
        ]]);

        $query = DataQuery::where('rule_code', 'MAJ_38')->firstOrFail();
        $this->assertSame('Enfant n°1 (caregiver n°1) : cycles CPS à moins de 3 semaines – C3 (20/08/2026) 10 jours après C2 (10/08/2026). '
            .'Dates saisies : C1 : 10/07/2026 · C2 : 10/08/2026 · C3 : 20/08/2026 · C4 : 25/09/2026 · C5 : —.', $query->message);

        // Toutes les dates saisies sont corrigeables (pas seulement C2 et C3), et « Aucune » confirme la carte.
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[0][variable]" value="date_1"', false)
            ->assertSee('name="corrections[1][variable]" value="date_2"', false)
            ->assertSee('name="corrections[2][variable]" value="date_3"', false)
            ->assertSee('name="corrections[3][variable]" value="date_4"', false)
            ->assertSee('name="pair_choice" id="pair-none" value="none"', false);
    }

    public function test_smc_not_declared_but_on_card_offers_the_declaration_the_card_both_or_none(): void
    {
        $this->household('TCH-SEB-01-052', [[
            'child_got_smc' => '0', 'child_given_smc_card' => '1', 'child_still_have_card' => '1',
            'treated_1' => '1', 'date_1' => '2026-07-10', 'treated_2' => '1', 'treated_3' => '0',
        ]]);

        $query = DataQuery::where('rule_code', 'MAJ_41')->firstOrFail();
        $this->assertSame('Enfant n°1 (caregiver n°1) : le caregiver déclare que l\'enfant n\'a pas reçu la CPS (q. 173.5 « Non »), mais la carte CPS montre des cycles traités : C1 (10/07/2026), C2.', $query->message);

        $manager = $this->manager();
        $this->withoutVite()->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[0][variable]" value="child_got_smc"', false)
            ->assertSee('name="corrections[1][variable]" value="treated_1"', false)
            ->assertSee('name="corrections[2][variable]" value="treated_2"', false)
            ->assertDontSee('value="treated_3"', false)
            ->assertSee('name="pair_choice" id="pair-none" value="none"', false);

        // La déclaration seule est fausse : seule elle est corrigée.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'child_got_smc', 'instance_path' => $query->instance_path, 'new_value' => '1', 'optional' => '1', 'group' => 'paire', 'reason' => 'carnet'],
            ['variable' => 'treated_1', 'instance_path' => $query->instance_path, 'new_value' => '1', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['child_got_smc'], DataCorrection::where('data_query_id', $query->id)->pluck('variable')->all());
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
            ->assertDontSee('value="date_5"', false)
            ->assertSee('name="pair_choice" id="pair-none" value="none"', false)->assertSee('vient de la carte CPS elle-même');

        // Aucune date fausse : l'erreur est sur la carte. Valeurs confirmées, aucune correction.
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'pair_choice' => 'none', 'corrections' => [
            ['variable' => 'date_2', 'instance_path' => $query->instance_path, 'new_value' => '2026-08-10', 'optional' => '1', 'group' => 'paire'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $query->fresh()->status);
        $this->assertSame(0, DataCorrection::where('data_query_id', $query->id)->count());
        $this->assertTrue(QueryEvent::where('data_query_id', $query->id)->where('comment', 'like', '%l\'erreur vient de la carte elle-même%')->exists());
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
        $this->assertSame('Enfant n°2 (caregiver n°1), 20 mois : revue « 3 doses de VAP la 1re année » = Oui, mais la carte indique VAP 3 non reçu ; '
            .'au rappel, le caregiver déclare la 3e dose non reçue. Réponses cochées – Carte : VAP 1 : Oui le 10/07/2025, VAP 2 : Oui, VAP 3 : Non · '
            .'Rappel : 1re dose : Oui, 2e dose : Oui, 3e dose : Non.', $queries->first()->message);
        $this->assertStringContainsString('Enfant complètement vacciné', $queries->first()->suggestion);

        // Fiche : les réponses cochées des 3 premières doses (carte et rappel), corrigeables.
        $this->withoutVite()->actingAs($this->manager())->get(route('queries.show', $queries->first()))->assertOk()
            ->assertSee('Réponses cochées (VAP 1 à 3, carte puis rappel)')
            ->assertSee('0 – Non')
            ->assertSee('value="mal1"', false)->assertSee('value="mal3"', false)->assertSee('value="rtss3_rec"', false);
    }
}
