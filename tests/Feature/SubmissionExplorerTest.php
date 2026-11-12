<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        $processor = new SubmissionProcessor;
        foreach ([
            // Sébou, grappe 01 : un ménage dans le contour (sous-grappe 2), un dehors (~111 m au nord), un sans GPS.
            ['uuid:1', 'TCH-SEB-01-S2-001', '1', '1', '5', '66', '46', '0', '9.305 2.485 0 4'],
            ['uuid:2', 'TCH-SEB-01-002', '1', '1', '5', '66', '47', '3', '9.311 2.485 0 4'],
            ['uuid:3', 'TCH-SEB-01-003', '1', '1', '5', '66', '47', '0', null],
            ['uuid:4', 'DAG-ASS-01-001', '2', '2', '13', '57', '33', '0', '7.57 2.28 0 4'],
        ] as [$uuid, $code, $zone, $commune, $arr, $village, $collector, $status, $gps]) {
            $processor->process(new NormalizedSubmission($uuid, array_filter([
                'today' => now()->toDateString(), 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => $collector,
                'household' => $code, 'household_survey_status' => $status, 'current_location' => $gps,
            ], fn ($v) => $v !== null), [], []), ['source_type' => 'demo']);
        }

        $square = fn (float $lat, float $lng, float $size) => ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['hameau' => null, 'superficie_ha' => 1],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]]]]]]];
        $cluster = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $cluster->update(['boundary' => $square(9.30, 2.48, 0.01), 'boundary_file' => 'TCH-SEB-01-06-11-2026.csv', 'boundary_date' => '2026-11-06']);
        $cluster->subClusters()->create(['number' => 2, 'boundary' => $square(9.30, 2.48, 0.006)]);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_pi_explores_submissions_with_contours(): void
    {
        $pi = $this->user(User::ROLE_PI);

        $page = $this->actingAs($pi)->get(route('submissions.index'))->assertOk()->assertSee('TCH-SEB-01-002')->assertSee('DAG-ASS-01-001');
        $this->assertSame(4, $page->viewData('households')->total());
        $this->assertCount(3, $page->viewData('mapData')['points'], 'ménages géolocalisés');
        $this->assertSame(['TCH-SEB-01'], array_column($page->viewData('mapData')['boundaries'], 'code'));
        $this->assertSame([2], array_column($page->viewData('mapData')['boundaries'][0]['sub_clusters'], 'number'));

        $positions = $page->viewData('positions');
        $inside = Household::where('household_code', 'TCH-SEB-01-S2-001')->value('id');
        $outside = Household::where('household_code', 'TCH-SEB-01-002')->value('id');
        $this->assertTrue($positions[$inside]['cluster']['inside']);
        $this->assertTrue($positions[$inside]['sub_cluster']['inside']);
        $this->assertFalse($positions[$outside]['cluster']['inside']);
        $this->assertEqualsWithDelta(111, $positions[$outside]['cluster']['distance_m'], 3);
        $page->assertSee('hors grappe · 111 m', false)->assertSee('sans GPS');

        // Filtres : binôme, statut, zone.
        $team = Team::where('code', '46-47')->value('id');
        $this->assertSame(3, $this->actingAs($pi)->get(route('submissions.index', ['team_id' => $team]))->viewData('households')->total());
        $this->assertSame(1, $this->actingAs($pi)->get(route('submissions.index', ['statut' => 'sans_gps']))->viewData('households')->total());
        $this->assertSame(1, $this->actingAs($pi)->get(route('submissions.index', ['health_zone_id' => HealthZone::where('code', '2')->value('id')]))->viewData('households')->total());

        $this->actingAs($pi)->get(route('investigator.index'))->assertSee(route('submissions.index'));
    }

    public function test_household_page_shows_its_position_on_the_contours(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $household = Household::where('household_code', 'TCH-SEB-01-S2-001')->firstOrFail();

        $page = $this->actingAs($pi)->get(route('households.show', $household))->assertOk()
            ->assertSee('dans la grappe')->assertSee('dans la sous-grappe 2')->assertSee('household-map');
        $map = $page->viewData('mapData');
        $this->assertSame('TCH-SEB-01-S2-001', $map['household']['code']);
        $this->assertCount(1, $map['neighbours'], 'l\'autre ménage géolocalisé de la grappe');
        $this->assertSame('TCH-SEB-01', $map['boundaries'][0]['code']);

        $this->actingAs($pi)->get(route('households.show', Household::where('household_code', 'TCH-SEB-01-002')->firstOrFail()))->assertSee('hors grappe');
        $this->actingAs($pi)->get(route('households.show', Household::where('household_code', 'TCH-SEB-01-003')->firstOrFail()))->assertSee('Pas de coordonnées GPS');
    }

    public function test_supervisor_is_limited_to_their_zone(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '2')->value('id')]);

        $page = $this->actingAs($supervisor)->get(route('submissions.index', ['health_zone_id' => HealthZone::where('code', '1')->value('id')]))->assertOk();
        $this->assertSame(['DAG-ASS-01-001'], collect($page->viewData('households')->items())->pluck('household_code')->all());
        $this->assertSame([], $page->viewData('mapData')['boundaries']);

        $team = $this->user(User::ROLE_TEAM, ['team_id' => Team::where('code', '46-47')->value('id')]);
        $this->actingAs($team)->get(route('submissions.index'))->assertRedirect(route('team.space'));
    }
}
