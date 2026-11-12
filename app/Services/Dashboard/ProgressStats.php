<?php

namespace App\Services\Dashboard;

use App\Models\DataQuery;
use App\Services\Dictionary\VariableMap as Map;
use App\Support\DashboardCache;
use App\Support\Filters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Agrégations du dashboard de progression de la collecte. */
class ProgressStats
{
    public function __construct(private readonly Filters $filters) {}

    public function all(): array
    {
        return DashboardCache::remember('progress', $this->filters->cacheContext(), fn () => [
            'kpis' => $this->kpis(),
            'daily' => $this->daily(),
            'by_zone' => $this->byLevel('health_zones', 'health_zone_id'),
            'by_commune' => $this->byLevel('communes', 'commune_id'),
            'productivity' => $this->productivity(),
            'productivity_teams' => $this->productivity(byTeam: true),
            'hierarchy' => $this->hierarchy(),
            'points' => $this->points(),
            'boundaries' => $this->boundaries(),
            'generated_at' => now()->format('d/m/Y H:i:s'),
        ]);
    }

    private function households()
    {
        return $this->filters->apply(DB::table('households'), 'households', 'interview_date');
    }

    /** @return list<string> */
    public static function statusValues(string $key, string $default): array
    {
        return array_map('trim', explode(',', Map::value($key, $default)));
    }

    public function kpis(): array
    {
        $byStatus = $this->households()->selectRaw('survey_status, count(*) as n')->groupBy('survey_status')->pluck('n', 'survey_status');
        $visited = (int) $byStatus->sum();
        $surveyed = (int) $this->households()->where('consented', true)->count();
        $pick = fn (array $values) => (int) collect($values)->sum(fn ($v) => $byStatus[$v] ?? 0);

        $caregivers = $this->filters->applyThroughHouseholds(DB::table('caregivers')->where('consented', true), 'household_id')->count();
        $children = $this->filters->applyThroughHouseholds(DB::table('children')->where('eligible', true), 'household_id')->count();

        $clusters = $this->clusterStatus();
        $targets = $this->clusterQuery()->selectRaw('sum(clusters.target_households) as households, sum(clusters.target_children) as children')->first();

        $lastSync = DB::table('data_sources')->max('last_synced_at');
        $lastSubmission = $this->households()->max('submitted_at');

        return [
            'visited' => $visited,
            'surveyed' => $surveyed,
            'refused' => $pick(self::statusValues('statut_refus', '1')),
            'absent' => $pick(self::statusValues('statut_absent', '2,4')),
            'no_children' => $pick(self::statusValues('statut_sans_enfant', '3')),
            'caregivers' => $caregivers,
            'children' => $children,
            'target_households' => (int) ($targets->households ?? 0),
            'target_children' => (int) ($targets->children ?? 0),
            'rate_households' => $targets->households ? round($surveyed * 100 / $targets->households, 1) : null,
            'rate_children' => $targets->children ? round($children * 100 / $targets->children, 1) : null,
            'clusters' => $clusters,
            'last_sync' => $lastSync ? CarbonImmutable::parse($lastSync)->setTimezone(config('app.timezone'))->format('d/m/Y H:i') : null,
            'last_submission' => $lastSubmission ? CarbonImmutable::parse($lastSubmission)->format('d/m/Y H:i') : null,
        ];
    }

    /** Grappes de l'univers : celles qui ont une cible ou au moins une soumission. */
    private function clusterQuery()
    {
        $query = DB::table('clusters')->where(function ($q) {
            $q->whereNotNull('clusters.target_households')->orWhereIn('clusters.id', DB::table('households')->whereNotNull('cluster_id')->select('cluster_id'));
        });
        foreach (['health_zone_id', 'commune_id', 'village_id'] as $column) {
            if ($this->filters->get($column)) {
                $query->where("clusters.{$column}", $this->filters->get($column));
            }
        }
        if ($this->filters->get('cluster_id')) {
            $query->where('clusters.id', $this->filters->get('cluster_id'));
        }
        if ($this->filters->get('collector_id')) {
            $query->whereIn('clusters.id', DB::table('households')->where('collector_id', $this->filters->get('collector_id'))->select('cluster_id'));
        }
        if ($this->filters->get('team_id')) {
            $query->whereIn('clusters.id', DB::table('households')->whereNotNull('cluster_id')
                ->whereIn('collector_id', DB::table('collectors')->where('team_id', $this->filters->get('team_id'))->select('id'))->select('cluster_id'));
        }

        return $query;
    }

