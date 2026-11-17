<?php

namespace App\Services\Dashboard;

use App\Models\Cluster;
use App\Models\Village;
use App\Services\Dictionary\VariableMap as Map;
use App\Support\HouseholdCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grappes par village : pour chaque zone sanitaire et chaque village, le numéro de grappe et la sous-grappe
 * sélectionnée lus dans l'identifiant ménage (ZONE-VIL-NN[-Sx]-MMM ; « NA » quand l'identifiant ne donne pas de S),
 * le nombre de sous-grappes créées (réponse 6a « sub_circuits » du formulaire), les ménages visités et enquêtés,
 * les enfants éligibles (12–35 mois) et le ou les binômes.
 * Plusieurs valeurs pour une même grappe signalent des identifiants ou des réponses discordants entre soumissions.
 * Mêmes définitions que la progression (TeamProgress) : enquêté = consentement donné.
 */
class ClusterSelection
{
    public function __construct(private readonly ?int $zoneId = null) {}

    public const NA = 'NA';

    private static function first(array $numbers): int
    {
        return collect($numbers)->filter(fn ($n) => is_int($n))->min() ?? PHP_INT_MAX;
    }

    private function households()
    {
        return DB::table('households')->when($this->zoneId, fn ($q, $zone) => $q->where('households.health_zone_id', $zone));
    }

    /** Clé d'une ligne : la grappe, ou le village pour les ménages sans grappe. */
    private static function key(?int $clusterId, ?int $villageId): string
    {
        return $clusterId ? 'c'.$clusterId : 'v'.($villageId ?? 0);
    }

    /**
     * @return array{zones: list<array{name: string, villages: list<array{name: string, rows: list<array>, totals: array}>, totals: array}>, totals: array}
     */
    public function build(): array
    {
        $stats = [];
        $this->households()
            ->selectRaw('households.cluster_id, households.village_id, count(*) as visited, sum(case when households.consented then 1 else 0 end) as surveyed')
            ->groupBy('households.cluster_id', 'households.village_id')->get()
            ->each(function ($r) use (&$stats) {
                $key = self::key($r->cluster_id, $r->village_id);
                $stats[$key] ??= ['cluster_id' => $r->cluster_id, 'village_id' => $r->village_id, 'visited' => 0, 'surveyed' => 0, 'children' => 0, 'numbers' => [], 'created' => [], 'selected' => [], 'teams' => []];
                $stats[$key]['visited'] += (int) $r->visited;
                $stats[$key]['surveyed'] += (int) $r->surveyed;
            });

        $this->households()->join('children', 'children.household_id', '=', 'households.id')->where('children.eligible', true)
            ->selectRaw('households.cluster_id, households.village_id, count(*) as n')
            ->groupBy('households.cluster_id', 'households.village_id')->get()
            ->each(function ($r) use (&$stats) {
                $key = self::key($r->cluster_id, $r->village_id);
                if (isset($stats[$key])) {
                    $stats[$key]['children'] += (int) $r->n;
                }
            });

        // Numéro de grappe (NN) et sous-grappe (Sx) lus dans l'identifiant ménage ; sans S (ou identifiant illisible) : NA.
        $this->households()->distinct()->get(['households.cluster_id', 'households.village_id', 'households.household_code'])
            ->each(function ($r) use (&$stats) {
                $parsed = HouseholdCode::parse($r->household_code);
                $key = self::key($r->cluster_id, $r->village_id);
                if ($parsed) {
                    $stats[$key]['numbers'][] = $parsed['cluster'];
                }
                $stats[$key]['selected'][] = $parsed['sub_cluster'] ?? self::NA;
            });

        // Réponse 6a du formulaire : nombre de sous-grappes créées.
        $created = Map::var('nb_sous_grappes');
        if ($created && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $created)) {
            $this->households()->distinct()->get(['households.cluster_id', 'households.village_id', "households.data->{$created} as created"])
                ->each(function ($r) use (&$stats) {
                    if (is_numeric($r->created)) {
                        $stats[self::key($r->cluster_id, $r->village_id)]['created'][] = (int) $r->created;
                    }
                });
        }

        $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->join('teams', 'teams.id', '=', 'collectors.team_id')->distinct()
            ->get(['households.cluster_id', 'households.village_id', 'teams.code', 'teams.name'])
            ->each(function ($r) use (&$stats) {
                $stats[self::key($r->cluster_id, $r->village_id)]['teams'][$r->code] = $r->code.($r->name ? ' · '.$r->name : '');
            });

