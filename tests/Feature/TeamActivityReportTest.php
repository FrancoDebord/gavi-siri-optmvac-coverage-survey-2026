<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Child;
use App\Models\Collector;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Team $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $zone = HealthZone::create(['code' => '1', 'name' => 'Tchaourou']);
        $this->team = Team::create(['code' => '27-48', 'name' => 'Akinlabi & Alagbe', 'health_zone_id' => $zone->id]);
        $this->other = Team::create(['code' => '33-43', 'name' => 'Zinsou & Dossou', 'health_zone_id' => $zone->id]);
        $a = Collector::create(['code' => '27', 'name' => 'Akinlabi Rachidi', 'team_id' => $this->team->id, 'health_zone_id' => $zone->id, 'is_active' => true]);
        $b = Collector::create(['code' => '48', 'name' => 'Alagbe Kafayath', 'team_id' => $this->team->id, 'health_zone_id' => $zone->id, 'is_active' => true]);
        $c = Collector::create(['code' => '33', 'name' => 'Zinsou Paul', 'team_id' => $this->other->id, 'health_zone_id' => $zone->id, 'is_active' => true]);

        // Collecte à partir du 06/11 (semaine 1 : 06–12/11, semaine 2 : 13–19/11).
        // Binôme 27-48 : le 06/11 (deux agents), le 08/11, puis le 13/11 ; rien les autres jours. Autre binôme : le 07/11.
        $this->household($a, '2026-11-06', true, [[30, true], [50, false]]);
        $this->household($b, '2026-11-06', false, []);
        $this->household($a, '2026-11-08', true, [[14, true]]);
        $this->household($b, '2026-11-13', true, [[24, true], [6, false]]);
        $this->household($c, '2026-11-07', true, [[20, true]]);
        // Ménage exclu, et ménage d'avant le début de la collecte (essai) : ignorés.
        $this->household($a, '2026-11-08', true, [[18, true]], excluded: true);
        $this->household($a, '2026-11-03', true, [[18, true]]);
    }

    /** @param list<array{0: int, 1: bool}> $children âge en mois, éligible */
    private function household(Collector $collector, string $date, bool $consented, array $children, bool $excluded = false): void
    {
        $household = Household::create([
            'submission_uuid' => (string) Str::uuid(), 'collector_id' => $collector->id, 'health_zone_id' => $collector->health_zone_id,
            'interview_date' => $date, 'consented' => $consented, 'survey_status' => $consented ? null : '3',
            'data' => [], 'raw_data' => [], 'excluded_at' => $excluded ? now() : null,
        ]);
        if (! $consented) {
            return;
        }
        $caregiver = Caregiver::create(['household_id' => $household->id, 'position' => 1, 'consented' => true, 'data' => []]);
        foreach ($children as $i => [$months, $eligible]) {
            Child::create(['household_id' => $household->id, 'caregiver_id' => $caregiver->id, 'position' => $i + 1, 'age_months' => $months, 'eligible' => $eligible, 'data' => []]);
        }
    }

    public function test_weeks_with_subtotals_merged_off_days_and_grand_total(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);

        $page = $this->actingAs($admin)->get(route('team-activity.index', ['team_id' => $this->team->id]))->assertOk()
            ->assertSee('Du 06/11/2026 au 13/11/2026')
            ->assertSee('Semaine 1 · du 06/11 au 12/11/2026')->assertSee('Semaine 2 · du 13/11 au 13/11/2026')
            ->assertSee('Sous-total semaine 1')->assertSee('Total général')
            ->assertSee('colspan="5"', false)
            ->assertSee('Préparation du terrain, contrôle qualité des données, correction des erreurs');

        $report = $page->viewData('reports')->sole();
        [$week1, $week2] = $report['weeks'];
        $this->assertSame([1, 7, 2], [$week1['number'], count($week1['days']), $week1['active_days']]);
        $days = collect($week1['days'])->mapWithKeys(fn ($d) => [$d['date']->toDateString() => $d['values']]);
        $this->assertSame(['visited' => 2, 'surveyed' => 1, 'mothers' => 1, 'under5' => 2, 'eligible' => 1], $days['2026-11-06']);
        $this->assertNull($days['2026-11-07']);
        $this->assertSame(['visited' => 3, 'surveyed' => 2, 'mothers' => 2, 'under5' => 3, 'eligible' => 2], $week1['totals']);
        $this->assertSame([2, 1], [$week2['number'], count($week2['days'])]);
        $this->assertSame(['visited' => 1, 'surveyed' => 1, 'mothers' => 1, 'under5' => 2, 'eligible' => 1], $week2['totals']);
        $this->assertSame(['visited' => 4, 'surveyed' => 3, 'mothers' => 3, 'under5' => 5, 'eligible' => 3], $report['totals']);
        $this->assertSame(3, $report['active_days']);

        // Pas avant le début de la collecte, même si demandé.
        $early = $this->actingAs($admin)->get(route('team-activity.index', ['team_id' => $this->team->id, 'from' => '2026-11-01']))->viewData('reports')->sole();
        $this->assertSame('2026-11-06', $early['weeks'][0]['from']->toDateString());

        // Tous les binômes : un tableau par binôme.
        $this->assertCount(2, $this->actingAs($admin)->get(route('team-activity.index'))->assertOk()->viewData('reports'));
    }

    public function test_team_space_shows_its_own_activity_report_and_pdf(): void
    {
        $account = User::factory()->create(['team_id' => $this->team->id]);
        $account->assignRole(User::ROLE_TEAM);

        $page = $this->actingAs($account)->get(route('team.activity'))->assertOk()
            ->assertSee('Rapport d\'activité du binôme', false)->assertSee('Mon rapport d\'activité', false)
            ->assertSee('Semaine 1 · du 06/11 au 12/11/2026')->assertSee('Total général');
        $this->assertSame($this->team->id, $page->viewData('report')['team']->id);
        $this->assertSame(['visited' => 4, 'surveyed' => 3, 'mothers' => 3, 'under5' => 5, 'eligible' => 3], $page->viewData('report')['totals']);
        // Le compte binôme ne choisit pas un autre binôme : toujours le sien.
        $this->assertSame($this->team->id, $this->actingAs($account)->get(route('team.activity', ['team_id' => $this->other->id]))->viewData('report')['team']->id);

        $pdf = $this->actingAs($account)->get(route('team.activity.pdf', ['from' => '2026-11-06', 'to' => '2026-11-12']))->assertOk();
        $this->assertStringContainsString('rapport-activite-binome-27-48-20261106-20261112.pdf', $pdf->headers->get('content-disposition'));

        // Équipe centrale : rapport du binôme choisi.
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->assertSame($this->other->id, $this->actingAs($admin)->get(route('team.activity', ['team_id' => $this->other->id]))->assertOk()->viewData('report')['team']->id);
    }

    public function test_pdf_for_one_team_or_all_and_access_is_restricted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);

        $one = $this->actingAs($admin)->get(route('team-activity.pdf', ['team_id' => $this->team->id]))->assertOk();
        $this->assertStringContainsString('rapport-activite-binome-27-48-20261106-20261113.pdf', $one->headers->get('content-disposition'));
        $all = $this->actingAs($admin)->get(route('team-activity.pdf', ['from' => '2026-11-06', 'to' => '2026-11-20']))->assertOk();
        $this->assertStringContainsString('rapport-activite-binomes-20261106-20261120.pdf', $all->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $all->getContent());

        $team = User::factory()->create(['team_id' => $this->team->id]);
        $team->assignRole(User::ROLE_TEAM);
        $this->actingAs($team)->get(route('team-activity.index'))->assertRedirect();
        $this->actingAs($team)->get(route('team-activity.pdf'))->assertRedirect();
    }
}
