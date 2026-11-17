<?php

namespace App\Services\Import;

use App\Models\Cluster;
use App\Models\HealthZone;
use App\Models\SubCluster;
use App\Models\Village;
use App\Support\DashboardCache;
use App\Support\HouseholdCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Contours des grappes et sous-grappes relevés au GPS (dossier settings/points_gps).
 *
 * Fichier : ZONE-VIL-NN[-Sx][-Hx]-JJ-MM-AAAA.csv (ex. DAG-ASS-01-06-11-2026.csv, TCH-BEE-03-S2-07-11-2026.csv,
 * TCH-KPR-06-H01-09-11-2026.csv pour un seul hameau de la grappe).
 * Points : même nomenclature + Pn (ordre du point) et, si la grappe a des hameaux, Hn (ex. DAG-ASS-01-H2-P3).
 * Les points de chaque hameau sont reliés dans l'ordre P1, P2… pour former un contour fermé ; si des côtés se croisent
 * (points relevés hors de l'ordre du tour), l'ordre est corrigé au minimum et signalé. Colonnes lues d'après l'en-tête
 * (Latitude/Longitude, ou E/N en UTM 31N à défaut), séparateur « , » ou « ; », latitude/longitude inversées corrigées. Le nom du fichier
 * fait foi pour la grappe, la sous-grappe et le hameau ; les incohérences des noms de points sont signalées.
 * Le contour d'une grappe (ou sous-grappe) réunit, hameau par hameau, le relevé le plus récent : un fichier par
 * hameau est donc possible. Un relevé sans hameau couvre toute la grappe : plus récent, il remplace les relevés
 * de hameaux ; plus ancien, il est remplacé par eux. Tous les points sont compilés dans un CSV unique.
 */
class ClusterBoundaryImporter
{
    public const REPORT_CACHE_KEY = 'gavi.boundaries.report';

    private const FILE_PATTERN = '/^([A-Z]{3})[-_ ]([A-Z]{3})[-_ ](\d{1,3})(?:[-_ ]?S\(?(\d{1,2})\)?)?(?:[-_ ]?H\(?(\d{1,3})\)?)?[-_ ](\d{1,2})[-_ ](\d{1,2})[-_ ](\d{4})$/';

    public const FILE_FORMAT = 'ZONE-VIL-NN[-Sx][-Hx]-JJ-MM-AAAA.csv';

    /** Emprise approximative du Bénin. */
    private const BOUNDS = ['lat' => [6.0, 12.5], 'lng' => [0.7, 3.9]];

    /** Seuils des contrôles de vraisemblance. */
    private const MIN_AREA_HA = 0.5;

    private const MAX_DISTANCE_KM = 30.0;

    public function directory(): string
    {
        return rtrim(config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR.'points_gps';
    }

    public function compilationPath(): string
    {
        return rtrim(config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR.'compilation_points_gps.csv';
    }

    /** Nom de fichier attendu : ZONE-VIL-NN[-Sx][-Hx]-JJ-MM-AAAA.csv, date valide. */
    public function isValidFileName(string $filename): bool
    {
        return (bool) preg_match('/\.csv$/i', $filename)
            && preg_match(self::FILE_PATTERN, mb_strtoupper(pathinfo($filename, PATHINFO_FILENAME)), $m)
            && checkdate((int) $m[7], (int) $m[6], (int) $m[8]);
    }

    /** Fichiers de relevés présents dans le dossier. */
    public function files(): array
    {
        return is_dir($this->directory()) ? array_map('basename', glob($this->directory().DIRECTORY_SEPARATOR.'*.csv')) : [];
    }

    /**
     * Tous les contours appliqués (grappes et sous-grappes) en un seul GeoJSON, avec village, numéro de grappe,
     * sous-grappe, hameau, superficie, date et fichier du relevé dans les propriétés.
     */
    public function geoJson(): array
    {
        $features = [];
        $add = function (Cluster $cluster, $source, ?int $subCluster) use (&$features) {
            foreach ($source->boundary['features'] ?? [] as $feature) {
                $feature['properties'] = [
                    'zone_sanitaire' => $cluster->healthZone?->name,
                    'village' => $cluster->village?->name,
                    'code_grappe' => $cluster->code,
                    'numero_grappe' => $cluster->number,
                    'sous_grappe' => $subCluster,
                    'hameau' => $feature['properties']['hameau'] ?? null,
                    'superficie_ha' => $feature['properties']['superficie_ha'] ?? null,
                    'date_releve' => $feature['properties']['date_releve'] ?? $source->boundary_date?->toDateString(),
                    'fichier' => $feature['properties']['fichier'] ?? $source->boundary_file,
                ];
                $features[] = $feature;
            }
        };
        $clusters = Cluster::with(['village', 'healthZone', 'subClusters' => fn ($q) => $q->whereNotNull('boundary')])
            ->where(fn ($q) => $q->whereNotNull('boundary')->orWhereHas('subClusters', fn ($s) => $s->whereNotNull('boundary')))
            ->orderBy('code')->get();
        foreach ($clusters as $cluster) {
            if ($cluster->boundary) {
                $add($cluster, $cluster, null);
            }
            foreach ($cluster->subClusters as $sub) {
                $add($cluster, $sub, $sub->number);
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** Archive ZIP : compilation des points, contours GeoJSON et fichiers de relevés d'origine. */
    public function exportArchive(): string
    {
        if (! is_file($this->compilationPath())) {
            $this->run();
        }
        $path = storage_path('app/private/exports/contours-grappes-'.now()->format('Ymd-His').'.zip');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($this->compilationPath(), 'compilation_points_gps.csv');
        $zip->addFromString('contours_grappes.geojson', json_encode($this->geoJson(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        foreach ($this->files() as $file) {
            $zip->addFile($this->directory().DIRECTORY_SEPARATOR.$file, 'releves/'.$file);
        }
        $zip->close();

        return $path;
    }

    /**
     * Importe tous les fichiers, applique les contours retenus et réécrit la compilation.
     *
     * $progress (facultatif) est appelé à chaque étape : (étape, faits, total, détail), étapes « lecture »
     * (un appel par fichier), « application » puis « compilation ». Il n'est jamais appelé dans la transaction,
     * pour que la progression soit lisible par une autre requête pendant le traitement.
     *
     * @param  (callable(string, int, int, ?string): void)|null  $progress
     * @return array{generated_at: string, files: list<array>, clusters: int, sub_clusters: int, points: int, compilation: string}
     */
    public function run(?callable $progress = null): array
    {
        $progress ??= static fn () => null;
        $paths = is_dir($this->directory()) ? glob($this->directory().DIRECTORY_SEPARATOR.'*.csv') : [];
        $total = count($paths);
        $files = collect($paths)
            ->map(function ($path, $i) use ($progress, $total) {
                $progress('lecture', $i, $total, basename($path));

                return $this->readFile($path);
            })
            ->sortBy([['key', 'asc'], ['date', 'desc'], ['file', 'desc']])->values();

        $progress('application', $total, $total, null);
        $zoneCentroids = $this->householdCentroids('health_zone_id');
        $villageCentroids = $this->householdCentroids('village_id');

        DB::transaction(function () use (&$files, $zoneCentroids, $villageCentroids) {
            // Grappe de chaque relevé lisible.
            $clusters = [];
            $files = $files->map(function (array $file) use (&$clusters, $zoneCentroids, $villageCentroids) {
                if ($file['error'] !== null) {
                    $file['status'] = 'erreur';

                    return $file;
                }
                $cluster = $this->cluster($file);
                if (! $cluster) {
                    $file['status'] = 'erreur';
                    $file['error'] = "Village « {$file['village_code']} » inconnu dans la zone {$file['zone_prefix']} (fichier des identifiants villages).";

                    return $file;
                }
                $clusters[$cluster->id] = $cluster;
                $file['cluster_id'] = $cluster->id;
                $file['village'] = $cluster->village?->name;
                $file['warnings'] = array_merge($file['warnings'], $this->distanceWarnings($file, $cluster, $zoneCentroids, $villageCentroids));

                return $file;
            });

            $files = $this->retain($files);

            // Contour de chaque grappe / sous-grappe : réunion des hameaux retenus.
            $applied = ['clusters' => [], 'sub_clusters' => []];
            foreach ($files->where('status', 'appliqué')->groupBy('key') as $group) {
                $first = $group->first();
                $cluster = $clusters[$first['cluster_id']];
                $features = $group->flatMap(fn ($f) => $f['geojson']['features'])
                    ->sortBy(fn ($feature) => $feature['properties']['hameau'] ?? 0)->values()->all();
                $values = [
                    'boundary' => ['type' => 'FeatureCollection', 'features' => $features],
                    'boundary_file' => mb_strimwidth($group->pluck('file')->sort()->implode(' + '), 0, 255, '…'),
                    'boundary_date' => $group->max('date'),
                ];
                if ($first['sub_cluster'] === null) {
                    $cluster->fill($values);
                    if ($cluster->latitude === null && $first['centroid']) {
                        $cluster->fill(['latitude' => $first['centroid'][0], 'longitude' => $first['centroid'][1]]);
                    }
                    $cluster->save();
                    $applied['clusters'][] = $cluster->id;
                } else {
                    $applied['sub_clusters'][] = SubCluster::updateOrCreate(['cluster_id' => $cluster->id, 'number' => $first['sub_cluster']], $values)->id;
                }
            }

            // Contours dont plus aucun relevé n'est retenu (fichier retiré du dossier) : retirés.
            Cluster::whereNotNull('boundary_file')->whereNotIn('id', $applied['clusters'])
                ->update(['boundary' => null, 'boundary_file' => null, 'boundary_date' => null]);
            SubCluster::whereNotNull('boundary_file')->whereNotIn('id', $applied['sub_clusters'])
                ->update(['boundary' => null, 'boundary_file' => null, 'boundary_date' => null]);
        });

        $progress('compilation', $total, $total, null);
        $this->writeCompilation($files);
        DashboardCache::bump();

        $report = [
            'generated_at' => now()->format('d/m/Y H:i'),
            'files' => $files->map(fn ($f) => array_diff_key($f, ['geojson' => true, 'points' => true]) + ['points_count' => count($f['points'])])->all(),
            'clusters' => $files->where('status', 'appliqué')->whereNull('sub_cluster')->pluck('key')->unique()->count(),
            'sub_clusters' => $files->where('status', 'appliqué')->whereNotNull('sub_cluster')->pluck('key')->unique()->count(),
            'points' => $files->sum(fn ($f) => count($f['points'])),
            'compilation' => $this->compilationPath(),
        ];
        Cache::forever(self::REPORT_CACHE_KEY, $report);

        return $report;
    }

    /**
     * Relevés retenus, hameau par hameau : pour chaque grappe (ou sous-grappe), chaque hameau prend le relevé le
     * plus récent qui le contient. Un relevé sans hameau couvre toute la grappe : plus récent, il remplace les
     * relevés de hameaux ; plus ancien, il est remplacé par eux. Fichiers triés du plus récent au plus ancien.
     */
    private function retain(Collection $files): Collection
    {
        $taken = []; // clé grappe → [hameau | '*' => fichier retenu]

        return $files->map(function (array $file) use (&$taken) {
            if ($file['status'] === 'erreur') {
                return $file;
            }
            $claims = $taken[$file['key']] ?? [];
            $hamlets = collect($file['geojson']['features'])->map(fn ($f) => (int) ($f['properties']['hameau'] ?? 0))->unique()->sort()->values();
            $whole = $hamlets->contains(0);
            $kept = isset($claims['*']) || ($whole && $claims !== []) ? collect() : $hamlets->reject(fn ($h) => isset($claims[$h]));

            if ($kept->isEmpty()) {
                $file['status'] = 'remplacé';
                $file['warnings'][] = 'Relevé plus récent utilisé : '.collect($claims)->unique()->sort()->implode(', ').'.';

                return $file;
            }
            foreach ($hamlets->diff($kept) as $h) {
                $file['warnings'][] = "Hameau H{$h} : relevé plus récent utilisé ({$claims[$h]}).";
            }
            $file['status'] = 'appliqué';
            $file['geojson']['features'] = collect($file['geojson']['features'])
                ->filter(fn ($f) => $kept->contains((int) ($f['properties']['hameau'] ?? 0)))
                ->map(function ($f) use ($file) {
                    $f['properties'] += ['date_releve' => $file['date'], 'fichier' => $file['file']];

                    return $f;
                })->values()->all();
            foreach ($whole ? ['*'] : $kept as $h) {
                $taken[$file['key']][$h] = $file['file'];
            }

            return $file;
        });
    }

    /** Lecture et contrôle d'un fichier ; construit le GeoJSON (un polygone par hameau). */
    private function readFile(string $path): array
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $file = [
            'file' => basename($path), 'key' => $name, 'date' => null, 'zone_prefix' => null, 'village_code' => null,
            'cluster_number' => null, 'cluster_code' => null, 'sub_cluster' => null, 'hamlet' => null, 'hamlets' => 0, 'area_ha' => null,
            'centroid' => null, 'points' => [], 'geojson' => null, 'warnings' => [], 'error' => null, 'status' => null,
            'cluster_id' => null, 'village' => null,
        ];

        if (! preg_match(self::FILE_PATTERN, mb_strtoupper(trim($name)), $m) || ! checkdate((int) $m[7], (int) $m[6], (int) $m[8])) {
            $file['error'] = 'Nom de fichier non conforme : attendu '.self::FILE_FORMAT.' (ex. DAG-ASS-01-06-11-2026.csv, TCH-KPR-06-H01-09-11-2026.csv).';

            return $file;
        }
        [, $zone, $village, $number, $sub, $hamlet, $day, $month, $year] = $m;
        $file = array_merge($file, [
            'zone_prefix' => $zone,
            'village_code' => $village,
            'cluster_number' => (int) $number,
            'cluster_code' => sprintf('%s-%s-%02d', $zone, $village, (int) $number),
            'sub_cluster' => $sub !== '' ? (int) $sub : null,
            'hamlet' => $hamlet !== '' ? (int) $hamlet : null,
            'date' => CarbonImmutable::createFromDate((int) $year, (int) $month, (int) $day)->toDateString(),
        ]);
        $file['key'] = $file['cluster_code'].($file['sub_cluster'] !== null ? '-S'.$file['sub_cluster'] : '');
        if (HouseholdCode::zoneCode($zone) === null) {
            $file['error'] = "Préfixe de zone « {$zone} » inconnu (attendu ".implode(' ou ', array_keys(config('gavi.household_code.zone_prefixes'))).').';

            return $file;
        }

        $file['points'] = $this->readPoints($path, $file);
        if ($file['points'] === []) {
            $file['error'] = 'Aucun point GPS lisible dans le fichier.';

            return $file;
        }

        // Un contour par hameau (ou un seul sans hameau), points dans l'ordre P1, P2…
        $features = [];
        $area = 0.0;
        $groups = collect($file['points'])->groupBy(fn ($p) => $p['hamlet'] ?? 0)->sortKeys();
        foreach ($groups as $hamlet => $points) {
            $points = $points->sortBy('order')->values();
            $label = $hamlet ? "hameau {$hamlet}" : 'contour';
            if ($points->pluck('order')->duplicates()->isNotEmpty()) {
                $file['warnings'][] = "Numéros de points en double ({$label}) : ".$points->pluck('order')->duplicates()->unique()->map(fn ($n) => 'P'.$n)->implode(', ').'.';
            }
            // Points relevés hors de l'ordre du tour : côtés croisés. On réordonne au minimum pour un contour simple.
            if ($points->count() >= 4) {
                $untangled = self::untangle($points->all());
                if (array_column($untangled, 'order') !== $points->pluck('order')->all()) {
                    $file['warnings'][] = ucfirst($label).' : des côtés se croisaient en reliant les points dans l\'ordre P1, P2… ; contour tracé dans l\'ordre '
                        .collect($untangled)->map(fn ($p) => 'P'.$p['order'])->implode(', ').'. Vérifier l\'ordre du relevé.';
                    $points = collect($untangled);
                }
            }
            $ring = $points->map(fn ($p) => [$p['lng'], $p['lat']])->all();
            $properties = ['hameau' => $hamlet ?: null, 'points' => count($ring)];
            if (count($ring) < 3) {
                $file['warnings'][] = ucfirst($label).' : '.count($ring).' point(s) seulement, pas de contour possible (3 au minimum).';
                foreach ($ring as $coordinates) {
                    $features[] = ['type' => 'Feature', 'properties' => $properties, 'geometry' => ['type' => 'Point', 'coordinates' => $coordinates]];
                }

                continue;
            }
            $ring[] = $ring[0];
            $hectares = self::areaHectares($ring);
            $area += $hectares;
            $features[] = ['type' => 'Feature', 'properties' => $properties + ['superficie_ha' => round($hectares, 2)], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]];
        }
        $file['hamlets'] = $groups->keys()->filter()->count();
        $file['area_ha'] = round($area, 2);
        if ($area > 0 && $area < self::MIN_AREA_HA) {
            $file['warnings'][] = sprintf('Contour très petit (%s ha) : points relevés au même endroit ou mauvais fichier ?', number_format($area, 2, ',', ' '));
        }
        $file['centroid'] = [
            round(collect($file['points'])->avg('lat'), 7),
            round(collect($file['points'])->avg('lng'), 7),
        ];
        $file['geojson'] = ['type' => 'FeatureCollection', 'features' => $features];

        return $file;
    }

    /**
     * Points du fichier : colonnes « Point, Latitude, Longitude, Altitude… » (export du GPS).
     *
     * @return list<array{name: string, order: int, hamlet: ?int, lat: float, lng: float, alt: ?float}>
     */
    private function readPoints(string $path, array &$file): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        $points = [];
        $prefixes = [];
        $mismatched = [];
        $swapped = [];
        $fromUtm = [];
        $utmGaps = [];
        $line = 0;
        // Séparateur : « , » (export du GPS) ou « ; » (fichier repris dans Excel en français, virgule décimale possible).
        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        // Colonnes par défaut de l'export du GPS : Point, Latitude, Longitude, Altitude, E, N…
        $columns = ['name' => 0, 'lat' => 1, 'lng' => 2, 'alt' => 3, 'e' => 4, 'n' => 5];
        $number = fn ($v) => is_string($v) && preg_match('/^-?\d+(?:[.,]\d+)?$/', $v) ? (float) str_replace(',', '.', $v) : null;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            $row = array_map(fn ($v) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $row);
            $name = $row[$columns['name']] ?? '';
            if ($name === '') {
                continue;
            }
            if (str_starts_with(mb_strtolower($name), 'point') && $number($row[$columns['lat']] ?? null) === null) {
                $columns = $this->headerColumns($row, $columns);

                continue; // en-tête
            }
            $lat = $number($row[$columns['lat']] ?? null);
            $lng = $number($row[$columns['lng']] ?? null);
            $e = $columns['e'] !== null ? $number($row[$columns['e']] ?? null) : null;
            $n = $columns['n'] !== null ? $number($row[$columns['n']] ?? null) : null;
            $utm = $e !== null && $n !== null && $e > 100000 && $e < 900000 && $n > 0 && $n < 2000000 ? self::utmToLatLng($e, $n) : null;

            // Latitude et longitude inversées (au Bénin, la latitude dépasse toujours la longitude).
            if ($lat !== null && $lng !== null && ! $this->inBenin($lat, $lng) && $this->inBenin($lng, $lat)) {
                [$lat, $lng] = [$lng, $lat];
                $swapped[] = $name;
            }
            if (($lat === null || $lng === null || ! $this->inBenin($lat, $lng)) && $utm && $this->inBenin(...$utm)) {
                [$lat, $lng] = [round($utm[0], 8), round($utm[1], 8)];
                $fromUtm[] = $name;
            } elseif ($lat !== null && $lng !== null && $utm) {
                $gap = self::distanceKm($lat, $lng, $utm[0], $utm[1]) * 1000;
                if ($gap > 50) {
                    $utmGaps[] = sprintf('%s (%s m)', $name, number_format($gap, 0, ',', ' '));
                }
            }
            if ($lat === null || $lng === null) {
                $file['warnings'][] = "Ligne {$line} ({$name}) : coordonnées illisibles, point ignoré.";

                continue;
            }
            if (! $this->inBenin($lat, $lng)) {
                $file['warnings'][] = "{$name} : coordonnées hors du Bénin ({$lat}, {$lng}), point ignoré.";

                continue;
            }

            $upper = mb_strtoupper($name);
            if (! preg_match('/P\s*0*(\d+)\s*$/', $upper, $p)) {
                $file['warnings'][] = "{$name} : numéro de point (P1, P2…) introuvable, point ignoré.";

                continue;
            }
            $hamlet = preg_match('/(?:^|[-_ ])H\s*0*(\d+)(?=$|[-_ ])/', $upper, $h) ? (int) $h[1] : null;
            if ($file['hamlet'] !== null) {
                // Fichier d'un seul hameau : le nom du fichier fait foi.
                if ($hamlet !== null && $hamlet !== $file['hamlet']) {
                    $mismatched[$hamlet] = true;
                }
                $hamlet = $file['hamlet'];
            }
            $points[] = ['name' => $name, 'order' => (int) $p[1], 'hamlet' => $hamlet, 'lat' => $lat, 'lng' => $lng,
                'alt' => $columns['alt'] !== null ? $number($row[$columns['alt']] ?? null) : null];

            // Préfixe du point (zone, village, grappe, sous-grappe) comparé au nom du fichier.
            $prefix = preg_replace('/[-_ ]+/', '-', preg_replace('/[-_ ]*(H\s*\d+[-_ ]*)?P\s*\d+\s*$/', '', $upper));
            $prefixes[self::normalizeKey($prefix)] = $prefix;
        }
        fclose($handle);

        if ($swapped) {
            $file['warnings'][] = 'Latitude et longitude inversées, corrigées automatiquement : '.implode(', ', $swapped).'.';
        }
        if ($fromUtm) {
            $file['warnings'][] = 'Latitude/longitude absentes ou fausses, position calculée depuis les coordonnées UTM (E, N) : '.implode(', ', $fromUtm).'.';
        }
        if ($utmGaps) {
            $file['warnings'][] = 'Latitude/longitude et coordonnées UTM (E, N) ne désignent pas le même endroit : '.implode(', ', $utmGaps).'. Latitude/longitude retenues, vérifier le relevé.';
        }
        if ($mismatched) {
            $file['warnings'][] = 'Points nommés '.collect(array_keys($mismatched))->sort()->map(fn ($h) => 'H'.$h)->implode(', ')
                ." alors que le fichier désigne le hameau H{$file['hamlet']} : le nom du fichier a été retenu, vérifier le relevé.";
        }
        foreach ($prefixes as $normalized => $prefix) {
            if ($normalized !== $file['key']) {
                $file['warnings'][] = "Points nommés « {$prefix}-P… » alors que le fichier désigne {$file['key']} : le nom du fichier a été retenu, vérifier le relevé.";
            }
        }

        return $points;
    }

    private function inBenin(float $lat, float $lng): bool
    {
        return $lat >= self::BOUNDS['lat'][0] && $lat <= self::BOUNDS['lat'][1] && $lng >= self::BOUNDS['lng'][0] && $lng <= self::BOUNDS['lng'][1];
    }

    /**
     * Position des colonnes d'après l'en-tête (« Point:, Latitude:, Longitude:, Altitude: (m.), E: (m.), N: (m.)… ») ;
     * à défaut, les positions de l'export du GPS sont conservées.
     */
    private function headerColumns(array $header, array $defaults): array
    {
        $found = [];
        foreach ($header as $i => $label) {
            $label = mb_strtolower(trim(preg_replace('/[:(].*$/u', '', $label)));
            $key = match (true) {
                in_array($label, ['point', 'nom', 'name', 'nom_point', 'nom du point'], true) => 'name',
                in_array($label, ['latitude', 'lat', 'y'], true) => 'lat',
                in_array($label, ['longitude', 'lon', 'long', 'lng', 'x'], true) => 'lng',
                in_array($label, ['altitude', 'alt', 'elevation', 'élévation'], true) => 'alt',
                in_array($label, ['e', 'easting', 'est'], true) => 'e',
                in_array($label, ['n', 'northing', 'nord'], true) => 'n',
                default => null,
            };
            if ($key && ! isset($found[$key])) {
                $found[$key] = $i;
            }
        }
        if (! isset($found['lat'], $found['lng']) && ! isset($found['e'], $found['n'])) {
            return $defaults; // en-tête non reconnu
        }

        return ['name' => $found['name'] ?? 0, 'lat' => $found['lat'] ?? null, 'lng' => $found['lng'] ?? null,
            'alt' => $found['alt'] ?? null, 'e' => $found['e'] ?? null, 'n' => $found['n'] ?? null];
    }

    /** Grappe du fichier, créée si elle n'existe pas encore (aucun ménage reçu), rattachée au village du code. */
    private function cluster(array $file): ?Cluster
    {
        $zone = HealthZone::where('code', HouseholdCode::zoneCode($file['zone_prefix']))->first();
        $village = $zone ? Village::where('health_zone_id', $zone->id)->where('short_code', $file['village_code'])->first() : null;
        $cluster = Cluster::with('village')->where('code', $file['cluster_code'])->first();
        if ($cluster) {
            return $cluster;
        }
        if (! $village) {
            return null;
        }

        return Cluster::create([
            'code' => $file['cluster_code'],
            'name' => sprintf('%s – grappe %02d', $village->name, $file['cluster_number']),
            'number' => $file['cluster_number'],
            'village_id' => $village->id,
            'commune_id' => $village->commune_id,
            'health_zone_id' => $village->health_zone_id,
        ])->load('village');
    }

    /** Centre (médiane) des ménages géolocalisés par zone ou par village (3 ménages au moins). */
    private function householdCentroids(string $column): array
    {
        return DB::table('households')->whereNotNull($column)->whereNotNull('latitude')->whereNotNull('longitude')
            ->get([$column, 'latitude', 'longitude'])->groupBy($column)
            ->filter(fn (Collection $points) => $points->count() >= 3)
            ->map(fn (Collection $points) => [$points->median('latitude'), $points->median('longitude')])->all();
    }

    private function distanceWarnings(array $file, Cluster $cluster, array $zoneCentroids, array $villageCentroids): array
    {
        if (! $file['centroid']) {
            return [];
        }
        [$reference, $label, $limit] = isset($villageCentroids[$cluster->village_id])
            ? [$villageCentroids[$cluster->village_id], 'des ménages du village', self::MAX_DISTANCE_KM / 3]
            : [$zoneCentroids[$cluster->health_zone_id] ?? null, 'des ménages de la zone', self::MAX_DISTANCE_KM];
        if (! $reference) {
            return [];
        }
        $km = self::distanceKm($file['centroid'][0], $file['centroid'][1], (float) $reference[0], (float) $reference[1]);

        return $km > $limit
            ? [sprintf('Contour à %s km %s : coordonnées ou nom de fichier à vérifier.', number_format($km, 0, ',', ' '), $label)]
            : [];
    }

    /** Compilation de tous les points (y compris des relevés remplacés), séparateur « ; » pour Excel. */
    private function writeCompilation(Collection $files): void
    {
        $handle = fopen($this->compilationPath(), 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['zone_sanitaire', 'prefixe_zone', 'village', 'code_village', 'numero_grappe', 'code_grappe', 'sous_grappe', 'hameau',
            'point', 'nom_point', 'latitude', 'longitude', 'altitude_m', 'date_releve', 'fichier', 'statut'], ';', '"', '\\');
        $zones = HealthZone::pluck('name', 'code');
        foreach ($files as $file) {
            foreach (collect($file['points'])->sortBy([['hamlet', 'asc'], ['order', 'asc']]) as $point) {
                fputcsv($handle, [
                    $zones[HouseholdCode::zoneCode((string) $file['zone_prefix']) ?? ''] ?? null, $file['zone_prefix'], $file['village'],
                    $file['village_code'], $file['cluster_number'], $file['cluster_code'], $file['sub_cluster'], $point['hamlet'],
                    $point['order'], $point['name'], $point['lat'], $point['lng'], $point['alt'],
                    $file['date'] ? CarbonImmutable::parse($file['date'])->format('d/m/Y') : null, $file['file'], $file['status'],
                ], ';', '"', '\\');
            }
        }
        fclose($handle);
    }

    /** « TCH-BEE-3-S02 » → « TCH-BEE-03-S2 » ; un préfixe non reconnu est laissé tel quel. */
    private static function normalizeKey(string $prefix): string
    {
        if (! preg_match('/^([A-Z]{3})-([A-Z]{3})-(\d{1,3})(?:-?\(?S\(?0*(\d{1,2})?\)?\)?)?$/', $prefix, $m)) {
            return $prefix;
        }
        $key = sprintf('%s-%s-%02d', $m[1], $m[2], (int) $m[3]);

        return isset($m[4]) && $m[4] !== '' ? $key.'-S'.(int) $m[4] : (str_contains(substr($prefix, 10), 'S') ? $key.'-S?' : $key);
    }

    /**
     * Contour sans côtés croisés : tant que deux côtés se coupent, la portion de points entre eux est inversée
     * (mouvement « 2-opt »). Chaque inversion raccourcit le périmètre, l'algorithme se termine donc toujours ;
     * un contour déjà simple est rendu tel quel. Points : [['lat' => …, 'lng' => …, …], …] dans l'ordre P1, P2…
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @return list<array{lat: float, lng: float}>
     */
    public static function untangle(array $points): array
    {
        $points = array_values($points);
        $n = count($points);
        if ($n < 4) {
            return $points;
        }
        // Projection locale en mètres, pour des tests d'intersection justes à ces latitudes.
        $cos = cos(deg2rad(array_sum(array_column($points, 'lat')) / $n));
        $xy = fn (array $p) => [$p['lng'] * 111320.0 * $cos, $p['lat'] * 110574.0];
        $cross = fn ($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
        $intersect = function ($a, $b, $c, $d) use ($cross) {
            $d1 = $cross($c, $d, $a);
            $d2 = $cross($c, $d, $b);
            $d3 = $cross($a, $b, $c);
            $d4 = $cross($a, $b, $d);

            return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
        };

        for ($guard = 0; $guard < 10 * $n * $n; $guard++) {
            $found = false;
            for ($i = 0; $i < $n - 2 && ! $found; $i++) {
                for ($j = $i + 2; $j < $n && ! $found; $j++) {
                    if ($i === 0 && $j === $n - 1) {
                        continue; // côtés adjacents (fermeture de l'anneau)
                    }
                    if ($intersect($xy($points[$i]), $xy($points[$i + 1]), $xy($points[$j]), $xy($points[($j + 1) % $n]))) {
                        array_splice($points, $i + 1, $j - $i, array_reverse(array_slice($points, $i + 1, $j - $i)));
                        $found = true;
                    }
                }
            }
            if (! $found) {
                break;
            }
        }

        return $points;
    }

    /** Coordonnées UTM (WGS84, hémisphère nord) → [latitude, longitude] en degrés. Bénin : fuseau 31N. */
    public static function utmToLatLng(float $easting, float $northing, int $zone = 31): array
    {
        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $k0 = 0.9996;
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $x = $easting - 500000.0;
        $mu = $northing / $k0 / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi = $mu + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu) + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu) + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);
        $n1 = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $t = tan($phi) ** 2;
        $c = $ep2 * cos($phi) ** 2;
        $r = $a * (1 - $e2) / (1 - $e2 * sin($phi) ** 2) ** 1.5;
        $d = $x / ($n1 * $k0);
        $lat = $phi - ($n1 * tan($phi) / $r) * ($d ** 2 / 2 - (5 + 3 * $t + 10 * $c - 4 * $c ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t + 298 * $c + 45 * $t ** 2 - 252 * $ep2 - 3 * $c ** 2) * $d ** 6 / 720);
        $lng = ($d - (1 + 2 * $t + $c) * $d ** 3 / 6 + (5 - 2 * $c + 28 * $t - 3 * $c ** 2 + 8 * $ep2 + 24 * $t ** 2) * $d ** 5 / 120) / cos($phi);

        return [rad2deg($lat), ($zone - 1) * 6 - 180 + 3 + rad2deg($lng)];
    }

    /** Superficie (ha) d'un anneau [lng, lat] fermé, par projection locale équirectangulaire. */
    public static function areaHectares(array $ring): float
    {
        $lat0 = deg2rad(array_sum(array_column($ring, 1)) / count($ring));
        $r = 6371008.8;
        $xy = array_map(fn ($c) => [deg2rad($c[0]) * $r * cos($lat0), deg2rad($c[1]) * $r], $ring);
        $sum = 0.0;
        for ($i = 0, $n = count($xy) - 1; $i < $n; $i++) {
            $sum += $xy[$i][0] * $xy[$i + 1][1] - $xy[$i + 1][0] * $xy[$i][1];
        }

        return abs($sum) / 2 / 10000;
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * 6371.0 * asin(min(1, sqrt($a)));
    }
}
