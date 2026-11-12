<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use App\Models\Village;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterMapTest extends TestCase
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
            // Sébou : grappe 01 (binôme 46-47) et grappe 02 (binôme 38-39) ; Assiyo (Dagla) : binôme 33-43.
            ['uuid:1', 'TCH-SEB-01-S1-001', '1', '1', '5', '66', '46', '9.305 2.485 0 4'],
            ['uuid:2', 'TCH-SEB-02-001', '1', '1', '5', '66', '38', '9.322 2.495 0 4'],
            ['uuid:3', 'DAG-ASS-01-001', '2', '2', '13', '57', '33', '7.57 2.28 0 4'],
        ] as [$uuid, $code, $zone, $commune, $arr, $village, $collector, $gps]) {
            $processor->process(new NormalizedSubmission($uuid, [
                'today' => now()->toDateString(), 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => $collector,
                'household' => $code, 'household_survey_status' => '0', 'current_location' => $gps,
            ], [], []), ['source_type' => 'demo']);
        }
        $square = fn (float $lat, float $lng, float $size) => ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['superficie_ha' => 1],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]]]]]]];
        $seb1 = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $seb1->update(['boundary' => $square(9.30, 2.48, 0.01), 'boundary_date' => '2026-11-06']);
        $seb1->subClusters()->create(['number' => 1, 'boundary' => $square(9.30, 2.48, 0.006)]);
        Cluster::where('code', 'TCH-SEB-02')->firstOrFail()->update(['boundary' => $square(9.32, 2.49, 0.01)]);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_each_level_sees_its_own_villages(): void
    {
        $sebou = Village::where('short_code', 'SEB')->value('id');
        $assiyo = Village::where('short_code', 'ASS')->value('id');

        // Investigateur : tous les villages ; grappes 01 et 02 de Sébou et la sous-grappe 1 ensemble.
        $pi = $this->user(User::ROLE_PI);
        $page = $this->actingAs($pi)->get(route('maps.clusters', ['village_id' => $sebou]))->assertOk()->assertSee('Carte des grappes et sous-grappes');
        $this->assertEqualsCanonicalizing([$sebou, $assiyo], $page->viewData('villages')->pluck('id')->all());
        $this->assertSame([$assiyo => false, $sebou => true], $page->viewData('villages')->sortBy('id')->mapWithKeys(fn ($v) => [$v->id => $v->has_boundary])->all(),
            'village sans contour signalé, quel que soit l\'ordre');
        $page->assertSee('Assiyo (sans contour)');
        $this->assertSame(['TCH-SEB-01', 'TCH-SEB-02'], array_column($page->viewData('mapData')['boundaries'], 'code'));
        $this->assertSame([1], array_column($page->viewData('mapData')['boundaries'][0]['sub_clusters'], 'number'));
        $this->assertCount(2, $page->viewData('mapData')['points']);
        $this->assertSame($assiyo, $page->viewData('next')?->id ?? $page->viewData('previous')?->id, 'navigation vers l\'autre village');

        // Superviseur de Dagla : ses villages seulement, même s'il demande Sébou.
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '2')->value('id')]);
        $page = $this->actingAs($supervisor)->get(route('maps.clusters', ['village_id' => $sebou]))->assertOk();
        $this->assertSame([$assiyo], $page->viewData('villages')->pluck('id')->all());
        $this->assertSame($assiyo, $page->viewData('village')->id);

        // Binôme 46-47 : Sébou seulement ; il voit les contours des deux grappes du village mais seulement ses ménages.
        $team = $this->user(User::ROLE_TEAM, ['team_id' => Team::where('code', '46-47')->value('id')]);
        $page = $this->actingAs($team)->get(route('maps.clusters'))->assertOk()->assertSee('Carte de mes villages');
        $this->assertSame([$sebou], $page->viewData('villages')->pluck('id')->all());
        $this->assertSame(['TCH-SEB-01', 'TCH-SEB-02'], array_column($page->viewData('mapData')['boundaries'], 'code'));
        $this->assertSame(['TCH-SEB-01-S1-001'], array_column($page->viewData('mapData')['points'], 'code'));
        $this->actingAs($team)->get(route('maps.clusters', ['village_id' => $assiyo]))->assertOk()->assertViewHas('village', fn ($v) => $v->id === $sebou);
    }

    public function test_free_window_mode(): void
    {
        $reader = $this->user(User::ROLE_READER);

        $this->actingAs($reader)->get(route('maps.clusters'))->assertOk()->assertSee('Ouvrir dans une fenêtre')->assertDontSee('class="map-window"', false);
        $this->actingAs($reader)->get(route('maps.clusters', ['fenetre' => 1]))->assertOk()->assertSee('class="map-window"', false)->assertSee('Fermer la fenêtre');
    }
}
