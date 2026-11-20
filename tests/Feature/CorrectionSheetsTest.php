<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\CorrectionSheets;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Restitution au format du document qualité Bénin : synthèse, fiche par binôme, observations, règles non appliquées. */
class CorrectionSheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        // Tchaourou, agent 46 (binôme 46-47) : Penta 2 avant Penta 1 (majeure) et VPO 0 avant la naissance (critique).
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:A', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-001', 'household_survey_status' => '0', 'num_slept_last_night' => '3', 'hh_caregivers_num' => '1',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
            'child_dob' => '2025-01-10', 'receive_vaccine' => '1', 'see_card' => '1', 'opv0' => '1', 'opv0date' => '2025-01-01',
            'Penta1' => '1', 'Penta1date' => '2025-03-25', 'Penta2' => '1', 'Penta2date' => '2025-02-20',
        ], 'repeats' => []]]]]]], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');
    }

    public function test_sheet_follows_the_document_format(): void
    {
        $report = (new CorrectionSheets)->build();
        $this->assertSame('46-47', substr($report['synthesis'][0]['team'], 0, 5));
        $this->assertGreaterThan(0, $report['synthesis'][0]['critique']);

        $sheet = $report['sheets'][0];
        $this->assertStringStartsWith('Binôme 46-47', $sheet['title']);
        $this->assertStringContainsString('– Zone Tchaourou – Commune', $sheet['title']);
        // Critiques d'abord, puis majeures ; rang de la personne et question numérotée.
        $severities = array_column($sheet['corrections'], 'severity');
        $this->assertSame('CRITIQUE', $severities[0]);
        $this->assertSame($severities, collect($severities)->sortBy(fn ($s) => $s === 'CRITIQUE' ? 0 : 1)->values()->all());
        $order = collect($sheet['corrections'])->firstWhere('code', 'VAC_DOSE_ORDER');
        $this->assertSame('Enfant n° 1 (gardien n° 1)', $order['person']);
        $this->assertMatchesRegularExpression('/^Q\d+ – /', $order['question']);
        $this->assertStringNotContainsString('Penta2date', $order['problem'].$order['action']);
        $this->assertStringNotContainsString('Enfant n°1', $order['problem'], 'rang déjà dans « Personne »');
        // Règles non appliquées : règles désactivées et contrôles non repris.
        $this->assertContains('MAJ-47', array_column($report['not_applied'], 'code'));
        $this->assertContains('AVT-01', array_column($report['not_applied'], 'code'));
    }

    public function test_page_exports_and_team_pdf_respect_scopes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('correction-sheets.index'))->assertOk()
            ->assertSee('1. Synthèse par zone sanitaire et par binôme')->assertSee('Binôme 46-47')->assertSee('5. Règles non appliquées');
        $this->actingAs($admin)->get(route('correction-sheets.excel'))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('correction-sheets.pdf'))->assertOk();

        // Superviseur de Dagla : rien de Tchaourou.
        $dagla = User::factory()->create(['health_zone_id' => HealthZone::where('code', '2')->value('id')]);
        $dagla->assignRole(User::ROLE_SUPERVISOR);
        $this->actingAs($dagla)->get(route('correction-sheets.index'))->assertOk()->assertDontSee('Binôme 46-47');

        // Compte binôme : sa propre fiche en PDF.
        $team = User::factory()->create(['team_id' => Team::where('code', '46-47')->value('id')]);
        $team->assignRole(User::ROLE_TEAM);
        $this->actingAs($team)->get(route('team.corrections.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($team)->get(route('correction-sheets.index'))->assertRedirect();
        $this->assertGreaterThan(0, DataQuery::count());
    }
}
