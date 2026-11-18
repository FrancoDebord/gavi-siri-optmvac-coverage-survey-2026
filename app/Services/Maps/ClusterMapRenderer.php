<?php

namespace App\Services\Maps;

use App\Models\Cluster;
use App\Support\Geo;
use App\Support\HouseholdCode;
use GdImage;
use Illuminate\Support\Collection;

/**
 * Carte d'une grappe en image PNG haute résolution (pour les PDF) : contours de la grappe et des sous-grappes,
 * ménages géolocalisés numérotés (vert : enquêté, orange : visité non enquêté, cerclé de rouge : hors contour),
 * échelle, nord et légende. Projection locale équirectangulaire (échelle exacte à l'échelle d'une grappe).
 */
class ClusterMapRenderer
{
    public const WIDTH = 1600;

    public const HEIGHT = 1100;

    private const LEGEND = 70;

    private string $font;

    private string $bold;

    public function __construct()
    {
        $this->font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $this->bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
    }

    /**
     * @param  Collection<int, object>  $households  latitude, longitude, household_code, consented
     * @return array{png: string, outside: array<int, int>} image et distance (m) au contour des ménages hors contour
     */
    public function render(Cluster $cluster, Collection $households): array
    {
        $shapes = [];
        foreach (self::rings($cluster->boundary) as $ring) {
            $shapes[] = ['ring' => $ring, 'kind' => 'cluster', 'label' => null];
        }
        foreach ($cluster->subClusters as $sub) {
            foreach (self::rings($sub->boundary) as $i => $ring) {
                $shapes[] = ['ring' => $ring, 'kind' => 'sub', 'label' => $i === 0 ? 'S'.$sub->number : null];
            }
        }
        $points = $households->filter(fn ($h) => $h->latitude !== null && $h->longitude !== null)->values();

        // Emprise : contours et points, marge de 8 %.
        $lngs = $lats = [];
        foreach ($shapes as $shape) {
            foreach ($shape['ring'] as [$lng, $lat]) {
                $lngs[] = $lng;
                $lats[] = $lat;
            }
        }
        foreach ($points as $p) {
            $lngs[] = $p->longitude;
            $lats[] = $p->latitude;
        }
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($img, true);
        $c = $this->palette($img);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $c['bg']);
        if (! $lngs) {
            $this->text($img, 22, self::WIDTH / 2 - 300, self::HEIGHT / 2, $c['muted'], 'Ni contour ni point GPS pour cette grappe');

            return ['png' => $this->png($img), 'outside' => []];
        }

        $midLat = (min($lats) + max($lats)) / 2;
        $kx = 111320 * cos(deg2rad($midLat));
        $ky = 110540;
        $spanX = max((max($lngs) - min($lngs)) * $kx, 60);
        $spanY = max((max($lats) - min($lats)) * $ky, 60);
        $drawW = self::WIDTH - 80;
        $drawH = self::HEIGHT - self::LEGEND - 80;
        $scale = min($drawW / ($spanX * 1.16), $drawH / ($spanY * 1.16)); // pixels par mètre
        $cx = (min($lngs) + max($lngs)) / 2;
        $cy = (min($lats) + max($lats)) / 2;
        $project = fn (float $lng, float $lat) => [
            (int) round(self::WIDTH / 2 + ($lng - $cx) * $kx * $scale),
            (int) round(40 + $drawH / 2 - ($lat - $cy) * $ky * $scale),
        ];

        // Contours : grappe (bleu, remplie), sous-grappes (orange).
        foreach (['cluster', 'sub'] as $kind) {
            foreach (array_filter($shapes, fn ($s) => $s['kind'] === $kind) as $shape) {
                $xy = [];
                foreach ($shape['ring'] as [$lng, $lat]) {
                    array_push($xy, ...$project($lng, $lat));
                }
                if (count($xy) < 6) {
                    continue;
                }
                if ($kind === 'cluster') {
                    imagefilledpolygon($img, $xy, $c['fill']);
                }
                imagesetthickness($img, $kind === 'cluster' ? 4 : 3);
                imagepolygon($img, $xy, $kind === 'cluster' ? $c['cluster'] : $c['sub']);
                if ($shape['label']) {
                    // Étiquette au sommet le plus au nord, à l'intérieur, pour ne pas masquer les ménages.
                    $top = 1;
                    for ($k = 1; $k < count($xy); $k += 2) {
                        $top = $xy[$k] < $xy[$top] ? $k : $top;
                    }
                    $this->text($img, 24, $xy[$top - 1] - 15, $xy[$top] + 36, $c['sub'], $shape['label'], true, $c['white']);
                }
            }
        }
        imagesetthickness($img, 1);

        // Ménages : numéro d'ordre de l'identifiant, couleur selon l'enquête, rouge si hors contour.
        $outside = [];
        foreach ($points as $p) {
            [$x, $y] = $project($p->longitude, $p->latitude);
            $position = $cluster->boundary ? Geo::locate($p->latitude, $p->longitude, $cluster->boundary) : null;
            $isOut = $position && ! $position['inside'];
            if ($isOut) {
                $outside[$p->id] = $position['distance_m'];
                imagefilledellipse($img, $x, $y, 34, 34, $c['out']);
            }
            imagefilledellipse($img, $x, $y, 22, 22, $c['white']);
            imagefilledellipse($img, $x, $y, 17, 17, $p->consented ? $c['ok'] : $c['visited']);
            $number = HouseholdCode::parse($p->household_code)['order'] ?? null;
            $label = $number !== null ? str_pad((string) $number, 3, '0', STR_PAD_LEFT) : (string) $p->household_code;
            $this->text($img, 15, $x + 14, $y - 10, $c['ink'], $label, true, $c['white']);
        }

