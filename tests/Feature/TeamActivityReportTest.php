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

        // Binôme 27-48 : le 03/11 (deux agents) et le 05/11 ; rien le 04/11. Autre binôme : le 04/11.
        $this->household($a, '2026-11-03', true, [[30, true], [50, false]]);
        $this->household($b, '2026-11-03', false, []);
        $this->household($a, '2026-11-05', true, [[14, true]]);
        $this->household($c, '2026-11-04', true, [[20, true]]);
        // Ménage exclu : ignoré.
        $this->household($a, '2026-11-05', true, [[18, true]], excluded: true);
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

    public function test_daily_rows_with_merged_off_days_and_totals(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);

        $page = $this->actingAs($admin)->get(route('team-activity.index', ['team_id' => $this->team->id]))->assertOk()
            ->assertSee('Du 03/11/2026 au 05/11/2026')
            ->assertSee('colspan="5"', false)
            ->assertSee('Préparation du terrain, contrôle qualité des données, correction des erreurs');

        $report = $page->viewData('reports')->sole();
        $days = collect($report['days'])->mapWithKeys(fn ($d) => [$d['date']->toDateString() => $d['values']]);
        $this->assertSame(['visited' => 2, 'surveyed' => 1, 'mothers' => 1, 'under5' => 2, 'eligible' => 1], $days['2026-11-03']);
        $this->assertNull($days['2026-11-04']);
        $this->assertSame(['visited' => 1, 'surveyed' => 1, 'mothers' => 1, 'under5' => 1, 'eligible' => 1], $days['2026-11-05']);
        $this->assertSame(['visited' => 3, 'surveyed' => 2, 'mothers' => 2, 'under5' => 3, 'eligible' => 2], $report['totals']);
        $this->assertSame(2, $report['active_days']);

        // Tous les binômes : un tableau par binôme.
        $this->assertCount(2, $this->actingAs($admin)->get(route('team-activity.index'))->assertOk()->viewData('reports'));
    }

    public function test_pdf_for_one_team_or_all_and_access_is_restricted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);

        $one = $this->actingAs($admin)->get(route('team-activity.pdf', ['team_id' => $this->team->id]))->assertOk();
        $this->assertStringContainsString('rapport-activite-binome-27-48-20261103-20261105.pdf', $one->headers->get('content-disposition'));
        $all = $this->actingAs($admin)->get(route('team-activity.pdf', ['from' => '2026-11-01', 'to' => '2026-11-06']))->assertOk();
        $this->assertStringContainsString('rapport-activite-binomes-20261101-20261106.pdf', $all->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $all->getContent());

        $team = User::factory()->create(['team_id' => $this->team->id]);
        $team->assignRole(User::ROLE_TEAM);
        $this->actingAs($team)->get(route('team-activity.index'))->assertRedirect();
        $this->actingAs($team)->get(route('team-activity.pdf'))->assertRedirect();
    }
}
