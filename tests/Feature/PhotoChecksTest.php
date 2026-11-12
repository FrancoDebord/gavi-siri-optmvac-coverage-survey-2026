<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\PhotoCheck;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoChecksTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_PATH = 'cg[1]/smc_cov_hh_cg_child_2023[1]';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function child(string $sex, array $vaccines): array
    {
        return ['key' => null, 'repeats' => [], 'data' => [
            'child_sex' => $sex, 'child_dob' => '2025-05-02', 'receive_vaccine' => '1', 'see_card' => '1',
            'c_low' => '12', 'c_high' => '35', 'age_months_est' => '18',
        ] + $vaccines];
    }

    private function submit(array $children): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:m', [
            'today' => '2026-11-06', 'l1' => '2', 'l2' => '2', 'l3' => '28', 'village' => '1', 'fieldworker' => '34',
            'household' => 'DAG-MIN-03-001', 'household_survey_status' => '0',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'firstname' => 'AB'], 'repeats' => [
            'smc_cov_hh_cg_child_2023' => $children,
        ]]]], []), ['source_type' => 'demo']);
    }

    private const DATES = [
        'bcgdate' => '2025-05-12', 'opv0date' => '2025-05-12', 'Penta1date' => '2025-06-30', 'Penta2date' => '2025-07-29',
        'Penta3date' => '2025-08-27', 'mal2date' => '2025-12-03', 'mal3date' => '2026-01-07', 'YFdate' => '2026-04-27',
    ];

    public function test_photo_check_opens_then_closes_when_entry_is_corrected(): void
    {
        $this->submit([$this->child('0', self::DATES + ['mal1date' => '2025-11-30'])]);
        PhotoCheck::create(['submission_uuid' => 'uuid:m', 'instance_path' => self::CHILD_PATH, 'variable' => 'mal1date',
            'expected' => '2025-11-03', 'message' => 'VAP 1 : le carnet porte 03/11/2025']);
        PhotoCheck::create(['submission_uuid' => 'uuid:m', 'instance_path' => self::CHILD_PATH, 'variable' => 'pcv1date',
            'expected' => null, 'message' => 'PCV 1 non visible sur la photo du carnet']);

        app(QualityEngine::class)->run('test');
        $queries = DataQuery::where('rule_code', 'PHOTO_CHECK')->orderBy('id')->get();
        $this->assertCount(2, $queries);
        [$vap1, $pcv1] = $queries;
        $this->assertSame(['30/11/2025', '03/11/2025'], [$vap1->observed_value, $vap1->expected]);
        $this->assertStringContainsString('Enfant n°1 (caregiver n°1) : VAP 1', $vap1->message);
        $this->assertNotNull($vap1->child_id);
        $this->assertStringContainsString('03/11/2025 au lieu de 30/11/2025', $vap1->suggestion);

        // Saisie corrigée dans ODK : la query de VAP 1 se ferme, celle sans valeur attendue reste ouverte.
        $this->submit([$this->child('0', self::DATES + ['mal1date' => '2025-11-03'])]);
        app(QualityEngine::class)->run('test');
        $this->assertSame(DataQuery::STATUS_VALIDATED, $vap1->fresh()->status);
        $this->assertTrue($vap1->fresh()->auto_closed);
        $this->assertSame(DataQuery::STATUS_OPEN, $pcv1->fresh()->status);
    }

    public function test_expected_empty_value_and_status_values_are_not_dates(): void
    {
        $this->submit([$this->child('0', self::DATES + ['meas1' => '0', 'meas2' => '1', 'meas2date' => '2026-04-27'])]);
        foreach ([['meas2date', ''], ['meas1', '1']] as [$variable, $expected]) {
            PhotoCheck::create(['submission_uuid' => 'uuid:m', 'instance_path' => self::CHILD_PATH, 'variable' => $variable, 'expected' => $expected, 'message' => 'RR']);
        }

        app(QualityEngine::class)->run('test');
        $this->assertEqualsCanonicalizing(['vide', '1'], DataQuery::where('rule_code', 'PHOTO_CHECK')->pluck('expected')->all());

        $this->submit([$this->child('0', self::DATES + ['meas1' => '1', 'meas1date' => '2026-04-27', 'meas2' => '0'])]);
        app(QualityEngine::class)->run('test');
        $this->assertSame(0, DataQuery::where('rule_code', 'PHOTO_CHECK')->whereIn('status', DataQuery::UNRESOLVED)->count());
    }

    public function test_same_card_for_two_children_is_flagged_even_with_different_sex(): void
    {
        $this->submit([
            $this->child('0', self::DATES + ['mal1date' => '2025-11-30']),
            $this->child('1', self::DATES + ['mal1date' => '2025-11-03']),
        ]);

        app(QualityEngine::class)->run('test');

        $queries = DataQuery::where('rule_code', 'DUP_CARD')->orderBy('id')->get();
        $this->assertCount(2, $queries, 'une query par enfant');
        $this->assertStringContainsString('8 dates de vaccination sur 9 identiques', $queries[0]->message);
        $this->assertStringContainsString('(sexe différent)', $queries[0]->message);
        $this->assertFalse(DataQuery::where('rule_code', 'DUP_CHILD')->exists(), 'DUP_CHILD compare le sexe');
    }

    public function test_vap4_minimum_age_is_15_months_in_dassa_glazoue_only(): void
    {
        // Né le 02/05/2025, VAP 4 le 30/07/2026 : 454 jours (≥ 450 à Dagla, < 540 ailleurs).
        $this->submit([$this->child('0', self::DATES + ['mal4date' => '2026-07-30'])]);
        $processor = new SubmissionProcessor;
        $processor->process(new NormalizedSubmission('uuid:t', [
            'today' => '2026-11-06', 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => [
            'smc_cov_hh_cg_child_2023' => [$this->child('0', self::DATES + ['mal4date' => '2026-07-30'])],
        ]]]], []), ['source_type' => 'demo']);

        app(QualityEngine::class)->run('test');

        $queries = DataQuery::where('rule_code', 'VAC_MIN_AGE')->where('variable', 'mal4date')->pluck('message', 'submission_uuid');
        $this->assertArrayNotHasKey('uuid:m', $queries->all(), 'Dassa-Glazoué : VAP 4 autorisé dès 450 jours');
        $this->assertStringContainsString('VAP 4 reçu à 454 jour(s) de vie (30/07/2026), âge minimal 540 jours', $queries['uuid:t']);
    }

    public function test_children_with_different_records_are_not_flagged(): void
    {
        $other = ['bcgdate' => '2025-05-20', 'opv0date' => '2025-05-20', 'Penta1date' => '2025-07-02', 'Penta2date' => '2025-08-05', 'Penta3date' => '2025-09-02', 'YFdate' => '2026-04-27'];
        $this->submit([$this->child('0', self::DATES), $this->child('1', $other)]);

        app(QualityEngine::class)->run('test');

        $this->assertFalse(DataQuery::where('rule_code', 'DUP_CARD')->exists());
    }
}
