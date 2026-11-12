<?php

namespace App\Services\Import;

use App\Models\Arrondissement;
use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\Village;
use App\Services\Dictionary\CsvReader;
use App\Support\HouseholdCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Charge le référentiel géographique et la liste des enquêteurs à partir des CSV
 * utilisés par le formulaire (pulldata / select_one_from_file) :
 * l1.csv (zones sanitaires), l2.csv (communes), l3.csv (arrondissements),
 * village.csv, l1_fieldworkers.csv (agents) et binomes.csv (paires d'agents).
 */
class ReferenceDataLoader
{
    /** @var list<string> lignes du classeur des codes villages sans village correspondant (dernier chargement) */
    public array $unmatchedVillageCodes = [];

    /**
     * Les grappes viennent de l'identifiant ménage (ZONE-VIL-NN) ;
     * $clustersFromVillages recrée l'ancien découpage « une grappe par village ».
     *
     * @return array<string, int> nombre d'éléments chargés par niveau
     */
    public function load(?string $directory = null, ?string $country = null, bool $clustersFromVillages = false): array
    {
        $directory = rtrim($directory ?? config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR;
        $country ??= config('gavi.country');
        $counts = ['zones' => 0, 'communes' => 0, 'arrondissements' => 0, 'villages' => 0, 'grappes' => 0, 'enqueteurs' => 0, 'binomes' => 0];

        DB::transaction(function () use ($directory, $country, $clustersFromVillages, &$counts) {
            $zones = [];
            foreach ($this->rows($directory.'l1.csv') as $row) {
                if ($country && ($row['country'] ?? $country) !== $country) {
                    continue;
                }
                $zones[$row['name']] = HealthZone::updateOrCreate(['code' => $row['name']], ['name' => $row['label'], 'country' => $row['country'] ?? $country])->id;
            }

            $communes = [];
            foreach ($this->rows($directory.'l2.csv') as $row) {
                if (! isset($zones[$row['l1'] ?? null])) {
                    continue;
                }
                $communes[$row['name']] = Commune::updateOrCreate(['code' => $row['name']], ['name' => $row['label'], 'health_zone_id' => $zones[$row['l1']]])->id;
            }

            $arrondissements = [];
            foreach ($this->rows($directory.'l3.csv') as $row) {
                if (! isset($communes[$row['l2'] ?? null])) {
                    continue;
                }
                $arrondissements[$row['name']] = Arrondissement::updateOrCreate(['code' => $row['name']], ['name' => $row['label'], 'commune_id' => $communes[$row['l2']]])->id;
            }

            $communeZone = Commune::pluck('health_zone_id', 'id');
            $arrCommune = Arrondissement::pluck('commune_id', 'id');
            foreach ($this->rows($directory.'village.csv') as $row) {
                $arrId = $arrondissements[$row['parent'] ?? null] ?? null;
                if (! $arrId) {
                    continue;
                }
                $communeId = $arrCommune[$arrId] ?? null;
                $village = Village::updateOrCreate(['code' => $row['name']], [
                    'name' => trim($row['label']),
                    'arrondissement_id' => $arrId,
                    'commune_id' => $communeId,
                    'health_zone_id' => $communeZone[$communeId] ?? null,
                ]);
                $counts['villages']++;

                if ($clustersFromVillages) {
                    $cluster = Cluster::firstOrNew(['code' => $village->code]);
                    $cluster->fill([
                        'name' => $cluster->name ?: $village->name,
                        'village_id' => $village->id,
                        'commune_id' => $village->commune_id,
                        'health_zone_id' => $village->health_zone_id,
                    ])->save();
                    $counts['grappes']++;
                }
            }

            foreach ($this->rows($directory.'l1_fieldworkers.csv') as $row) {
                $zoneId = $zones[$row['L1'] ?? null] ?? null;
                if ($country && ! $zoneId) {
                    continue;
                }
                $name = $row['label'] ?? trim(($row['Prénom'] ?? '').' '.($row['Nom'] ?? ''));
                Collector::updateOrCreate(['code' => $row['name']], [
                    'name' => $name,
                    'initials' => $row['INITIALES'] ?? null,
                    'health_zone_id' => $zoneId,
                ]);
                $counts['enqueteurs']++;
            }

            $counts['binomes'] = $this->loadTeams($directory.'binomes.csv');

            $counts['zones'] = count($zones);
            $counts['communes'] = count($communes);
            $counts['arrondissements'] = count($arrondissements);
        });

        $codes = $this->loadVillageCodes($directory.config('gavi.household_code.village_codes_file'));
        $counts['codes_villages'] = $codes['matched'];
        $this->unmatchedVillageCodes = $codes['unmatched'];

        return $counts;
    }

    /**
     * Binômes (binomes.csv : binome, agent_1, agent_2 = codes « name » de l1_fieldworkers.csv).
     * Le fichier fait foi : les affectations sont recalculées et les binômes disparus supprimés.
     */
    private function loadTeams(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }
        $collectors = Collector::get(['id', 'code', 'name', 'health_zone_id'])->keyBy('code');
        $lastName = fn (?Collector $c) => $c ? trim(explode(',', $c->name)[0]) : null;

        $teamIds = [];
        Collector::query()->update(['team_id' => null]);
        foreach ($this->rows($path) as $row) {
            $members = collect([$row['agent_1'] ?? null, $row['agent_2'] ?? null])->filter()->map(fn ($code) => $collectors[trim($code)] ?? null)->filter();
            if ($members->isEmpty()) {
                continue;
            }
            $code = trim($row['binome'] ?? '') ?: $members->pluck('code')->implode('-');
            $team = Team::updateOrCreate(['code' => $code], [
                'name' => $members->map($lastName)->implode(' & '),
                'health_zone_id' => $members->first()->health_zone_id,
            ]);
            Collector::whereIn('id', $members->pluck('id'))->update(['team_id' => $team->id]);
            $teamIds[] = $team->id;
        }
        Team::whereNotIn('id', $teamIds)->delete();

        return count($teamIds);
    }

    /**
     * Codes villages à 3 lettres (identifiant ménage) : un onglet par zone, colonnes « Village » et « Identifiant ».
     * Le nom est rapproché de village.csv dans la zone, à 2 caractères près (ex. « Kpassa 2 » / « Kapassa 2 »).
     *
     * @return array{matched: int, unmatched: list<string>}
     */
    public function loadVillageCodes(string $path): array
    {
        $result = ['matched' => 0, 'unmatched' => []];
        if (! is_file($path)) {
            return $result;
        }

        $normalize = fn (?string $name) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(Str::ascii(trim((string) $name))));
        $spreadsheet = IOFactory::load($path);

        foreach ($spreadsheet->getSheetNames() as $sheet) {
            $zoneCode = HouseholdCode::zoneCode(mb_substr(Str::ascii($sheet), 0, 3));
            $zone = $zoneCode !== null ? HealthZone::where('code', $zoneCode)->first() : null;
            if (! $zone) {
                continue;
            }
            $villages = Village::where('health_zone_id', $zone->id)->get(['id', 'name'])
                ->map(fn ($v) => ['id' => $v->id, 'key' => $normalize($v->name)]);

            $rows = $spreadsheet->getSheetByName($sheet)->toArray(null, true, false, false);
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
            $nameColumn = array_search('village', $header, true);
            $codeColumn = array_search('identifiant', $header, true);
            if ($nameColumn === false || $codeColumn === false) {
                continue;
            }

            $entries = collect($rows)
                ->map(fn ($row) => ['name' => trim((string) ($row[$nameColumn] ?? '')), 'code' => mb_strtoupper(trim((string) ($row[$codeColumn] ?? '')))])
                ->filter(fn ($e) => $e['name'] !== '' && $e['code'] !== '')
                ->map(fn ($e) => $e + ['key' => $normalize($e['name'])]);

            // 1) correspondances exactes ; 2) à 2 caractères près parmi les villages restants, mêmes chiffres, candidat unique.
            $assigned = [];
            $pending = [];
            foreach ($entries as $entry) {
                $match = $villages->firstWhere('key', $entry['key']);
                if ($match) {
                    $assigned[$match['id']] = $entry;
                } else {
                    $pending[] = $entry;
                }
            }
            $digits = fn (string $key) => preg_replace('/\D/', '', $key);
            foreach ($pending as $entry) {
                $candidates = $villages->reject(fn ($v) => isset($assigned[$v['id']]))
                    ->filter(fn ($v) => $digits($v['key']) === $digits($entry['key']))
                    ->map(fn ($v) => $v + ['distance' => levenshtein($v['key'], $entry['key'])])
                    ->filter(fn ($v) => $v['distance'] <= 2);
                $best = $candidates->min('distance');
                $closest = $candidates->where('distance', $best);
                if ($closest->count() === 1) {
                    $assigned[$closest->first()['id']] = $entry;
                } else {
                    $result['unmatched'][] = "{$zone->name} : {$entry['name']} ({$entry['code']})";
                }
            }

            foreach ($assigned as $villageId => $entry) {
                Village::whereKey($villageId)->update(['short_code' => $entry['code']]);
                $result['matched']++;
            }
        }

        return $result;
    }

    /** @return list<array<string, string>> */
    private function rows(string $path): array
    {
        return is_file($path) ? CsvReader::read($path) : [];
    }
}
