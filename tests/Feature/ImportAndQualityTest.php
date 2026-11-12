<?php

namespace Tests\Feature;

use App\Jobs\ImportSubmissionsJob;
use App\Models\Caregiver;
use App\Models\Child;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\ImportBatch;
use App\Models\QualityRun;
use App\Models\QueryEvent;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Services\Quality\QueryWorkflow;
use Database\Seeders\QualityRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ImportAndQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->seed(QualityRuleSeeder::class);
    }

    private function csv(string $name, array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gavi').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fclose($handle);

        return ['path' => $path, 'name' => $name];
    }

    /** Export ODK Central : fichier principal + repeats liés par KEY / PARENT_KEY. */
    private function odkExport(string $penta2 = '2025-03-27'): array
    {
        $main = $this->csv('cov26c4v2.csv', [[
            'SubmissionDate' => '2026-10-20T12:00:00.000Z', 'start' => '2026-10-20T09:00:00.000+01:00', 'end' => '2026-10-20T09:40:00.000+01:00',
            'today' => '2026-10-20', 'country' => 'bj', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '1',
            'household' => 'HH-001', 'current_location-Latitude' => '8.88', 'current_location-Longitude' => '2.60',
            'current_location-Altitude' => '200', 'current_location-Accuracy' => '5', 'visit_num' => '1', 'household_survey_status' => '0',
            'c_low' => '12', 'c_high' => '35', 'ok_Penta1' => 'Penta1', 'ok_Penta2' => 'Penta2',
            'section2-num_slept_last_night' => '6', 'section2-num_bednets' => '2', 'section2-hh_caregivers_num' => '1',
            'meta-instanceID' => 'uuid:hh-1', 'KEY' => 'uuid:hh-1', 'ReviewState' => '',
        ]]);
        $cg = $this->csv('cov26c4v2-cg.csv', [[
            'participation_agreed' => '1', 'cg_consent-firstname' => 'AB', 'cg_consent-caregiver_sex' => '1', 'cg_consent-age_years' => '28',
            'PARENT_KEY' => 'uuid:hh-1', 'KEY' => 'uuid:hh-1/section2/cg[1]',
        ]]);
        $children = $this->csv('cov26c4v2-smc_cov_hh_cg_child_2023.csv', [[
            'child_sex' => '1', 'child_dob_documented' => '1', 'child_dob' => '2025-01-10', 'child_years' => '1', 'child_months' => '9',
            'age_months_est' => '21', 'receive_vaccine' => '1', 'see_card' => '1',
            'Penta1' => '1', 'Penta1dat_known' => '1', 'Penta1date' => '2025-02-25',
            'Penta2' => '1', 'Penta2dat_known' => '1', 'Penta2date' => $penta2,
            'PARENT_KEY' => 'uuid:hh-1/section2/cg[1]', 'KEY' => 'uuid:hh-1/section2/cg[1]/cg_consent/smc_cov_hh_cg_child_2023[1]',
        ]]);

        return [$main, $cg, $children];
    }

    private function import(array $files): ImportBatch
    {
        $batch = ImportBatch::create(['type' => 'data', 'files' => $files]);
        (new ImportSubmissionsJob($batch->id))->handle();

        return $batch->fresh();
    }

    public function test_odk_csv_import_builds_households_caregivers_children(): void
    {
        $batch = $this->import($this->odkExport());

        $this->assertSame('done', $batch->status, (string) $batch->message);
        $this->assertSame('odk', $batch->format);
        $household = Household::firstOrFail();
        $this->assertSame('uuid:hh-1', $household->submission_uuid);
        $this->assertTrue($household->consented);
        $this->assertSame('2026-10-20', $household->interview_date->toDateString());
        $this->assertSame(40, $household->duration_minutes);
        $this->assertEqualsWithDelta(8.88, $household->latitude, 0.0001);
        $this->assertSame('6', $household->data['num_slept_last_night']);
        $this->assertSame('Tchaourou', $household->healthZone->name);
        $this->assertNotNull($household->cluster_id);
        $this->assertNotNull($household->collector_id);

        $this->assertSame(1, Caregiver::count());
        $child = Child::firstOrFail();
        $this->assertSame(21, $child->age_months);
        $this->assertTrue($child->eligible);
        $this->assertTrue($child->card_seen);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->import($this->odkExport());
        $second = $this->import($this->odkExport());

        $this->assertSame(1, Household::count());
        $this->assertSame(0, $second->new_count);
        $this->assertSame(0, $second->updated_count);
        $this->assertStringContainsString('1 inchangée', $second->message);

        $third = $this->import($this->odkExport('2025-03-30'));
        $this->assertSame(1, $third->updated_count);
        $this->assertSame(1, Child::count());
    }

    public function test_engine_opens_then_auto_closes_queries_when_data_is_corrected(): void
    {
        // Penta2 avant Penta1 : ordre des doses non respecté.
        $this->import($this->odkExport('2025-02-20'));
        $run = app(QualityEngine::class)->run('test');
        $this->assertSame('success', $run->status);

        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        $this->assertSame('Penta2date', $query->variable);
        $this->assertSame(DataQuery::STATUS_OPEN, $query->status);
        $this->assertSame('2026-10-20', $query->collection_date->toDateString());
        $this->assertNotNull($query->collector_id);
        $this->assertNotNull($query->cluster_id);
        $this->assertSame('cg[1]/smc_cov_hh_cg_child_2023[1]', $query->instance_path);

        // Nouvelle exécution sans changement : la même query est conservée (pas de doublon).
        app(QualityEngine::class)->run('test');
        $this->assertSame(1, DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->count());

        // La donnée est corrigée à la synchro suivante : l'import relance le moteur (queue sync), qui ferme la query.
        $this->import($this->odkExport('2025-03-27'));
        $query->refresh();
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->status);
        $this->assertTrue($query->auto_closed);
        // Ordre des doses + âge minimal de Penta2 (41 jours de vie) : les deux queries se ferment.
        $this->assertSame(2, (int) QualityRun::latest('id')->value('auto_closed_count'));
        $this->assertSame(DataQuery::STATUS_VALIDATED, DataQuery::where('rule_code', 'VAC_MIN_AGE')->value('status'));
        $this->assertTrue(QueryEvent::where('data_query_id', $query->id)->where('type', 'system')->exists());

        // L'erreur réapparaît : la query est rouverte.
        $this->import($this->odkExport('2025-02-20'));
        app(QualityEngine::class)->run('test');
        $this->assertSame(DataQuery::STATUS_OPEN, $query->fresh()->status);
    }

    public function test_dictionary_rules_detect_choice_type_and_constraint_errors(): void
    {
        $processor = new SubmissionProcessor;
        $processor->process(new NormalizedSubmission('uuid:x', [
            'today' => '2026-10-20', 'start' => '2026-10-20T09:00:00+01:00', 'end' => '2026-10-20T09:30:00+01:00',
            'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '1', 'household' => 'HH-9',
            'current_location' => '8.88 2.6 200 5', 'household_survey_status' => '0',
            'visit_num' => '7',                 // hors liste
            'num_slept_last_night' => 'six',    // type integer invalide
            'hh_caregivers_num' => '9',         // contrainte . <= ${num_slept_last_night}
        ], [], []));

        app(QualityEngine::class)->run('test');

        $this->assertTrue(DataQuery::where('rule_code', 'DICT_CHOICE')->where('variable', 'visit_num')->exists());
        $this->assertTrue(DataQuery::where('rule_code', 'DICT_TYPE')->where('variable', 'num_slept_last_night')->exists());
        $this->assertTrue(DataQuery::where('rule_code', 'DICT_REQUIRED')->where('variable', 'num_bednets')->exists());
        $this->assertTrue(DataQuery::where('rule_code', 'CONSENT_NO_CAREGIVER')->exists());
    }

    public function test_query_workflow_transitions(): void
    {
        $this->import($this->odkExport('2025-02-20'));
        app(QualityEngine::class)->run('test');
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        $workflow = app(QueryWorkflow::class);

        $workflow->transition($query, DataQuery::STATUS_SENT, null, null);
        $this->assertSame(DataQuery::STATUS_SENT, $query->status);

        try {
            $workflow->transition($query, DataQuery::STATUS_JUSTIFIED, '', null);
            $this->fail('Une justification devrait être exigée.');
        } catch (InvalidArgumentException) {
        }

        $workflow->transition($query, DataQuery::STATUS_JUSTIFIED, 'Dates vérifiées sur le carnet.', null);
        $this->assertNotNull($query->resolved_at);

        $this->expectException(InvalidArgumentException::class);
        $workflow->transition($query, DataQuery::STATUS_SENT, null, null);
    }
}
