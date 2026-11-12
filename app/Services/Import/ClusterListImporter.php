<?php

namespace App\Services\Import;

use App\Models\Cluster;
use App\Models\Village;
use App\Services\Dictionary\CsvReader;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Import de la liste des grappes et de leurs cibles.
 * Colonnes attendues (en-tête, ordre libre) :
 *   code, nom, village (code), cible_menages, cible_enfants, latitude, longitude
 */
class ClusterListImporter
{
    private const ALIASES = [
        'code' => ['code', 'grappe', 'cluster', 'code_grappe', 'cluster_code'],
        'name' => ['nom', 'name', 'libelle', 'label'],
        'village' => ['village', 'code_village', 'village_code'],
        'target_households' => ['cible_menages', 'target_households', 'menages_cibles', 'cible_menage'],
        'target_children' => ['cible_enfants', 'target_children', 'enfants_cibles', 'cible_enfant'],
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'lon', 'lng'],
    ];

    /** @return array{created: int, updated: int, skipped: int} */
    public function import(string $path): array
    {
        $rows = CsvReader::read($path);
        if ($rows === []) {
            throw new InvalidArgumentException('Le fichier est vide.');
        }
        $columns = $this->resolveColumns(array_keys($rows[0]));
        if (! isset($columns['code'])) {
            throw new InvalidArgumentException('Colonne « code » (grappe) introuvable.');
        }

        $villages = Village::get(['id', 'code', 'commune_id', 'health_zone_id', 'name'])->keyBy('code');
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($rows, $columns, $villages, &$result) {
            foreach ($rows as $row) {
                $value = fn (string $field) => isset($columns[$field]) && $row[$columns[$field]] !== '' ? $row[$columns[$field]] : null;
                $code = $value('code');
                if (! $code) {
                    $result['skipped']++;

                    continue;
                }
                $village = $villages->get((string) $value('village'));
                $cluster = Cluster::firstOrNew(['code' => $code]);
                $exists = $cluster->exists;
                $cluster->fill(array_filter([
                    'name' => $value('name') ?? $cluster->name ?? $village?->name ?? 'Grappe '.$code,
                    'village_id' => $village?->id,
                    'commune_id' => $village?->commune_id,
                    'health_zone_id' => $village?->health_zone_id,
                    'target_households' => is_numeric($value('target_households')) ? (int) $value('target_households') : null,
                    'target_children' => is_numeric($value('target_children')) ? (int) $value('target_children') : null,
                    'latitude' => is_numeric($value('latitude')) ? (float) $value('latitude') : null,
                    'longitude' => is_numeric($value('longitude')) ? (float) $value('longitude') : null,
                ], fn ($v) => $v !== null))->save();
                $result[$exists ? 'updated' : 'created']++;
            }
        });

        return $result;
    }

    /** @return array<string, string> champ => colonne du fichier */
    private function resolveColumns(array $header): array
    {
        $resolved = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($header as $column) {
                if (in_array(strtolower(trim($column)), $aliases, true)) {
                    $resolved[$field] = $column;

                    break;
                }
            }
        }

        return $resolved;
    }
}
