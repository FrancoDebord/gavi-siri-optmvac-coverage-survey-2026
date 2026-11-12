<?php

namespace Tests\Unit;

use App\Models\Caregiver;
use App\Models\Child;
use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QualityRuleInterface;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Rules\Business;
use App\Services\Quality\SubmissionContext;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Règles de vaccination évaluées sur des soumissions construites en mémoire
 * (sans base de données : mapping par défaut de config/gavi.php).
 */
class VaccinationRulesTest extends TestCase
{
    private function context(array $child, string $interviewDate = '2026-10-20', array $household = []): SubmissionContext
    {
        $model = new Household(['interview_date' => $interviewDate, 'data' => $household + ['c_low' => '12', 'c_high' => '35']]);
        $model->id = 1;
        $caregiver = new Caregiver(['position' => 1, 'consented' => true, 'data' => ['participation_agreed' => '1']]);
        $caregiver->id = 10;
        $childModel = new Child(['position' => 1, 'caregiver_id' => 10, 'data' => $child]);
        $childModel->id = 100;
        $model->setRelation('caregivers', collect([$caregiver]));
        $model->setRelation('children', collect([$childModel]));

        return new SubmissionContext($model, new Dictionary(null), CarbonImmutable::parse('2026-10-28'));
    }

    /** @return list<QueryCandidate> */
    private function apply(string $class, SubmissionContext $context, string $code, array $parameters = []): array
    {
        /** @var QualityRuleInterface $rule */
        $rule = new $class;

        return iterator_to_array($rule->evaluate($context, new QualityRule(['code' => $code, 'parameters' => $parameters])), false);
    }

    private function child(array $overrides = []): array
    {
        return $overrides + [
            'child_dob' => '2025-01-10',
            'receive_vaccine' => '1',
            'see_card' => '1',
            'bcg' => '1', 'bcgdate' => '2025-01-12',
            'Penta1' => '1', 'Penta1date' => '2025-02-25',
            'Penta2' => '1', 'Penta2date' => '2025-03-27',
            'Penta3' => '1', 'Penta3date' => '2025-04-28',
            'meas1' => '1', 'meas1date' => '2025-10-15',
        ];
    }

    public function test_a_consistent_schedule_raises_no_query(): void
    {
        $context = $this->context($this->child());
        foreach ([
            [Business\VaccineBeforeBirthRule::class, 'VAC_BEFORE_DOB'],
            [Business\VaccineAfterInterviewRule::class, 'VAC_AFTER_INTERVIEW'],
            [Business\VaccineDoseOrderRule::class, 'VAC_DOSE_ORDER'],
            [Business\VaccineDoseIntervalRule::class, 'VAC_DOSE_INTERVAL'],
            [Business\VaccineMinAgeRule::class, 'VAC_MIN_AGE'],
            [Business\VaccineStatusMismatchRule::class, 'VAC_STATUS_MISMATCH'],
            [Business\CardSeenNoDatesRule::class, 'CARD_NO_DATES'],
            [Business\ChildAgeEligibilityRule::class, 'ELIG_AGE'],
        ] as [$class, $code]) {
            $this->assertSame([], $this->apply($class, $context, $code), $code);
        }
    }

    public function test_vaccine_before_birth(): void
    {
        $queries = $this->apply(Business\VaccineBeforeBirthRule::class, $this->context($this->child(['bcgdate' => '2025-01-01'])), 'VAC_BEFORE_DOB');
        $this->assertCount(1, $queries);
        $this->assertSame('bcgdate', $queries[0]->variable);
        $this->assertSame('cg[1]/smc_cov_hh_cg_child_2023[1]', $queries[0]->scope->path);
    }

    public function test_vaccine_after_interview(): void
    {
        $queries = $this->apply(Business\VaccineAfterInterviewRule::class, $this->context($this->child(['meas1date' => '2026-10-25'])), 'VAC_AFTER_INTERVIEW');
        $this->assertCount(1, $queries);
        $this->assertSame('meas1date', $queries[0]->variable);
    }

    public function test_dose_order(): void
    {
        $queries = $this->apply(Business\VaccineDoseOrderRule::class, $this->context($this->child(['Penta2date' => '2025-02-20'])), 'VAC_DOSE_ORDER');
        $this->assertCount(1, $queries);
        $this->assertSame('Penta2date', $queries[0]->variable);
        $this->assertStringContainsString('Penta 2', $queries[0]->message);
    }

