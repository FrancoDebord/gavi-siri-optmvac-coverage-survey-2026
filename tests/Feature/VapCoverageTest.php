<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Couverture VAP 1 à 4 : carte puis rappel, dénominateur de VAP 4 selon l'âge de la 4e dose de la zone. */
class VapCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        $child = fn (string $dob, array $vaccines) => ['key' => null, 'data' => ['child_dob' => $dob, 'child_sex' => '1', 'receive_vaccine' => '1', 'child_sleep_last_night' => '1'] + $vaccines, 'repeats' => []];
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:V', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-030', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '3', 'age_years' => '30'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [
            // 30 mois : VAP 1 à 4 sur la carte.
            $child('2024-04-10', ['see_card' => '1', 'mal1' => '1', 'mal2' => '1', 'mal3' => '1', 'mal4' => '1']),
            // 20 mois (≥ 18 : VAP 4 dû à Tchaourou) : VAP 1 et 2 au rappel seulement.
            $child('2025-02-10', ['see_card' => '0', 'rtss_rec' => '1', 'rtss1_rec' => '1', 'rtss2_rec' => '1', 'rtss3_rec' => '0']),
            // 14 mois (VAP 4 pas encore dû) : aucune dose.
            $child('2025-08-10', ['see_card' => '1', 'mal1' => '0', 'mal2' => '0', 'mal3' => '0', 'mal4' => '0']),
        ]]]]], []), ['source_type' => 'demo']);
    }

    public function test_coverage_by_dose_with_card_then_recall(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);

        $this->withoutVite()->actingAs($admin)->get(route('coverage.vap'))->assertOk()->assertSee('Couverture du vaccin antipaludique (VAP 1 à 4)');

        $data = $this->actingAs($admin)->getJson(route('coverage.vap.data'))->assertOk()->json();
        $doses = $data['kpis']['doses'];
        $this->assertSame(3, $data['kpis']['children']);
        $this->assertSame([3, 2, 1, 1], [$doses[1]['denominator'], $doses[1]['received'], $doses[1]['card'], $doses[1]['recall']]);
        $this->assertEquals(66.7, $doses[1]['pct']);
        $this->assertEquals(33.3, $doses[3]['pct']);
        // VAP 4 : seuls les enfants de 18 mois ou plus (Tchaourou) comptent.
        $this->assertSame([2, 1], [$doses[4]['denominator'], $doses[4]['received']]);
        $this->assertEquals(50.0, $data['kpis']['dropout_1_3']);
        $this->assertEquals(33.3, $data['kpis']['zero_dose']);
        $this->assertSame(['12–23 mois', '24–35 mois'], array_column($data['by_age'], 'label'));
        $this->assertSame('Filles', $data['by_sex'][0]['label']);

        $this->actingAs($admin)->get(route('coverage.vap.export'))->assertOk()->assertHeader('content-disposition');
        $this->assertSame('application/pdf', $this->actingAs($admin)->get(route('coverage.vap.pdf'))->assertOk()->headers->get('content-type'));
    }
}
