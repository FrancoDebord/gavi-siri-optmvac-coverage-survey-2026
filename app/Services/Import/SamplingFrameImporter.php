<?php

namespace App\Services\Import;

use App\Models\ClusterFrame;
use App\Models\HealthZone;
use App\Models\Village;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Charge la base de sondage (database/data/base_sondage_grappes.csv : zone, arrondissement, village, grappe,
 * ménages dénombrés, subdivisions) et rattache chaque village de la liste aux villages de la plateforme :
 * nom identique (sans accents ni ponctuation), alias connus, village séparé en deux sur la plateforme
 * (« Kpassa » → Kpassa 1 et Kapassa 2), sinon nom proche (2 lettres d'écart au plus). Rechargement complet, idempotent.
 */
class SamplingFrameImporter
{
    /**
     * Noms des fichiers → nom de la plateforme (forme normalisée : minuscules sans accents ni ponctuation),
     * quand l'écriture diffère ou pour fixer une correspondance confirmée par l'équipe.
     */
    public const ALIASES = [
        'tchaouroucentre' => 'tchaourou',
        'zaffe' => 'zaffecentre',
        'erokoyazankoumadon' => 'zankoumadon',   // EROKOYA (ZANKOUMADON) → Zankoumadon
        'erokoya' => 'zankoumadon',
        'yevedoactuellealodoalomin' => 'yevedo',
        'vedji' => 'vedji',                     // Vêdji → VEDJI
        'itchoguesotre' => 'itchoguesotre',     // Itchogué Sotré → Itchogue-Sotre
        'fita' => 'fita',                       // FITA → Fita
    ];

    /** Forme normalisée d'un nom de village, alias appliqué. */
    public static function normalize(string $name): string
    {
        $key = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($name)));

        return self::ALIASES[$key] ?? $key;
    }

    public static function path(): string
    {
        return database_path('data/base_sondage_grappes.csv');
    }

    /**
     * @return array{clusters: int, villages: int, unmatched: list<string>}
     */
    public function import(?string $path = null): array
    {
        $path ??= self::path();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }
        fclose($handle);

        $prefixes = config('gavi.household_code.zone_prefixes');
        $zones = HealthZone::pluck('id', 'code');
        $villages = Village::get(['id', 'name', 'health_zone_id'])->groupBy('health_zone_id');
        $matches = [];
        $unmatched = [];
        $records = [];
        $now = now();

        foreach ($rows as $row) {
            $zoneId = $zones[$prefixes[$row['zone']] ?? ''] ?? null;
            $key = $row['zone'].'|'.$row['village'];
            $matches[$key] ??= $this->match($row['village'], $villages[$zoneId] ?? collect());
            if ($matches[$key] === []) {
                $unmatched[$key] = $row['zone'].' · '.$row['arrondissement'].' · '.$row['village'];
            }
            $records[] = [
                'health_zone_id' => $zoneId,
                'arrondissement' => $row['arrondissement'],
                'village_label' => $row['village'],
                'village_ids' => json_encode($matches[$key]),
                'cluster_number' => $row['grappe'] === '' ? 1 : (int) $row['grappe'],
                'single_cluster' => $row['grappe'] === '',
                'households_enumerated' => (int) $row['menages_denombres'],
                'subdivisions' => max(1, (int) $row['subdivisions']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($records) {
            ClusterFrame::query()->delete();
            foreach (array_chunk($records, 200) as $chunk) {
                ClusterFrame::insert($chunk);
            }
        });

        return ['clusters' => count($records), 'villages' => count($matches), 'unmatched' => array_values($unmatched)];
    }

    /** @return list<int> villages de la plateforme correspondant au nom de la liste */
    private function match(string $label, Collection $villages): array
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($s)));
        $base = fn (string $s) => preg_replace('/\d+$/', '', $norm($s));
        $target = self::normalize($label);

        $exact = $villages->filter(fn ($v) => $norm($v->name) === $target);
        if ($exact->isNotEmpty()) {
            return $exact->pluck('id')->values()->all();
        }
        // Village séparé en deux sur la plateforme (« Kpassa 1 », « Kapassa 2 »).
        $split = $villages->filter(fn ($v) => preg_match('/\d+$/', $norm($v->name)) && levenshtein($base($v->name), $target) <= 1);
        if ($split->isNotEmpty()) {
            return $split->pluck('id')->values()->all();
        }
        $close = $villages->map(fn ($v) => ['id' => $v->id, 'd' => levenshtein($norm($v->name), $target)])->where('d', '<=', 2)->sortBy('d');

        return $close->isNotEmpty() ? [$close->first()['id']] : [];
    }
}
