<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    /** Carré [lng, lat] de 0,01° (~1,1 km) de côté, coin sud-ouest en (9.30, 2.48). */
    private function square(float $lat = 9.30, float $lng = 2.48, bool $closed = true): array
    {
        $ring = [[$lng, $lat], [$lng + 0.01, $lat], [$lng + 0.01, $lat + 0.01], [$lng, $lat + 0.01]];
        if ($closed) {
            $ring[] = $ring[0];
        }

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    public function test_point_inside_and_outside_a_contour(): void
    {
        $collection = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => [], 'geometry' => $this->square()]]];

        $this->assertSame(['inside' => true, 'distance_m' => 0], Geo::locate(9.305, 2.485, $collection));

        // 0,001° de latitude au nord du bord supérieur : ~111 m.
        $outside = Geo::locate(9.311, 2.485, $collection);
        $this->assertFalse($outside['inside']);
        $this->assertEqualsWithDelta(111, $outside['distance_m'], 2);
    }

    public function test_hamlets_unclosed_rings_and_missing_contours(): void
    {
        // Deux hameaux : un point dans le second est dans la grappe.
        $hamlets = ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['hameau' => 1], 'geometry' => $this->square()],
            ['type' => 'Feature', 'properties' => ['hameau' => 2], 'geometry' => $this->square(9.40, 2.58)],
        ]];
        $this->assertTrue(Geo::locate(9.405, 2.585, $hamlets)['inside']);

        // Anneau non fermé : le dernier côté (ouest) compte pour la distance.
        $this->assertEqualsWithDelta(110, Geo::locate(9.305, 2.479, $this->square(closed: false))['distance_m'], 3);

        $this->assertNull(Geo::locate(9.3, 2.48, null));
        $this->assertNull(Geo::locate(9.3, 2.48, ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [2.48, 9.3]]]]]));
    }
}
