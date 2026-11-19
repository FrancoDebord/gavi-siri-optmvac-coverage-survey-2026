<?php

namespace App\Services\Import;

use App\Models\Team;
use App\Models\Village;
use App\Services\Dictionary\CsvReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Villages couverts par chaque binôme (binomes_villages.csv : binome = code du binôme, agents, village), base de la
 * règle TEAM_VILLAGE. Le nom du village est rapproché des villages de la zone du binôme : nom identique (sans accents
 * ni ponctuation, alias de la base de sondage), sinon à 2 lettres près avec les mêmes chiffres (« Kpassa 2 » → Kapassa 2).
 * Le fichier fait foi : rechargement complet des binômes qu'il cite.
 */
class TeamVillageImporter
{
    public const FILE = 'binomes_villages.csv';

    /** Fichier du dossier des réglages s'il y est déposé, sinon celui livré avec l'application. */
    public static function path(): string
    {
        $settings = rtrim((string) config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR.self::FILE;

        return is_file($settings) ? $settings : database_path('data/'.self::FILE);
    }

    /**
     * @return array{teams: int, links: int, unmatched: list<string>}
     */
    public function import(?string $path = null): array
    {
        $path ??= self::path();
        $result = ['teams' => 0, 'links' => 0, 'unmatched' => []];
        if (! is_file($path)) {
            return $result;
        }

        $teams = Team::with('collectors:id,team_id,name')->get()->keyBy('code');
        $villagesByZone = Village::get(['id', 'name', 'health_zone_id'])->groupBy('health_zone_id');
        $links = [];
        foreach (CsvReader::read($path) as $row) {
            $code = trim((string) ($row['binome'] ?? ''));
            $name = trim((string) ($row['village'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $team = $teams[$code] ?? null;
            if (! $team) {
                $result['unmatched'][] = "Binôme {$code} inconnu ({$name})";

                continue;
            }
            $links[$team->id] ??= [];
            $village = self::match($name, $villagesByZone[$team->health_zone_id] ?? collect());
            if (! $village) {
                $result['unmatched'][] = "Binôme {$code} : village « {$name} » introuvable";

                continue;
            }
            $links[$team->id][$village->id] = true;
        }

        DB::transaction(function () use ($links, &$result) {
            foreach ($links as $teamId => $villageIds) {
                DB::table('team_village')->where('team_id', $teamId)->delete();
                DB::table('team_village')->insert(array_map(fn ($id) => ['team_id' => $teamId, 'village_id' => $id], array_keys($villageIds)));
                $result['teams']++;
                $result['links'] += count($villageIds);
            }
        });

        return $result;
    }

    private static function match(string $name, Collection $villages): ?Village
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($s)));
        $digits = fn (string $s) => preg_replace('/\D/', '', $s);
        $key = SamplingFrameImporter::normalize($name);

        return $villages->first(fn ($v) => $norm($v->name) === $key)
            ?? $villages->filter(fn ($v) => $digits($norm($v->name)) === $digits($key))
                ->map(fn ($v) => ['v' => $v, 'd' => levenshtein($norm($v->name), $key)])
                ->where('d', '<=', 2)->sortBy('d')->first()['v'] ?? null;
    }
}