        $this->scaleBar($img, $c, $scale);
        $this->north($img, $c);
        $this->legend($img, $c);

        return ['png' => $this->png($img), 'outside' => $outside];
    }

    /** @return list<list<array{0: float, 1: float}>> */
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
        foreach (array_filter($geometries) as $g) {
            if (($g['type'] ?? null) === 'Polygon') {
                $rings[] = $g['coordinates'][0] ?? [];
            } elseif (($g['type'] ?? null) === 'MultiPolygon') {
                foreach ($g['coordinates'] ?? [] as $polygon) {
                    $rings[] = $polygon[0] ?? [];
                }
            }
        }

        return array_values(array_filter($rings, fn ($r) => count($r) >= 3));
    }

    private function palette(GdImage $img): array
    {
        return [
            'bg' => imagecolorallocate($img, 248, 247, 244),
            'white' => imagecolorallocate($img, 255, 255, 255),
            'fill' => imagecolorallocatealpha($img, 30, 110, 200, 105),
            'cluster' => imagecolorallocate($img, 20, 80, 160),
            'sub' => imagecolorallocate($img, 220, 110, 0),
            'ok' => imagecolorallocate($img, 25, 135, 84),
            'visited' => imagecolorallocate($img, 240, 160, 0),
            'out' => imagecolorallocate($img, 200, 0, 0),
            'ink' => imagecolorallocate($img, 20, 20, 20),
            'muted' => imagecolorallocate($img, 110, 110, 110),
        ];
    }

    private function text(GdImage $img, int $size, int $x, int $y, int $color, string $text, bool $bold = false, ?int $halo = null): void
    {
        $font = $bold ? $this->bold : $this->font;
        if ($halo !== null) {
            foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-1, -1], [1, 1], [-1, 1], [1, -1]] as [$dx, $dy]) {
                imagettftext($img, $size, 0, $x + $dx, $y + $dy, $halo, $font, $text);
            }
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    private function scaleBar(GdImage $img, array $c, float $scale): void
    {
        $target = 250 / $scale; // mètres pour ~250 px
        $steps = [10, 20, 50, 100, 200, 250, 500, 1000, 2000, 5000];
        $meters = collect($steps)->last(fn ($s) => $s <= $target) ?? 10;
        $px = (int) round($meters * $scale);
        $x = 40;
        $y = self::HEIGHT - self::LEGEND - 20;
        imagefilledrectangle($img, $x, $y, $x + $px, $y + 10, $c['ink']);
        imagefilledrectangle($img, $x + (int) ($px / 2), $y + 2, $x + $px - 2, $y + 8, $c['white']);
        $this->text($img, 16, $x, $y - 10, $c['ink'], $meters >= 1000 ? ($meters / 1000).' km' : $meters.' m', true, $c['white']);
    }

    private function north(GdImage $img, array $c): void
    {
        $x = self::WIDTH - 60;
        imagefilledpolygon($img, [$x, 30, $x - 16, 75, $x, 65, $x + 16, 75], $c['ink']);
        $this->text($img, 20, $x - 9, 100, $c['ink'], 'N', true);
    }

    private function legend(GdImage $img, array $c): void
    {
        $y = self::HEIGHT - self::LEGEND + 10;
        imagefilledrectangle($img, 0, $y - 10, self::WIDTH, self::HEIGHT, $c['white']);
        $x = 40;
        $item = function (callable $draw, string $label) use (&$x, $y, $img, $c) {
            $draw($x + 12, $y + 22);
            $this->text($img, 16, $x + 32, $y + 29, $c['ink'], $label);
            $box = imagettfbbox(16, 0, $this->font, $label);
            $x += 60 + abs($box[2] - $box[0]);
        };
        $item(fn ($cx, $cy) => imagefilledellipse($img, $cx, $cy, 17, 17, $c['ok']), 'Ménage enquêté');
        $item(fn ($cx, $cy) => imagefilledellipse($img, $cx, $cy, 17, 17, $c['visited']), 'Visité, non enquêté');
        $item(function ($cx, $cy) use ($img, $c) {
            imagefilledellipse($img, $cx, $cy, 30, 30, $c['out']);
            imagefilledellipse($img, $cx, $cy, 17, 17, $c['ok']);
        }, 'Hors du contour');
        $item(function ($cx, $cy) use ($img, $c) {
            imagesetthickness($img, 4);
            imagerectangle($img, $cx - 12, $cy - 9, $cx + 12, $cy + 9, $c['cluster']);
            imagesetthickness($img, 1);
        }, 'Contour de la grappe');
        $item(function ($cx, $cy) use ($img, $c) {
            imagesetthickness($img, 3);
            imagerectangle($img, $cx - 12, $cy - 9, $cx + 12, $cy + 9, $c['sub']);
            imagesetthickness($img, 1);
        }, 'Sous-grappe');
    }

    private function png(GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
