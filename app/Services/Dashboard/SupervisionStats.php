<?php

namespace App\Services\Dashboard;

use App\Models\Collector;
use App\Models\DataQuery;
use App\Models\FieldIncident;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs de l'espace superviseur, limités à un périmètre (zone sanitaire ou commune) :
 * chiffres du jour et cumulés, évolution journalière, binômes, villages et grappes.
 */
class SupervisionStats
{
    private array $refused;

    private array $absent;

    private array $noChildren;

    public function __construct(
        private readonly string $column,
        private readonly int $id,
        private readonly ?int $zoneId,
        private readonly CarbonImmutable $today,
    ) {
        $this->refused = ProgressStats::statusValues('statut_refus', '1');
        $this->absent = ProgressStats::statusValues('statut_absent', '2,4');
        $this->noChildren = ProgressStats::statusValues('statut_sans_enfant', '3');
    }

    private function households()
    {
        return DB::table('households')->where("households.{$this->column}", $this->id);
    }

    /** Agrégats de statut communs à toutes les vues (visités, enquêtés, sans enfant, refus, absents). */
    private function statusSums(string $table = 'households'): string
    {
        $in = fn (array $values) => implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), $values ?: ['__']));

        return "count(*) as visited,
            sum(case when {$table}.consented then 1 else 0 end) as surveyed,
            sum(case when not {$table}.consented and {$table}.survey_status in ({$in($this->noChildren)}) then 1 else 0 end) as no_children,
            sum(case when not {$table}.consented and {$table}.survey_status in ({$in($this->refused)}) then 1 else 0 end) as refused,
            sum(case when not {$table}.consented and {$table}.survey_status in ({$in($this->absent)}) then 1 else 0 end) as absent";
    }

    public function kpis(): array
    {
        $total = $this->households()->selectRaw($this->statusSums())->first();
        $today = $this->households()->whereDate('interview_date', $this->today)->selectRaw($this->statusSums())->first();
        $children = DB::table('children')->join('households', 'households.id', '=', 'children.household_id')
            ->where("households.{$this->column}", $this->id)->where('children.eligible', true);

        return [
            'visited' => (int) $total->visited,
            'surveyed' => (int) $total->surveyed,
            'no_children' => (int) $total->no_children,
            'refused' => (int) $total->refused,
            'absent' => (int) $total->absent,
            'visited_today' => (int) $today->visited,
            'surveyed_today' => (int) $today->surveyed,
            'children' => (clone $children)->count(),
            'children_today' => (clone $children)->whereDate('households.interview_date', $this->today)->count(),
            'clusters' => $this->households()->whereNotNull('cluster_id')->distinct()->count('cluster_id'),
            'collectors_today' => $this->households()->whereDate('interview_date', $this->today)->whereNotNull('collector_id')->distinct()->count('collector_id'),
            'teams_today' => $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')
                ->whereDate('households.interview_date', $this->today)->whereNotNull('collectors.team_id')->distinct()->count('collectors.team_id'),
            'open_queries' => DataQuery::where($this->column, $this->id)->whereIn('status', DataQuery::UNRESOLVED)->count(),
            'open_incidents' => FieldIncident::where($this->column, $this->id)->whereIn('status', FieldIncident::UNRESOLVED)->count(),
            'last_submission' => ($last = $this->households()->max('submitted_at')) ? CarbonImmutable::parse($last)->format('d/m/Y H:i') : null,
        ];
    }

    /** @return list<array{date: string, visited: int, surveyed: int, cum_surveyed: int}> */
    public function daily(): array
    {
        $cumulative = 0;

        return $this->households()->whereNotNull('interview_date')
            ->selectRaw('interview_date as d, count(*) as visited, sum(case when consented then 1 else 0 end) as surveyed')
            ->groupBy('interview_date')->orderBy('interview_date')->get()
            ->map(function ($row) use (&$cumulative) {
                $cumulative += (int) $row->surveyed;

                return [
                    'date' => CarbonImmutable::parse($row->d)->format('d/m'),
                    'visited' => (int) $row->visited,
                    'surveyed' => (int) $row->surveyed,
                    'cum_surveyed' => $cumulative,
                ];
            })->all();
    }

    /**
     * Indicateurs de travail regroupés par agent (collectors.id) ou par binôme (collectors.team_id).
     * Jours travaillés et durée moyenne sont calculés sur le groupe, pas additionnés.
     *
     * @return array{0: Collection<int|string, object>, 1: Collection<int|string, int>} statistiques et queries ouvertes par clé
     */
    private function performanceBy(string $key): array
    {
        $stats = $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->selectRaw("{$key} as k, ".$this->statusSums().',
                sum(case when date(households.interview_date) = ? then 1 else 0 end) as today,
                count(distinct households.interview_date) as days,
                count(distinct households.cluster_id) as clusters,
                avg(case when households.consented and households.duration_minutes between 1 and 300 then households.duration_minutes end) as avg_duration,
                max(households.submitted_at) as last_submission', [$this->today->toDateString()])
            ->groupBy($key)->get()->keyBy(fn ($r) => $r->k ?? 0);
        $queries = DB::table('data_queries')->join('collectors', 'collectors.id', '=', 'data_queries.collector_id')
            ->where("data_queries.{$this->column}", $this->id)->whereIn('data_queries.status', DataQuery::UNRESOLVED)
            ->selectRaw("{$key} as k, count(*) as n")->groupBy($key)->get()->pluck('n', 'k');

        return [$stats, $queries];
    }

    private static function performanceRow(?object $s, int $queries): array
    {
        return [
            'today' => (int) ($s->today ?? 0),
            'visited' => (int) ($s->visited ?? 0),
            'surveyed' => (int) ($s->surveyed ?? 0),
            'no_children' => (int) ($s->no_children ?? 0),
            'refused' => (int) ($s->refused ?? 0) + (int) ($s->absent ?? 0),
            'days' => (int) ($s->days ?? 0),
            'per_day' => ($s->days ?? 0) ? round($s->visited / $s->days, 1) : null,
            'clusters' => (int) ($s->clusters ?? 0),
            'avg_duration' => isset($s->avg_duration) ? (int) round((float) $s->avg_duration) : null,
            'open_queries' => $queries,
            'last_submission' => isset($s->last_submission) ? CarbonImmutable::parse($s->last_submission) : null,
        ];
    }

    /**
     * Agents de la zone (y compris ceux qui n'ont encore rien envoyé), du plus actif au moins actif.
     *
     * @return Collection<int, object>
     */
    public function collectors(): Collection
    {
        [$stats, $queries] = $this->performanceBy('collectors.id');

        return Collector::query()
            ->where(fn ($q) => $q->whereIn('id', $stats->keys())->when($this->zoneId, fn ($q) => $q->orWhere('health_zone_id', $this->zoneId)))
            ->orderBy('name')->get(['id', 'name', 'code', 'team_id'])
            ->map(fn ($collector) => (object) ([
                'id' => $collector->id,
                'name' => $collector->name,
                'code' => $collector->code,
                'team_id' => $collector->team_id,
            ] + self::performanceRow($stats[$collector->id] ?? null, (int) ($queries[$collector->id] ?? 0))))
            ->sortByDesc(fn ($c) => [$c->today, $c->visited])->values();
    }

    /**
     * Binômes de la zone avec leurs indicateurs communs et le détail de leurs deux agents ;
     * les agents sans binôme sont regroupés à la fin.
     *
     * @return Collection<int, object>
     */
    public function teams(): Collection
    {
        [$stats, $queries] = $this->performanceBy('collectors.team_id');
        $agents = $this->collectors()->groupBy(fn ($c) => $c->team_id ?? 0);

        return Team::query()
            ->where(fn ($q) => $q->whereIn('id', $agents->keys())->when($this->zoneId, fn ($q) => $q->orWhere('health_zone_id', $this->zoneId)))
            ->get(['id', 'code', 'name'])
            ->map(fn ($team) => (object) ([
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
                'agents' => ($agents[$team->id] ?? collect())->sortBy('code', SORT_NATURAL)->values()->all(),
            ] + self::performanceRow($stats[$team->id] ?? null, (int) ($queries[$team->id] ?? 0))))
            ->sortByDesc(fn ($t) => [$t->today, $t->visited])
            ->when(isset($agents[0]), fn ($teams) => $teams->push((object) ([
                'id' => null, 'code' => null, 'name' => 'Agents sans binôme', 'agents' => $agents[0]->all(),
            ] + self::performanceRow($stats[0] ?? null, (int) ($queries[0] ?? 0)))))
            ->values();
    }

    /**
     * Villages et leurs grappes, avec les indicateurs de chaque grappe et le total du village.
     *
     * @return Collection<int, object{name: string, totals: array, clusters: list<object>}>
     */
    public function villages(): Collection
    {
        $children = DB::table('children')->join('households', 'households.id', '=', 'children.household_id')
            ->where("households.{$this->column}", $this->id)->where('children.eligible', true)
            ->selectRaw('households.cluster_id, count(*) as n')->groupBy('households.cluster_id')->pluck('n', 'cluster_id');
        $queries = DataQuery::where($this->column, $this->id)->whereIn('status', DataQuery::UNRESOLVED)
            ->selectRaw('cluster_id, count(*) as n')->groupBy('cluster_id')->pluck('n', 'cluster_id');
        $subClusters = $this->households()->whereNotNull('sub_cluster')
            ->selectRaw('cluster_id, count(distinct sub_cluster) as n')->groupBy('cluster_id')->pluck('n', 'cluster_id');

        $rows = $this->households()->join('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->leftJoin('villages', 'villages.id', '=', 'clusters.village_id')
            ->selectRaw('clusters.id, clusters.code, clusters.name, clusters.number, clusters.target_households, villages.name as village, '
                .$this->statusSums().', max(households.interview_date) as last_visit')
            ->groupBy('clusters.id', 'clusters.code', 'clusters.name', 'clusters.number', 'clusters.target_households', 'villages.name')
            ->get();

        return $rows->map(fn ($r) => (object) [
            'id' => $r->id,
            'code' => $r->code,
            'label' => $r->number !== null ? sprintf('Grappe %02d', $r->number) : $r->name,
            'village' => $r->village ?? 'Village non renseigné',
            'visited' => (int) $r->visited,
            'surveyed' => (int) $r->surveyed,
            'no_children' => (int) $r->no_children,
            'refused' => (int) $r->refused + (int) $r->absent,
            'children' => (int) ($children[$r->id] ?? 0),
            'target' => $r->target_households ? (int) $r->target_households : null,
            'rate' => $r->target_households ? round($r->surveyed * 100 / $r->target_households) : null,
            'sub_clusters' => (int) ($subClusters[$r->id] ?? 0),
            'open_queries' => (int) ($queries[$r->id] ?? 0),
            'last_visit' => $r->last_visit ? CarbonImmutable::parse($r->last_visit) : null,
        ])
            ->groupBy('village')
            ->map(fn (Collection $clusters, string $village) => (object) [
                'name' => $village,
                'clusters' => $clusters->sortBy('code', SORT_NATURAL)->values()->all(),
                'totals' => collect(['visited', 'surveyed', 'no_children', 'refused', 'children', 'open_queries'])
                    ->mapWithKeys(fn ($key) => [$key => $clusters->sum($key)])->all(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
