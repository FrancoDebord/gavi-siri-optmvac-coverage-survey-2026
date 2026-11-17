<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Services\Quality\QueryWorkflow;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class QueryExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        // Deux ménages de deux zones sans GPS, l'un avec Penta2 avant Penta1.
        $processor = new SubmissionProcessor;
        foreach ([['1', '1', '1', '41', '1', 'A', '2025-02-20'], ['2', '2', '28', '1', '20', 'B', '2025-03-27']] as [$zone, $commune, $arr, $village, $collector, $code, $penta2]) {
            $processor->process(new NormalizedSubmission("uuid:{$code}", [
                'today' => '2026-10-20', 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => $collector,
                'household' => "HH-{$code}", 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
                'num_slept_last_night' => '5', 'num_bednets' => '2', 'hh_caregivers_num' => '1',
            ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
                'child_dob' => '2025-01-10', 'receive_vaccine' => '1', 'see_card' => '1',
                'Penta1' => '1', 'Penta1date' => '2025-02-25', 'Penta2' => '1', 'Penta2date' => $penta2,
            ], 'repeats' => []]]]]]], []));
        }
        app(QualityEngine::class)->run('test');

        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);
    }

    private function spreadsheet(string $content)
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $content);

        return IOFactory::load($path);
    }

    public function test_query_carries_code_label_suggestion_and_location(): void
    {
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();

        $this->assertSame('Q-'.str_pad((string) $query->id, 6, '0', STR_PAD_LEFT), $query->code);
        $this->assertSame($query->id, DataQuery::idFromCode($query->code));
        $this->assertStringStartsWith('Ordre des doses non respecté – ', $query->label);
        $this->assertStringContainsString('Penta 2', $query->variable_label);
        $this->assertStringContainsString('Relire sur le carnet', $query->suggestion);
        $this->assertStringContainsString($query->variable_label, $query->suggestion);
        $this->assertSame('HH-A', $query->household_code);
        $this->assertNotNull($query->health_zone_id);
        $this->assertNotNull($query->commune_id);
        $this->assertNotNull($query->village_id);
        $this->assertNotNull($query->cluster_id);
        $this->assertNotNull($query->collector_id);
        $this->assertSame('Non résolu', $query->resolutionLabel());
    }

    public function test_unresolvable_status_requires_reason_and_closes_query(): void
    {
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        $workflow = app(QueryWorkflow::class);

        try {
            $workflow->transition($query, DataQuery::STATUS_UNRESOLVABLE, '', null);
            $this->fail('Un motif devrait être exigé.');
        } catch (InvalidArgumentException) {
        }

        $workflow->transition($query, DataQuery::STATUS_UNRESOLVABLE, 'Carnet perdu, ménage parti.', null);
        $this->assertSame('Irrésoluble', $query->resolutionLabel());
        $this->assertNotNull($query->resolved_at);
        $this->assertNotNull($query->closed_at);

        // Réouverture (motif obligatoire) : les dates de résolution et de clôture sont effacées.
        $workflow->transition($query, DataQuery::STATUS_OPEN, 'Carnet retrouvé', null);
        $this->assertNull($query->resolved_at);
        $this->assertNull($query->closed_at);
    }

    public function test_excel_export_with_criteria(): void
    {
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        app(QueryWorkflow::class)->transition($query, DataQuery::STATUS_UNRESOLVABLE, 'Carnet perdu', $this->admin);

        $response = $this->actingAs($this->admin)->get(route('queries.export', [
            'resolution' => DataQuery::RESOLUTION_UNRESOLVABLE,
            'health_zone_id' => $query->health_zone_id,
        ]));
        $response->assertOk()->assertDownload();

        $book = $this->spreadsheet($response->streamedContent());
        $this->assertSame(['Critères', 'Queries'], $book->getSheetNames());

        $criteria = collect($book->getSheetByName('Critères')->toArray())->pluck(1, 0);
        $this->assertSame('Irrésoluble', $criteria['Statut de résolution']);
        $this->assertSame('Tchaourou', $criteria['Zone sanitaire']);
        $this->assertSame('1', (string) $criteria['Nombre de queries exportées']);

        $rows = $book->getSheetByName('Queries')->toArray();
        $header = $rows[0];
        $row = array_combine($header, $rows[1]);
        $this->assertCount(2, $rows);
        $this->assertSame($query->code, $row['Code query']);
        $this->assertSame($query->label, $row['Libellé']);
        $this->assertSame($query->message, $row['Description de l\'erreur']);
        $this->assertSame($query->suggestion, $row['Suggestion de résolution']);
        $this->assertSame('Irrésoluble', $row['Statut de résolution']);
        $this->assertSame('HH-A', $row['ID ménage']);
        $this->assertSame('Tchaourou', $row['Zone sanitaire']);
        $this->assertNotEmpty($row['Commune']);
        $this->assertNotEmpty($row['Village']);
        $this->assertNotEmpty($row['Grappe']);
        $this->assertNotEmpty($row['Agent']);
        $this->assertArrayHasKey('Binôme', $row);
        $this->assertSame('Caregiver 1 › Enfant 1', $row['Membre concerné']);
        $this->assertSame('20/10/2026', $row['Date de collecte']);
        $this->assertNotEmpty($row['Date de génération']);
        $this->assertNotEmpty($row['Date de résolution']);
        $this->assertSame('Carnet perdu', $row['Dernier commentaire']);
    }

    public function test_excel_export_grouped_by_collector(): void
    {
        $response = $this->actingAs($this->admin)->get(route('queries.export', ['group_by' => 'collector']));
        $response->assertOk();

        $book = $this->spreadsheet($response->streamedContent());
        $names = $book->getSheetNames();
        $this->assertSame(['Critères', 'Récapitulatif'], array_slice($names, 0, 2));
        $this->assertCount(2 + 2, $names, 'une feuille par agent');

        $summary = $book->getSheetByName('Récapitulatif')->toArray();
        $this->assertSame(['Agent', 'Queries', 'Non résolues', 'Résolues', 'Irrésolubles', 'Critiques', 'Ménages concernés'], $summary[0]);
        $this->assertSame(DataQuery::count(), array_sum(array_column(array_slice($summary, 1), 1)));
    }

    public function test_field_feedback_export_has_one_sheet_per_team(): void
    {
        // Agent 20 appartient au binôme 20-35 (binomes.csv) ; l'agent 1 n'a pas de binôme.
        DataQuery::query()->update(['status' => DataQuery::STATUS_OPEN]);
        $response = $this->actingAs($this->admin)->get(route('queries.export.collectors'));
        $response->assertOk();

        $book = $this->spreadsheet($response->streamedContent());
        $summary = $book->getSheetByName('Récapitulatif')->toArray();
        $this->assertSame('Binôme', $summary[0][0]);
        $this->assertEqualsCanonicalizing(['Binôme 20-35 · Akpovo & Tchehouali', 'Non renseigné'], array_column(array_slice($summary, 1), 0));
        $this->assertNotEmpty(array_filter($book->getSheetNames(), fn ($name) => str_starts_with($name, 'Binôme 20-35')), 'une feuille pour le binôme 20-35');
    }

    public function test_csv_export_uses_semicolons_and_bom(): void
    {
        $response = $this->actingAs($this->admin)->get(route('queries.export', ['format' => 'csv', 'rule_code' => 'VAC_DOSE_ORDER']));
        $response->assertOk()->assertDownload();

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = array_values(array_filter(preg_split('/\r?\n/', substr($content, 3))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('"Code query";"Libellé";"Description de l\'erreur"', $lines[0]);
    }

    public function test_list_filters_by_code_resolution_and_variable(): void
    {
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        $get = fn (array $params) => $this->actingAs($this->admin)->getJson(route('queries.datatable', ['draw' => 1, 'length' => 100] + $params))->json();

        $byCode = $get(['search' => $query->code]);
        $this->assertSame(1, $byCode['recordsFiltered']);
        $this->assertSame($query->code, $byCode['data'][0]['code']);
        $this->assertSame('Non résolu', $byCode['data'][0]['resolution_label']);
        $this->assertNotEmpty($byCode['data'][0]['suggestion']);

        $this->assertSame(1, $get(['code' => $query->code])['recordsFiltered']);
        $this->assertSame(DataQuery::where('variable', 'Penta2date')->count(), $get(['variable' => 'Penta2date'])['recordsFiltered']);
        $this->assertSame(0, $get(['resolution' => DataQuery::RESOLUTION_RESOLVED])['recordsFiltered']);
    }

    public function test_supervisor_export_stays_in_scope(): void
    {
        $query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
        $supervisor = User::factory()->create(['health_zone_id' => $query->health_zone_id]);
        $supervisor->assignRole(User::ROLE_SUPERVISOR);

        $response = $this->actingAs($supervisor)->get(route('queries.export'));
        $rows = $this->spreadsheet($response->streamedContent())->getSheetByName('Queries')->toArray();
        $zones = array_unique(array_column(array_slice($rows, 1), array_search('Zone sanitaire', $rows[0], true)));
        $this->assertSame(['Tchaourou'], array_values($zones));
    }
}