    public function test_short_interval_uses_parameter(): void
    {
        $child = $this->child(['Penta2date' => '2025-03-10']); // 13 jours après Penta1
        $this->assertCount(1, $this->apply(Business\VaccineDoseIntervalRule::class, $this->context($child), 'VAC_DOSE_INTERVAL'));
        $this->assertCount(0, $this->apply(Business\VaccineDoseIntervalRule::class, $this->context($child), 'VAC_DOSE_INTERVAL', ['min_interval_days' => 10]));
    }

    public function test_measles_before_nine_months(): void
    {
        $queries = $this->apply(Business\VaccineMinAgeRule::class, $this->context($this->child(['meas1date' => '2025-07-01'])), 'VAC_MIN_AGE');
        $this->assertCount(1, $queries);
        $this->assertSame('meas1date', $queries[0]->variable);
        $this->assertSame('≥ 270 jours', $queries[0]->expected);
    }

    public function test_status_mismatch(): void
    {
        $queries = $this->apply(Business\VaccineStatusMismatchRule::class, $this->context($this->child(['Penta1' => '0'])), 'VAC_STATUS_MISMATCH');
        $this->assertCount(1, $queries);
        $this->assertSame('Penta1', $queries[0]->variable);

        $never = $this->apply(Business\VaccineStatusMismatchRule::class, $this->context($this->child(['receive_vaccine' => '0'])), 'VAC_STATUS_MISMATCH');
        $this->assertSame('receive_vaccine', $never[0]->variable);
    }

    public function test_card_seen_without_dates(): void
    {
        $child = ['child_dob' => '2025-01-10', 'receive_vaccine' => '1', 'see_card' => '1', 'bcg' => '1', 'bcgdat_known' => '1'];
        $this->assertCount(1, $this->apply(Business\CardSeenNoDatesRule::class, $this->context($child), 'CARD_NO_DATES'));

        // Un motif de date manquante explique l'absence de date.
        $child['bcgInvalid'] = '2';
        $this->assertCount(0, $this->apply(Business\CardSeenNoDatesRule::class, $this->context($child), 'CARD_NO_DATES'));
    }

    public function test_enrolled_child_outside_age_range(): void
    {
        $queries = $this->apply(Business\ChildAgeEligibilityRule::class, $this->context($this->child(['child_dob' => '2026-06-01'])), 'ELIG_AGE');
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('4 mois', $queries[0]->message);

        // Hors tranche mais non enrôlé (pas de section vaccination) : pas de query.
        $notEnrolled = ['child_dob' => '2026-06-01'];
        $this->assertCount(0, $this->apply(Business\ChildAgeEligibilityRule::class, $this->context($notEnrolled), 'ELIG_AGE'));
    }

    public function test_declared_age_inconsistent_with_dob(): void
    {
        $child = $this->child(['child_years' => '3', 'child_months' => '0']); // 21 mois d'après la DDN
        $queries = $this->apply(Business\AgeDobConsistencyRule::class, $this->context($child), 'ELIG_AGE_DOB');
        $this->assertCount(1, $queries);
        $this->assertSame('36 mois', $queries[0]->observed);

        $ok = $this->child(['child_years' => '1', 'child_months' => '9']);
        $this->assertCount(0, $this->apply(Business\AgeDobConsistencyRule::class, $this->context($ok), 'ELIG_AGE_DOB'));
    }

    public function test_fingerprint_is_stable_and_scoped(): void
    {
        $a = $this->apply(Business\VaccineBeforeBirthRule::class, $this->context($this->child(['bcgdate' => '2025-01-01'])), 'VAC_BEFORE_DOB')[0];
        $b = $this->apply(Business\VaccineBeforeBirthRule::class, $this->context($this->child(['bcgdate' => '2024-12-01'])), 'VAC_BEFORE_DOB')[0];
        $this->assertSame($a->fingerprint('VAC_BEFORE_DOB', 1), $b->fingerprint('VAC_BEFORE_DOB', 1));
        $this->assertNotSame($a->fingerprint('VAC_BEFORE_DOB', 1), $a->fingerprint('VAC_BEFORE_DOB', 2));
    }
}