    private function clusterStatus(): array
    {
        $clusters = $this->clusterQuery()->get(['clusters.id', 'clusters.target_households']);
        $done = $this->households()->whereNotNull('cluster_id')
            ->selectRaw('cluster_id, count(*) as visited, sum(case when consented then 1 else 0 end) as surveyed')
            ->groupBy('cluster_id')->get()->keyBy('cluster_id');

        $started = $completed = 0;
        foreach ($clusters as $cluster) {
            $row = $done[$cluster->id] ?? null;
            if (! $row || ! $row->visited) {
                continue;
            }
            if ($cluster->target_households && $row->surveyed >= $cluster->target_households) {
                $completed++;
            } else {
                $started++;
            }
        }

        return ['total' => $clusters->count(), 'started' => $started, 'completed' => $completed, 'not_started' => $clusters->count() - $started - $completed];
    }

    public function daily(): array
    {
        $households = $this->households()->whereNotNull('interview_date')
            ->selectRaw('interview_date as d, count(*) as visited, sum(case when consented then 1 else 0 end) as surveyed')
            ->groupBy('interview_date')->orderBy('interview_date')->get()->keyBy(fn ($r) => substr($r->d, 0, 10));

        $caregivers = $this->filters->apply(DB::table('caregivers')->join('households', 'households.id', '=', 'caregivers.household_id'), 'households', 'interview_date')
            ->where('caregivers.consented', true)->selectRaw('households.interview_date as d, count(*) as n')->groupBy('households.interview_date')
            ->pluck('n', 'd')->mapWithKeys(fn ($n, $d) => [substr($d, 0, 10) => $n]);
        $children = $this->filters->apply(DB::table('children')->join('households', 'households.id', '=', 'children.household_id'), 'households', 'interview_date')
            ->where('children.eligible', true)->selectRaw('households.interview_date as d, count(*) as n')->groupBy('households.interview_date')
            ->pluck('n', 'd')->mapWithKeys(fn ($n, $d) => [substr($d, 0, 10) => $n]);

        $rows = [];
        $cumulative = ['households' => 0, 'caregivers' => 0, 'children' => 0];
        foreach ($households as $date => $row) {
            $cumulative['households'] += $row->surveyed;
            $cumulative['caregivers'] += $caregivers[$date] ?? 0;
            $cumulative['children'] += $children[$date] ?? 0;
            $rows[] = [
                'date' => CarbonImmutable::parse($date)->format('d/m/Y'),
                'visited' => (int) $row->visited,
                'households' => (int) $row->surveyed,
                'caregivers' => (int) ($caregivers[$date] ?? 0),
                'children' => (int) ($children[$date] ?? 0),
                'cum_households' => $cumulative['households'],
                'cum_caregivers' => $cumulative['caregivers'],
                'cum_children' => $cumulative['children'],
            ];
        }

        return $rows;
    }

    /** Répartition des statuts de visite par zone sanitaire ou par commune (barres empilées). */
    public function byLevel(string $table, string $column): array
    {
        $refused = self::statusValues('statut_refus', '1');
        $absent = self::statusValues('statut_absent', '2,4');
        $noChildren = self::statusValues('statut_sans_enfant', '3');

        $rows = $this->households()->join($table, "{$table}.id", '=', "households.{$column}")
            ->selectRaw("{$table}.name as label, households.survey_status as status, households.consented as consented, count(*) as n")
            ->groupBy("{$table}.name", 'households.survey_status', 'households.consented')->get();

        return $rows->groupBy('label')->map(function (Collection $group, $label) use ($refused, $absent, $noChildren) {
            $sum = fn (callable $filter) => (int) $group->filter($filter)->sum('n');

            return [
                'label' => $label,
                'surveyed' => $sum(fn ($r) => $r->consented),
                'refused' => $sum(fn ($r) => ! $r->consented && in_array((string) $r->status, $refused, true)),
                'absent' => $sum(fn ($r) => ! $r->consented && in_array((string) $r->status, $absent, true)),
                'no_children' => $sum(fn ($r) => ! $r->consented && in_array((string) $r->status, $noChildren, true)),
                'other' => $sum(fn ($r) => ! $r->consented && ! in_array((string) $r->status, [...$refused, ...$absent, ...$noChildren], true)),
            ];
        })->sortBy('label')->values()->all();
    }

