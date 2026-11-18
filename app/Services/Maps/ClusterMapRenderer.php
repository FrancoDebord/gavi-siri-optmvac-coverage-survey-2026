<?php

namespace App\Services\Maps;

use App\Models\Cluster;
use App\Support\Geo;
use GdImage;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Carte d'une grappe sur fond satellite (tuiles Esri World Imagery, mises en cache), en PNG haute résolution pour
 * les PDF : contours de la grappe et des sous-grappes, ménages visités en simples points (vert : enquêté, orange :
 * visité non enquêté, cerclé de rouge : hors contour), échelle, nord, légende et attribution. Zoom le plus fort qui
 * contient la grappe et ses ménages, pour repérer d'un coup d'œil les ménages restant à visiter.
 */
class ClusterMapRenderer
{
    public const WIDTH = 2000;

    public const HEIGHT = 1300;

    public const TILE_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

    public const ATTRIBUTION = 'Imagerie © Esri, Maxar, Earthstar Geographics';

    private const LEGEND = 70;

    private const MAX_ZOOM = 18;

    private string $font;

    private string $bold;

    public function __construct()
    {
        $this->font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $this->bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
    }

    /**
     * @param  Collection<int, object>  $households  latitude, longitude, consented
     * @return array{png: string, outside: int, tiles: bool} image, ménages hors contour, fond satellite disponible
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

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($img, true);
        $c = $this->palette($img);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $c['ground']);

        $coords = [];
        foreach ($shapes as $shape) {
            array_push($coords, ...$shape['ring']);
        }
        foreach ($points as $p) {
            $coords[] = [$p->longitude, $p->latitude];
        }
        if (! $coords) {
            $this->text($img, 26, self::WIDTH / 2 - 380, self::HEIGHT / 2, $c['white'], 'Ni contour ni point GPS pour cette grappe', true);

            return ['png' => $this->png($img), 'outside' => 0, 'tiles' => false];
        }

        // Zoom le plus fort qui contient tout (marge de 6 %), centré sur l'emprise.
        $lngs = array_column($coords, 0);
        $lats = array_column($coords, 1);
        $mapH = self::HEIGHT - self::LEGEND;
        $zoom = self::MAX_ZOOM;
        for (; $zoom > 3; $zoom--) {
            [$x1, $y1] = self::pixel(min($lngs), max($lats), $zoom);
            [$x2, $y2] = self::pixel(max($lngs), min($lats), $zoom);
            if (($x2 - $x1) <= self::WIDTH * 0.88 && ($y2 - $y1) <= $mapH * 0.88) {
                break;
            }
        }
        [$x1, $y1] = self::pixel(min($lngs), max($lats), $zoom);
        [$x2, $y2] = self::pixel(max($lngs), min($lats), $zoom);
        $originX = ($x1 + $x2) / 2 - self::WIDTH / 2;
        $originY = ($y1 + $y2) / 2 - $mapH / 2;
        $project = function (float $lng, float $lat) use ($zoom, $originX, $originY) {
            [$x, $y] = self::pixel($lng, $lat, $zoom);

            return [(int) round($x - $originX), (int) round($y - $originY)];
        };

        $tiles = $this->drawTiles($img, $zoom, $originX, $originY, $mapH);

        // Contours : grappe en jaune, sous-grappes en cyan (lisibles sur l'imagerie).
        foreach (['cluster', 'sub'] as $kind) {
            foreach (array_filter($shapes, fn ($s) => $s['kind'] === $kind) as $shape) {
                $xy = [];
                foreach ($shape['ring'] as [$lng, $lat]) {
                    array_push($xy, ...$project($lng, $lat));
                }
                if (count($xy) < 6) {
                    continue;
                }
                imagesetthickness($img, $kind === 'cluster' ? 6 : 4);
                imagepolygon($img, $xy, $kind === 'cluster' ? $c['cluster'] : $c['sub']);
                if ($shape['label']) {
                    $top = 1;
                    for ($k = 1; $k < count($xy); $k += 2) {
                        $top = $xy[$k] < $xy[$top] ? $k : $top;
                    }
                    $this->text($img, 26, $xy[$top - 1] - 18, $xy[$top] + 40, $c['sub'], $shape['label'], true, $c['black']);
                }
            }
        }
        imagesetthickness($img, 1);

        // Ménages visités : simples points, sans nom.
        $outside = 0;
        foreach ($points as $p) {
            [$x, $y] = $project($p->longitude, $p->latitude);
            $position = $cluster->boundary ? Geo::locate($p->latitude, $p->longitude, $cluster->boundary) : null;
            if ($position && ! $position['inside']) {
                $outside++;
                imagefilledellipse($img, $x, $y, 40, 40, $c['out']);
            }
            imagefilledellipse($img, $x, $y, 26, 26, $c['white']);
            imagefilledellipse($img, $x, $y, 19, 19, $p->consented ? $c['ok'] : $c['visited']);
        }

        $this->scaleBar($img, $c, $zoom, ($y1 + $y2) / 2, $mapH);
        $this->north($img, $c);
        $this->legend($img, $c, $tiles);

        return ['png' => $this->png($img), 'outside' => $outside, 'tiles' => $tiles];
    }

    /** Coordonnées pixel Web Mercator au zoom donné. */
    private static function pixel(float $lng, float $lat, int $zoom): array
    {
        $size = 256 * (2 ** $zoom);
        $sin = sin(deg2rad(max(-85.0, min(85.0, $lat))));

        return [($lng + 180) / 360 * $size, (0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)) * $size];
    }

    /** Fond satellite : tuiles du cache, les manquantes téléchargées en parallèle. Faux si aucune n'est disponible. */
    private function drawTiles(GdImage $img, int $zoom, float $originX, float $originY, int $mapH): bool
    {
        $tx1 = (int) floor($originX / 256);
        $ty1 = (int) floor($originY / 256);
        $tx2 = (int) floor(($originX + self::WIDTH) / 256);
        $ty2 = (int) floor(($originY + $mapH) / 256);
        $wanted = [];
        for ($tx = $tx1; $tx <= $tx2; $tx++) {
            for ($ty = $ty1; $ty <= $ty2; $ty++) {
                $wanted["{$zoom}/{$tx}/{$ty}"] = [$tx, $ty];
            }
        }

        $dir = storage_path('app/tiles/esri');
        $missing = array_filter($wanted, fn ($key) => ! is_file("{$dir}/{$key}.jpg"), ARRAY_FILTER_USE_KEY);
        foreach (array_chunk($missing, 16, true) as $batch) {
            try {
                $responses = Http::pool(fn (Pool $pool) => collect($batch)->map(fn ($t, $key) => $pool->as($key)->timeout(20)
                    ->withHeaders(['User-Agent' => 'plateforme-enquete-couverture/1.0'])
                    ->get(strtr(self::TILE_URL, ['{z}' => $zoom, '{x}' => $t[0], '{y}' => $t[1]])))->all());
            } catch (Throwable) {
                continue;
            }
            foreach ($responses as $key => $response) {
                if ($response instanceof Response && $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                    File::ensureDirectoryExists(dirname("{$dir}/{$key}.jpg"));
                    file_put_contents("{$dir}/{$key}.jpg", $response->body());
                }
            }
        }

        $drawn = false;
        foreach ($wanted as $key => [$tx, $ty]) {
            $path = "{$dir}/{$key}.jpg";
            $tile = is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;
            if ($tile) {
                imagecopy($img, $tile, (int) round($tx * 256 - $originX), (int) round($ty * 256 - $originY), 0, 0, 256, 256);
                imagedestroy($tile);
                $drawn = true;
            }
        }

        return $drawn;
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
            'ground' => imagecolorallocate($img, 70, 78, 66),
            'white' => imagecolorallocate($img, 255, 255, 255),
            'black' => imagecolorallocate($img, 0, 0, 0),
            'cluster' => imagecolorallocate($img, 255, 214, 0),
            'sub' => imagecolorallocate($img, 0, 229, 255),
            'ok' => imagecolorallocate($img, 0, 200, 83),
            'visited' => imagecolorallocate($img, 255, 145, 0),
            'out' => imagecolorallocate($img, 230, 0, 0),
            'ink' => imagecolorallocate($img, 20, 20, 20),
        ];
    }

    private function text(GdImage $img, int $size, int $x, int $y, int $color, string $text, bool $bold = false, ?int $halo = null): void
    {
        $font = $bold ? $this->bold : $this->font;
        if ($halo !== null) {
            foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
                imagettftext($img, $size, 0, $x + $dx, $y + $dy, $halo, $font, $text);
            }
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    private function scaleBar(GdImage $img, array $c, int $zoom, float $centerY, int $mapH): void
    {
        $size = 256 * (2 ** $zoom);
        $lat = rad2deg(atan(sinh(M_PI * (1 - 2 * $centerY / $size))));
        $metersPerPixel = 156543.03392 * cos(deg2rad($lat)) / (2 ** $zoom);
        $meters = collect([10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000])->last(fn ($s) => $s / $metersPerPixel <= 320) ?? 10;
        $px = (int) round($meters / $metersPerPixel);
        $x = 40;
        $y = $mapH - 34;
        imagefilledrectangle($img, $x - 10, $y - 36, $x + $px + 14, $y + 22, $c['white']);
        imagefilledrectangle($img, $x, $y, $x + $px, $y + 12, $c['ink']);
        imagefilledrectangle($img, $x + (int) ($px / 2), $y + 2, $x + $px - 2, $y + 10, $c['white']);
        $this->text($img, 18, $x, $y - 10, $c['ink'], $meters >= 1000 ? ($meters / 1000).' km' : $meters.' m', true);
    }

    private function north(GdImage $img, array $c): void
    {
        $x = self::WIDTH - 60;
        imagefilledellipse($img, $x, 70, 90, 110, $c['white']);
        imagefilledpolygon($img, [$x, 30, $x - 17, 78, $x, 67, $x + 17, 78], $c['ink']);
        $this->text($img, 22, $x - 10, 108, $c['ink'], 'N', true);
    }

    private function legend(GdImage $img, array $c, bool $tiles): void
    {
        $y = self::HEIGHT - self::LEGEND;
        imagefilledrectangle($img, 0, $y, self::WIDTH, self::HEIGHT, $c['white']);
        $x = 40;
        $item = function (callable $draw, string $label) use (&$x, $y, $img, $c) {
            $draw($x + 12, $y + 35);
            $this->text($img, 17, $x + 34, $y + 42, $c['ink'], $label);
            $box = imagettfbbox(17, 0, $this->font, $label);
            $x += 70 + abs($box[2] - $box[0]);
        };
        $dot = fn ($color, $ring = false) => function ($cx, $cy) use ($img, $c, $color, $ring) {
            if ($ring) {
                imagefilledellipse($img, $cx, $cy, 32, 32, $c['out']);
            }
            imagefilledellipse($img, $cx, $cy, 22, 22, $c['ink']);
            imagefilledellipse($img, $cx, $cy, 17, 17, $c[$color]);
        };
        $line = fn ($color) => function ($cx, $cy) use ($img, $c, $color) {
            imagesetthickness($img, 5);
            imageline($img, $cx - 14, $cy, $cx + 14, $cy, $c[$color]);
            imagesetthickness($img, 1);
        };
        $item($dot('ok'), 'Ménage enquêté');
        $item($dot('visited'), 'Visité, non enquêté');
        $item($dot('ok', true), 'Hors du contour');
        $item($line('cluster'), 'Grappe');
        $item($line('sub'), 'Sous-grappe');
        $note = $tiles ? self::ATTRIBUTION : 'Fond satellite indisponible (connexion)';
        $box = imagettfbbox(14, 0, $this->font, $note);
        $this->text($img, 14, self::WIDTH - 30 - abs($box[2] - $box[0]), $y + 42, $c['ink'], $note);
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
