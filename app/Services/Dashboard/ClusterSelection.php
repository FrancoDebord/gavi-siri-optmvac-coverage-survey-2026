<?php

namespace App\Services\Dashboard;

use App\Models\Cluster;
use App\Models\Village;
use App\Services\Dictionary\VariableMap as Map;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Grappes par village : pour chaque zone sanitaire et chaque village, la grappe sélectionnée, le nombre de
 * sous-grappes créées et la sous-grappe sélectionnée (réponses 6a « sub_circuits » et 6b « selected_sub_circuit »
 * du formulaire), les ménages visités et enquêtés, les enfants éligibles (12–35 mois) et le ou les binômes.
 * Plusieurs valeurs pour une même grappe signalent des réponses discordantes entre soumissions.
 * Mêmes définitions que la progression (TeamProgress) : enquêté = consentement donné.
 */
class ClusterSelection
{
    public function __construct(private readonly ?int $zoneId = null) {}

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
                $stats[$key] ??= ['cluster_id' => $r->cluster_id, 'village_id' => $r->village_id, 'visited' => 0, 'surveyed' => 0, 'children' => 0, 'created' => [], 'selected' => [], 'teams' => []];
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

        // Réponses 6a (nombre de sous-grappes créées) et 6b (sous-grappe sélectionnée) du formulaire.
        $answers = array_filter(['created' => Map::var('nb_sous_grappes'), 'selected' => Map::var('sous_grappe')],
            fn ($var) => $var && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $var));
        if ($answers) {
            $this->households()->distinct()
                ->get(array_merge(['households.cluster_id', 'households.village_id'], array_map(fn ($var, $alias) => "households.data->{$var} as {$alias}", $answers, array_keys($answers))))
                ->each(function ($r) use (&$stats, $answers) {
                    foreach (array_keys($answers) as $field) {
                        if (is_numeric($r->{$field} ?? null)) {
                            $stats[self::key($r->cluster_id, $r->village_id)][$field][] = (int) $r->{$field};
                        }
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

        $empty = ['visited' => 0, 'surveyed' => 0, 'children' => 0, 'created' => [], 'selected' => [], 'teams' => []];
        $values = fn (array $list) => collect($list)->unique()->sort()->values()->all();
        $rows = collect();
        foreach ($clusters as $cluster) {
            $s = ($stats['c'.$cluster->id] ?? []) + $empty;
            $rows->push([
                'zone' => $cluster->healthZone?->name ?? $cluster->village?->healthZone?->name ?? 'Zone non renseignée',
                'village' => $cluster->village?->name ?? 'Village non renseigné',
                'cluster_id' => $cluster->id,
                'cluster_code' => $cluster->code,
                'cluster_number' => $cluster->number,
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
                'cluster_number' => null,
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
                'rows' => $villageRows->sortBy([fn ($a, $b) => ($a['cluster_number'] ?? PHP_INT_MAX) <=> ($b['cluster_number'] ?? PHP_INT_MAX), fn ($a, $b) => strnatcmp((string) $a['cluster_code'], (string) $b['cluster_code'])])->values()->all(),
                'totals' => $sum($villageRows),
            ])->values()->all(),
            'totals' => $sum($zoneRows) + ['villages' => $zoneRows->pluck('village')->unique()->count()],
        ])->values()->all();

        return ['zones' => $zones, 'totals' => $sum($rows) + ['villages' => $rows->map(fn ($r) => $r['zone'].'|'.$r['village'])->unique()->count()]];
    }
}
