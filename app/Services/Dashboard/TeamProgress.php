<?php

namespace App\Services\Dashboard;

use App\Models\DataQuery;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Progression d'un binôme (ménages de ses deux agents), d'une zone sanitaire ou de toutes les zones :
 * KPIs du jour et cumulés, point journalier, détail par agent, par village et grappe, queries à corriger.
 * Sert à l'espace binôme, à l'onglet « Progression » de l'investigateur principal et aux rapports PI.
 */
class TeamProgress
{
    /** @var list<int>|null agents du binôme ; null = pas de restriction par agent */
    private ?array $collectorIds;

    private array $refused;

    private array $absent;

    private array $noChildren;

    public function __construct(
        public readonly ?Team $team,
        private readonly CarbonImmutable $today,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?int $zoneId = null,
    ) {
        $this->collectorIds = $team ? ($team->collectors()->pluck('id')->all() ?: [0]) : null;
        $this->refused = ProgressStats::statusValues('statut_refus', '1');
        $this->absent = ProgressStats::statusValues('statut_absent', '2,4');
        $this->noChildren = ProgressStats::statusValues('statut_sans_enfant', '3');
    }

    /** Progression d'une zone sanitaire, ou de toutes les zones ($zoneId null). */
    public static function forZone(?int $zoneId, CarbonImmutable $today): self
    {
        return new self(null, $today, null, null, $zoneId);
    }

    private function scoped($query, string $table)
    {
        return $query->when($this->collectorIds !== null, fn ($q) => $q->whereIn("{$table}.collector_id", $this->collectorIds))
            ->when($this->collectorIds === null && $this->zoneId, fn ($q) => $q->where("{$table}.health_zone_id", $this->zoneId));
    }

    private function dataQueries()
    {
        return $this->scoped(DataQuery::query(), 'data_queries');
    }

    private function households()
    {
        return $this->scoped(DB::table('households')->whereNull('households.excluded_at'), 'households')
            ->when($this->from, fn ($q, $d) => $q->whereDate('households.interview_date', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->whereDate('households.interview_date', '<=', $d));
    }

    private function eligibleChildren()
    {
        return $this->scoped(DB::table('children')->join('households', 'households.id', '=', 'children.household_id')->whereNull('households.excluded_at'), 'households')->where('children.eligible', true)
            ->when($this->from, fn ($q, $d) => $q->whereDate('households.interview_date', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->whereDate('households.interview_date', '<=', $d));
    }

    private function sums(): string
    {
        $in = fn (array $values) => implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), $values ?: ['__']));

