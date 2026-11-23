<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pages de couverture des antigènes du calendrier et de la CPS (carte, sinon rappel). */
class CoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);

        $child = fn (string $dob, array $data) => ['key' => null, 'data' => ['child_dob' => $dob, 'child_sex' => '0', 'receive_vaccine' => '1', 'child_sleep_last_night' => '1'] + $data, 'repeats' => []];
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:C', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46',
            'household' => 'TCH-SEB-01-040', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35', 'smc_cycles' => '4',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => '2', 'age_years' => '30'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [
            // 30 mois : BCG, VPO 3, Penta 1–3, RR 1 sur la carte ; CPS : carte présentée, cycles 1 à 4.
            $child('2024-04-10', ['see_card' => '1', 'bcg' => '1', 'opv3' => '1', 'Penta1' => '1', 'Penta2' => '1', 'Penta3' => '1', 'meas1' => '1',
                'child_got_smc' => '1', 'child_given_smc_card' => '1', 'child_still_have_card' => '1', 'treated_1' => '1', 'treated_2' => '1', 'treated_3' => '1', 'treated_4' => '1']),
            // 20 mois : Penta 1 au rappel seulement ; CPS : pas de carte, cycles 1 et 2 au rappel.
            $child('2025-02-10', ['see_card' => '0', 'penta_rec' => '1', 'penta1_rec' => '1', 'penta2_rec' => '0', 'penta3_rec' => '0',
                'child_got_smc' => '1', 'child_given_smc_card' => '0', 'treated_1_recall' => '1', 'treated_2_recall' => '1', 'treated_3_recall' => '0', 'treated_4_recall' => '0']),
        ]]]]], []), ['source_type' => 'demo']);
    }

    public function test_antigen_coverage_uses_the_card_then_the_recall(): void
    {
        $this->actingAs($this->admin)->get(route('coverage.show', 'antigenes'))->assertOk()->assertSee('Couverture vaccinale par antigène');
        $data = $this->actingAs($this->admin)->getJson(route('coverage.data', 'antigenes'))->assertOk()->json();
        $total = $data['total'];

        $this->assertSame(2, $total['children']);
        $this->assertSame([2, 2, 1, 1], [$total['doses']['penta1']['denominator'], $total['doses']['penta1']['received'], $total['doses']['penta1']['card'], $total['doses']['penta1']['recall']]);
        $this->assertEquals(50.0, $total['doses']['penta3']['pct']);
        $this->assertEquals(50.0, $total['fully_basic']);
        $this->assertEquals(0.0, $total['zero_dose']);
        $this->assertEquals(50.0, $total['dropout_penta']);
        // Dose jamais renseignée (question non posée) : écartée.
        $this->assertNotContains('ipv1', $data['collected']);
        $this->assertArrayNotHasKey('ipv1', $total['doses']);

        // Classeur : une feuille par niveau (zone ; zone et commune ; zone, commune et village ; âge et sexe).
        $file = $this->actingAs($this->admin)->get(route('coverage.export', 'antigenes'))->assertOk()->getFile()->getPathname();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $this->assertSame(['Zones', 'Zones et communes', 'Zones communes villages', 'Âge et sexe'], $book->getSheetNames());
        $villages = $book->getSheet(2)->toArray();
        $this->assertSame(['Zone sanitaire', 'Commune', 'Village', 'Enfants enrôlés'], array_slice($villages[0], 0, 4));
        $this->assertSame('Ensemble', $book->getSheet(0)->getCell('A2')->getValue());
        $pdf = $this->actingAs($this->admin)->get(route('coverage.pdf', ['type' => 'antigenes', 'health_zone_id' => 1]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_smc_coverage_by_cycle_with_card_then_recall(): void
    {
        $this->actingAs($this->admin)->get(route('coverage.show', 'cps'))->assertOk()->assertSee('chimioprévention du paludisme saisonnier');
        $total = $this->actingAs($this->admin)->getJson(route('coverage.data', 'cps'))->assertOk()->json('total');

        $this->assertSame([2, 2, 1, 1], [$total['cycles'][1]['denominator'], $total['cycles'][1]['received'], $total['cycles'][1]['card'], $total['cycles'][1]['recall']]);
        $this->assertEquals(50.0, $total['cycles'][3]['pct']);
        // Cycle 5 non prévu à Tchaourou (4 cycles) : pas de dénominateur.
        $this->assertSame(0, $total['cycles'][5]['denominator']);
        $this->assertEquals(100.0, $total['at_least_one']);
        $this->assertEquals(50.0, $total['all_cycles']);
        $this->assertSame([0, 0, 1, 0, 1, 0], $total['distribution']);
        $this->assertEquals(50.0, $total['card_present']);

        $this->actingAs($this->admin)->get(route('coverage.export', 'cps'))->assertOk()->assertHeader('content-disposition');
        $this->assertSame('application/pdf', $this->actingAs($this->admin)->get(route('coverage.pdf', 'cps'))->assertOk()->headers->get('content-type'));
    }
}