        // Grappes visitées, plus celles déjà délimitées mais pas encore visitées.
        $clusterIds = collect($stats)->pluck('cluster_id')->filter()->all();
        $clusters = Cluster::with(['village:id,name,health_zone_id', 'village.healthZone:id,name', 'healthZone:id,name'])
            ->where(fn ($q) => $q->whereIn('id', $clusterIds)->orWhereNotNull('boundary')->orWhereHas('subClusters'))
            ->when($this->zoneId, fn ($q, $zone) => $q->where(fn ($w) => $w->where('health_zone_id', $zone)->orWhereIn('id', $clusterIds)))
            ->get()->keyBy('id');
        $villages = Village::with('healthZone:id,name')
            ->whereIn('id', collect($stats)->whereNull('cluster_id')->pluck('village_id')->filter()->all())->get()->keyBy('id');

        $empty = ['visited' => 0, 'surveyed' => 0, 'children' => 0, 'numbers' => [], 'created' => [], 'selected' => [], 'teams' => []];
        // Valeurs distinctes, nombres d'abord puis NA.
        $values = fn (array $list) => collect($list)->unique()->sortBy(fn ($v) => $v === self::NA ? PHP_INT_MAX : $v)->values()->all();
        $rows = collect();
        foreach ($clusters as $cluster) {
            $s = ($stats['c'.$cluster->id] ?? []) + $empty;
            $rows->push([
                'zone' => $cluster->healthZone?->name ?? $cluster->village?->healthZone?->name ?? 'Zone non renseignée',
                'village' => $cluster->village?->name ?? 'Village non renseigné',
                'cluster_id' => $cluster->id,
                'cluster_code' => $cluster->code,
                // Grappe pas encore visitée : pas d'identifiant ménage, on garde le numéro du référentiel.
                'cluster_numbers' => $s['visited'] ? ($values($s['numbers']) ?: [self::NA]) : ($cluster->number !== null ? [$cluster->number] : []),
                'sub_clusters' => $values($s['created']),
                'selected' => $values($s['selected']),
                'teams' => collect($s['teams'])->sortKeys(SORT_NATURAL)->values()->all(),
            ] + array_intersect_key($s, array_flip(['visited', 'surveyed', 'children'])));
        }
        foreach (collect($stats)->whereNull('cluster_id') as $s) {
            $village = $villages[$s['village_id']] ?? null;
            $rows->push([
                'zone' => $village?->healthZone?->name ?? 'Zone non renseignée',
                'village' => $village?->name ?? 'Village non renseigné',
                'cluster_id' => null,
                'cluster_code' => null,
                'cluster_numbers' => $values($s['numbers']) ?: [self::NA],
                'sub_clusters' => $values($s['created']),
                'selected' => $values($s['selected']),
                'teams' => collect($s['teams'])->sortKeys(SORT_NATURAL)->values()->all(),
            ] + array_intersect_key($s, array_flip(['visited', 'surveyed', 'children'])));
        }

        $sum = fn (Collection $items) => [
            'clusters' => $items->whereNotNull('cluster_id')->count(),
            // Une valeur par grappe (la plus grande si les réponses diffèrent).
            'sub_clusters' => $items->sum(fn ($row) => $row['sub_clusters'] ? max($row['sub_clusters']) : 0),
            'visited' => $items->sum('visited'),
            'surveyed' => $items->sum('surveyed'),
            'children' => $items->sum('children'),
        ];

        $zones = $rows->groupBy('zone')->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->map(fn (Collection $zoneRows, $zone) => [
            'name' => $zone,
            'villages' => $zoneRows->groupBy('village')->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)->map(fn (Collection $villageRows, $village) => [
                'name' => $village,
                'rows' => $villageRows->sortBy([fn ($a, $b) => self::first($a['cluster_numbers']) <=> self::first($b['cluster_numbers']), fn ($a, $b) => strnatcmp((string) $a['cluster_code'], (string) $b['cluster_code'])])->values()->all(),
                'totals' => $sum($villageRows),
            ])->values()->all(),
            'totals' => $sum($zoneRows) + ['villages' => $zoneRows->pluck('village')->unique()->count()],
        ])->values()->all();

        return ['zones' => $zones, 'totals' => $sum($rows) + ['villages' => $rows->map(fn ($r) => $r['zone'].'|'.$r['village'])->unique()->count()]];
    }
}