    /** Productivité par agent, ou par binôme ($byTeam) : jours travaillés et durée moyenne calculés sur le binôme. */
    public function productivity(bool $byTeam = false): array
    {
        $query = $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id');
        [$id, $name] = $byTeam
            ? ['teams.id', DB::raw("coalesce(teams.code, 'Sans binôme') as code, teams.name as team_name")]
            : ['collectors.id', 'collectors.name'];
        if ($byTeam) {
            $query->leftJoin('teams', 'teams.id', '=', 'collectors.team_id');
        }

        return $query
            ->select($id.' as id', $name)
            ->selectRaw('count(*) as interviews, sum(case when households.consented then 1 else 0 end) as surveyed,
                count(distinct households.interview_date) as days,
                avg(case when households.consented and households.duration_minutes between 1 and 300 then households.duration_minutes end) as avg_duration')
            ->groupBy($byTeam ? ['teams.id', 'teams.code', 'teams.name'] : ['collectors.id', 'collectors.name'])->orderByDesc('interviews')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $byTeam ? ($r->id ? 'Binôme '.$r->code.' · '.$r->team_name : 'Sans binôme') : $r->name,
                'interviews' => (int) $r->interviews,
                'surveyed' => (int) $r->surveyed,
                'days' => (int) $r->days,
                'per_day' => $r->days ? round($r->interviews / $r->days, 1) : 0,
                'avg_duration' => $r->avg_duration !== null ? round((float) $r->avg_duration) : null,
            ])->all();
    }

    /**
     * Tableau Zone > Commune > Village > Grappe : lignes à plat avec clé et parent,
     * indicateurs agrégés à chaque niveau.
     */
    public function hierarchy(): array
    {
        $households = $this->households()->whereNotNull('cluster_id')
            ->selectRaw('cluster_id, count(*) as visited, sum(case when consented then 1 else 0 end) as surveyed')
            ->groupBy('cluster_id')->get()->keyBy('cluster_id');
        $caregivers = $this->filters->apply(DB::table('caregivers')->join('households', 'households.id', '=', 'caregivers.household_id'), 'households', 'interview_date')
            ->where('caregivers.consented', true)->selectRaw('households.cluster_id, count(*) as n')->groupBy('households.cluster_id')->pluck('n', 'cluster_id');
        $children = $this->filters->apply(DB::table('children')->join('households', 'households.id', '=', 'children.household_id'), 'households', 'interview_date')
            ->where('children.eligible', true)->selectRaw('households.cluster_id, count(*) as n')->groupBy('households.cluster_id')->pluck('n', 'cluster_id');
        $queries = $this->filters->apply(DB::table('data_queries'), 'data_queries', 'collection_date', false)
            ->whereIn('status', DataQuery::UNRESOLVED)->selectRaw('cluster_id, count(*) as n')->groupBy('cluster_id')->pluck('n', 'cluster_id');

        $clusters = $this->clusterQuery()
            ->leftJoin('villages', 'villages.id', '=', 'clusters.village_id')
            ->leftJoin('communes', 'communes.id', '=', DB::raw('coalesce(clusters.commune_id, villages.commune_id)'))
            ->leftJoin('health_zones', 'health_zones.id', '=', DB::raw('coalesce(clusters.health_zone_id, villages.health_zone_id)'))
            ->get([
                'clusters.id', 'clusters.code', 'clusters.name', 'clusters.number', 'clusters.target_households', 'clusters.target_children',
                'villages.id as village_id', 'villages.name as village', 'communes.id as commune_id', 'communes.name as commune',
                'health_zones.id as zone_id', 'health_zones.name as zone',
            ]);

        $nodes = [];
        $add = function (string $key, ?string $parent, int $level, string $label, array $metrics) use (&$nodes) {
            if (! isset($nodes[$key])) {
                $nodes[$key] = ['key' => $key, 'parent' => $parent, 'level' => $level, 'label' => $label,
                    'visited' => 0, 'households' => 0, 'caregivers' => 0, 'children' => 0, 'target' => 0, 'target_children' => 0, 'queries' => 0, 'filter' => []];
            }
            foreach ($metrics as $metric => $value) {
                $nodes[$key][$metric] += $value;
            }
        };

        foreach ($clusters as $c) {
            $metrics = [
                'visited' => (int) ($households[$c->id]->visited ?? 0),
                'households' => (int) ($households[$c->id]->surveyed ?? 0),
                'caregivers' => (int) ($caregivers[$c->id] ?? 0),
                'children' => (int) ($children[$c->id] ?? 0),
                'target' => (int) $c->target_households,
                'target_children' => (int) $c->target_children,
                'queries' => (int) ($queries[$c->id] ?? 0),
            ];
            $zoneKey = 'z'.($c->zone_id ?? 0);
            $communeKey = $zoneKey.'-c'.($c->commune_id ?? 0);
            $villageKey = $communeKey.'-v'.($c->village_id ?? 0);
            $add($zoneKey, null, 0, $c->zone ?? 'Zone non renseignée', $metrics);
            $add($communeKey, $zoneKey, 1, $c->commune ?? 'Commune non renseignée', $metrics);
            $add($villageKey, $communeKey, 2, $c->village ?? 'Village non renseigné', $metrics);
            $add($villageKey.'-g'.$c->id, $villageKey, 3, $c->number !== null ? sprintf('Grappe %02d (%s)', $c->number, $c->code) : $c->name.' ('.$c->code.')', $metrics);
            $nodes[$zoneKey]['filter'] = ['health_zone_id' => $c->zone_id];
            $nodes[$communeKey]['filter'] = ['commune_id' => $c->commune_id];
            $nodes[$villageKey]['filter'] = ['village_id' => $c->village_id];
            $nodes[$villageKey.'-g'.$c->id]['filter'] = ['cluster_id' => $c->id];
            $nodes[$villageKey.'-g'.$c->id]['url'] = route('clusters.show', $c->id);
        }

        foreach ($nodes as &$node) {
            $node['rate'] = $node['target'] > 0 ? round($node['households'] * 100 / $node['target'], 1) : null;
        }
        unset($node);

        // Tri : par niveau puis libellé, en conservant l'ordre parent → enfants.
        $children = [];
        foreach ($nodes as $node) {
            $children[$node['parent'] ?? 'root'][] = $node;
        }
        $ordered = [];
        $walk = function (string $parent) use (&$walk, &$children, &$ordered) {
            $items = $children[$parent] ?? [];
            usort($items, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
            foreach ($items as $item) {
                $item['has_children'] = isset($children[$item['key']]);
                $ordered[] = $item;
                $walk($item['key']);
            }
        };
        $walk('root');

        return $ordered;
    }

    public function points(): array
    {
        return $this->households()->whereNotNull('households.latitude')->whereNotNull('households.longitude')
            ->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->leftJoin('collectors', 'collectors.id', '=', 'households.collector_id')
            ->leftJoin('teams', 'teams.id', '=', 'collectors.team_id')
            ->orderByDesc('households.id')->limit(5000)
            ->get(['households.id', 'households.latitude as lat', 'households.longitude as lng', 'households.household_code as code',
                'households.consented', 'households.interview_date', 'households.sub_cluster', 'clusters.id as cluster_id', 'clusters.code as cluster',
                'collectors.id as collector_id', 'collectors.name as collector', 'teams.id as team_id', 'teams.code as team'])
            ->map(fn ($p) => [
                'id' => $p->id, 'lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'code' => $p->code, 'consented' => (bool) $p->consented,
                'date' => $p->interview_date ? CarbonImmutable::parse($p->interview_date)->format('d/m/Y') : null,
                'cluster_id' => $p->cluster_id, 'cluster' => $p->cluster, 'sub_cluster' => $p->sub_cluster,
                'collector_id' => $p->collector_id, 'collector' => $p->collector, 'team_id' => $p->team_id, 'team' => $p->team,
            ])->all();
    }

    /** Contours GeoJSON des grappes (et de leurs sous-grappes) de l'univers filtré. */
    public function boundaries(): array
    {
        $clusters = $this->clusterQuery()
            ->where(fn ($q) => $q->whereNotNull('clusters.boundary')
                ->orWhereExists(fn ($s) => $s->from('sub_clusters')->whereColumn('sub_clusters.cluster_id', 'clusters.id')->whereNotNull('sub_clusters.boundary')))
            ->get(['clusters.id', 'clusters.code', 'clusters.name', 'clusters.boundary']);
        $subClusters = DB::table('sub_clusters')->whereIn('cluster_id', $clusters->pluck('id'))->whereNotNull('boundary')
            ->orderBy('number')->get(['cluster_id', 'number', 'boundary'])->groupBy('cluster_id');

        return $clusters->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'url' => route('clusters.show', $c->id),
            'boundary' => $c->boundary ? json_decode($c->boundary, true) : null,
            'sub_clusters' => ($subClusters[$c->id] ?? collect())->map(fn ($s) => ['number' => $s->number, 'boundary' => json_decode($s->boundary, true)])->values()->all(),
        ])->all();
    }
}