        return "count(*) as visited,
            sum(case when households.consented then 1 else 0 end) as surveyed,
            sum(case when not households.consented and households.survey_status in ({$in($this->noChildren)}) then 1 else 0 end) as no_children,
            sum(case when not households.consented and households.survey_status in ({$in(array_merge($this->refused, $this->absent))}) then 1 else 0 end) as refused,
            count(distinct coalesce(households.village_id, 0)) as villages,
            count(distinct households.cluster_id) as clusters,
            avg(case when households.consented and households.duration_minutes between 1 and 300 then households.duration_minutes end) as avg_duration";
    }

    private static function row(?object $r, int $children): array
    {
        return [
            'visited' => (int) ($r->visited ?? 0),
            'surveyed' => (int) ($r->surveyed ?? 0),
            'no_children' => (int) ($r->no_children ?? 0),
            'refused' => (int) ($r->refused ?? 0),
            'villages' => (int) ($r->villages ?? 0),
            'clusters' => (int) ($r->clusters ?? 0),
            'children' => $children,
            'avg_duration' => isset($r->avg_duration) ? (int) round((float) $r->avg_duration) : null,
        ];
    }

    /** KPIs cumulés et du jour : villages, grappes, ménages visités et enquêtés, enfants éligibles (12–35 mois). */
    public function kpis(): array
    {
        $total = $this->households()->selectRaw($this->sums().', count(distinct households.interview_date) as days, max(households.submitted_at) as last_submission')->first();
        $today = $this->households()->whereDate('households.interview_date', $this->today)->selectRaw($this->sums())->first();
        $days = (int) $total->days;

        return [
            'total' => self::row($total, $this->eligibleChildren()->count()),
            'today' => self::row($today, $this->eligibleChildren()->whereDate('households.interview_date', $this->today)->count()),
            'days' => $days,
            'per_day' => $days ? round($total->visited / $days, 1) : null,
            'open_queries' => $this->dataQueries()->whereIn('status', DataQuery::UNRESOLVED)->count(),
            'last_submission' => $total->last_submission ? CarbonImmutable::parse($total->last_submission) : null,
        ];
    }

    /**
     * Point journalier : une ligne par jour de collecte, avec les villages et grappes visités ce jour-là.
     *
     * @return list<array>
     */
    public function daily(): array
    {
        $rows = $this->households()->whereNotNull('households.interview_date')
            ->selectRaw('date(households.interview_date) as d, '.$this->sums())
            ->groupBy(DB::raw('date(households.interview_date)'))->orderBy('d')->get();
        $children = $this->eligibleChildren()->selectRaw('date(households.interview_date) as d, count(*) as n')
            ->groupBy(DB::raw('date(households.interview_date)'))->pluck('n', 'd')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);
        $places = $this->households()->whereNotNull('households.interview_date')
            ->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->leftJoin('villages', 'villages.id', '=', DB::raw('coalesce(clusters.village_id, households.village_id)'))
            ->selectRaw('date(households.interview_date) as d, villages.name as village, clusters.code as cluster')
            ->distinct()->get()->groupBy(fn ($r) => substr((string) $r->d, 0, 10));
        $teams = $this->households()->whereNotNull('households.interview_date')->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->whereNotNull('collectors.team_id')->selectRaw('date(households.interview_date) as d, count(distinct collectors.team_id) as n')
            ->groupBy(DB::raw('date(households.interview_date)'))->pluck('n', 'd')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);
        $queries = $this->dataQueries()->whereNotNull('collection_date')
            ->selectRaw('date(collection_date) as d, count(*) as n, sum(case when status in (?, ?) then 1 else 0 end) as open', DataQuery::UNRESOLVED)
            ->groupBy(DB::raw('date(collection_date)'))->get()->keyBy(fn ($r) => substr((string) $r->d, 0, 10));

        $cumulative = ['visited' => 0, 'surveyed' => 0, 'children' => 0];

        return $rows->map(function ($r) use ($children, $places, $queries, $teams, &$cumulative) {
            $day = substr((string) $r->d, 0, 10);
            $row = self::row($r, $children[$day] ?? 0);
            foreach ($cumulative as $key => $value) {
                $cumulative[$key] = $value + $row[$key];
            }

            return $row + [
                'date' => $day,
                'label' => CarbonImmutable::parse($day)->format('d/m/Y'),
                'places' => ($places[$day] ?? collect())->groupBy(fn ($p) => $p->village ?? 'Village non renseigné')
                    ->map(fn (Collection $items, $village) => $village.' ('.$items->pluck('cluster')->filter()->unique()->sort()->implode(', ').')')->values()->implode(' ; '),
                'queries' => (int) ($queries[$day]->n ?? 0),
                'teams' => $teams[$day] ?? 0,
                'open_queries' => (int) ($queries[$day]->open ?? 0),
                'cum_visited' => $cumulative['visited'],
                'cum_surveyed' => $cumulative['surveyed'],
                'cum_children' => $cumulative['children'],
            ];
        })->all();
    }

    /** Totaux par agent du binôme. */
    public function agents(): array
    {
        $stats = $this->households()->selectRaw('households.collector_id, '.$this->sums().', count(distinct households.interview_date) as days')
            ->groupBy('households.collector_id')->get()->keyBy('collector_id');
        $children = $this->eligibleChildren()->selectRaw('households.collector_id, count(*) as n')->groupBy('households.collector_id')->pluck('n', 'collector_id');

        if (! $this->team) {
            return [];
        }

        return $this->team->collectors->map(fn ($agent) => [
            'name' => $agent->name,
            'code' => $agent->code,
            'days' => (int) ($stats[$agent->id]->days ?? 0),
        ] + self::row($stats[$agent->id] ?? null, (int) ($children[$agent->id] ?? 0)))->all();
    }

    /** Villages et grappes où le binôme a travaillé. */
    public function places(): array
    {
        $children = $this->eligibleChildren()->selectRaw('households.cluster_id, count(*) as n')->groupBy('households.cluster_id')->pluck('n', 'cluster_id');

        return $this->households()->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->leftJoin('villages', 'villages.id', '=', DB::raw('coalesce(clusters.village_id, households.village_id)'))
            ->selectRaw('households.cluster_id, clusters.code as cluster, villages.name as village, '.$this->sums().', min(households.interview_date) as first_visit, max(households.interview_date) as last_visit')
            ->groupBy('households.cluster_id', 'clusters.code', 'villages.name')->orderBy('villages.name')->orderBy('clusters.code')->get()
            ->map(fn ($r) => [
                'cluster_id' => $r->cluster_id,
                'cluster' => $r->cluster,
                'village' => $r->village ?? 'Village non renseigné',
                'first_visit' => $r->first_visit ? CarbonImmutable::parse($r->first_visit)->format('d/m') : null,
                'last_visit' => $r->last_visit ? CarbonImmutable::parse($r->last_visit)->format('d/m') : null,
            ] + self::row($r, (int) ($children[$r->cluster_id] ?? 0)))->all();
    }

    /** Queries non résolues des ménages du binôme, à corriger. */
    public function openQueries(int $limit = 50): Collection
    {
        return $this->dataQueries()->with('collector:id,name')->whereIn('status', DataQuery::UNRESOLVED)
            ->orderByRaw("case severity when 'critique' then 0 when 'majeure' then 1 else 2 end")->orderByDesc('collection_date')->limit($limit)->get();
    }
}
