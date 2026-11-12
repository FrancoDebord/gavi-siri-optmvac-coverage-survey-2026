<?php

namespace App\Support;

/**
 * Position d'un point GPS par rapport à un contour de grappe (GeoJSON : FeatureCollection, Feature ou géométrie,
 * Polygon ou MultiPolygon ; un polygone par hameau). Distances en mètres par projection locale.
 */
final class Geo
{
    /**
     * @return array{inside: bool, distance_m: ?int}|null null si le contour ne contient aucun polygone
     */
    public static function locate(float $lat, float $lng, ?array $geojson): ?array
    {
        $rings = self::rings($geojson);
        if ($rings === []) {
            return null;
        }
        foreach ($rings as $ring) {
            if (self::inRing($lat, $lng, $ring)) {
                return ['inside' => true, 'distance_m' => 0];
            }
        }
        $distance = min(array_map(fn ($ring) => self::distanceToRing($lat, $lng, $ring), $rings));

        return ['inside' => false, 'distance_m' => (int) round($distance)];
    }

    /** @return list<list<array{0: float, 1: float}>> anneaux extérieurs [lng, lat] */
    private static function rings(?array $geojson): array
    {
        if (! $geojson) {
            return [];
        }
        $geometries = match ($geojson['type'] ?? null) {
            'FeatureCollection' => array_column($geojson['features'] ?? [], 'geometry'),
            'Feature' => [$geojson['geometry'] ?? null],
            default => [$geojson],
        };
        $rings = [];
        foreach (array_filter($geometries) as $geometry) {
            if (($geometry['type'] ?? null) === 'Polygon') {
                $rings[] = $geometry['coordinates'][0] ?? [];
            } elseif (($geometry['type'] ?? null) === 'MultiPolygon') {
                foreach ($geometry['coordinates'] ?? [] as $polygon) {
                    $rings[] = $polygon[0] ?? [];
                }
            }
        }

        return array_values(array_filter($rings, fn ($ring) => count($ring) >= 3));
    }

    private static function inRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** Distance (m) du point au bord le plus proche de l'anneau. */
    private static function distanceToRing(float $lat, float $lng, array $ring): float
    {
        $k = 111320.0;
        $cos = cos(deg2rad($lat));
        $p = [0.0, 0.0];
        if ($ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0]; // anneau non fermé : on ajoute le dernier côté
        }
        $xy = array_map(fn ($c) => [($c[0] - $lng) * $k * $cos, ($c[1] - $lat) * $k], $ring);
        $best = INF;
        for ($i = 0, $n = count($xy) - 1; $i < $n; $i++) {
            [$ax, $ay] = $xy[$i];
            [$bx, $by] = $xy[$i + 1];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $t = ($dx || $dy) ? max(0, min(1, (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / ($dx * $dx + $dy * $dy))) : 0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $best;
    }
}
