<?php

namespace App\Services\Dashboard;

use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\Team;
use App\Support\DashboardCache;
use App\Support\Filters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Agrégations du dashboard des queries (filtre de date = date de collecte). */
class QueryStats
{
    /**
     * @param  array{status?: string|null, severity?: string|null, rule_code?: string|null}  $extra
     */
    public function __construct(
        private readonly Filters $filters,
        private readonly array $extra = [],
        /** query (défaut) ou observation : les observations de supervision partagent la table. */
        private readonly string $kind = DataQuery::KIND,
    ) {}

    public function all(): array
    {
        return DashboardCache::remember('queries', $this->filters->cacheContext() + $this->extra + ['kind' => $this->kind], fn () => [
            'kpis' => $this->kpis(),
            'by_severity' => $this->bySeverity(),
            'by_status' => $this->byStatus(),
            'top_rules' => $this->topRules(),
            'by_variable' => $this->byVariable(),
            'rankings' => [
                'zone' => $this->ranking('health_zones', 'health_zone_id'),
                'commune' => $this->ranking('communes', 'commune_id'),
                'village' => $this->ranking('villages', 'village_id'),
                'cluster' => $this->ranking('clusters', 'cluster_id'),
                'team' => $this->teamRanking(),
                'collector' => $this->ranking('collectors', 'collector_id'),
            ],
            'heatmap' => $this->heatmap(),
            'generated_at' => now()->format('d/m/Y H:i:s'),
        ]);
    }

    public function queries()
    {
        $query = $this->filters->apply(DB::table('data_queries')->where('data_queries.kind', $this->kind), 'data_queries', 'collection_date', false);
        if ($this->filters->get('source')) {
            $this->filters->applyThroughHouseholds($query, 'data_queries.household_id');
        }
        if (! empty($this->extra['status'])) {
            $this->extra['status'] === 'non_resolues'
                ? $query->whereIn('data_queries.status', DataQuery::UNRESOLVED)
                : $query->where('data_queries.status', $this->extra['status']);
        }
        if (! empty($this->extra['severity'])) {
            $query->where('data_queries.severity', $this->extra['severity']);
        }
        if (! empty($this->extra['rule_code'])) {
            $query->where('data_queries.rule_code', $this->extra['rule_code']);
        }
        if (! empty($this->extra['resolution'])) {
            $query->whereIn('data_queries.status', DataQuery::RESOLUTIONS[$this->extra['resolution']]['statuses']);
        }
        if (! empty($this->extra['variable'])) {
            $query->where('data_queries.variable', 'like', str_replace(['%', '_'], ['\%', '\_'], $this->extra['variable']).'%');
        }
        if (! empty($this->extra['generated_from'])) {
            $query->where('data_queries.detected_at', '>=', $this->extra['generated_from'].' 00:00:00');
        }
        if (! empty($this->extra['generated_to'])) {
            $query->where('data_queries.detected_at', '<=', $this->extra['generated_to'].' 23:59:59');
        }
        // Assigné : « moi » (résolu par le contrôleur en identifiant), « aucun », ou un utilisateur.
        if (! empty($this->extra['assigned'])) {
            $this->extra['assigned'] === 'aucun'
                ? $query->whereNull('data_queries.assigned_to')
                : $query->where('data_queries.assigned_to', (int) $this->extra['assigned']);
        }
        if (! empty($this->extra['reviewer'])) {
            $query->where('data_queries.reviewer_id', (int) $this->extra['reviewer'])->whereIn('data_queries.status', DataQuery::RESPONSES);
        }
        if (! empty($this->extra['overdue'])) {
            $query->whereIn('data_queries.status', DataQuery::UNRESOLVED)->whereNotNull('data_queries.due_on')
                ->where('data_queries.due_on', '<', now()->toDateString());
        }
        if (! empty($this->extra['code'])) {
            $query->where('data_queries.id', $this->extra['code']);
        }

        return $query;
    }

    public function kpis(): array
    {
        $byStatus = $this->queries()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $count = fn (string $resolution) => (int) collect(DataQuery::RESOLUTIONS[$resolution]['statuses'])->sum(fn ($s) => $byStatus[$s] ?? 0);
        $open = $count(DataQuery::RESOLUTION_UNRESOLVED);
        $resolved = $count(DataQuery::RESOLUTION_RESOLVED);
        $households = $this->filters->apply(DB::table('households'), 'households', 'interview_date')->count();

        // Délai moyen de résolution (queries résolues uniquement) calculé en PHP, portable MySQL / PostgreSQL / SQLite.
        $delays = $this->queries()->whereNotNull('resolved_at')
            ->whereIn('data_queries.status', DataQuery::RESOLUTIONS[DataQuery::RESOLUTION_RESOLVED]['statuses'])
            ->limit(20000)->get(['detected_at', 'resolved_at'])
            ->map(fn ($q) => CarbonImmutable::parse($q->detected_at)->diffInHours(CarbonImmutable::parse($q->resolved_at)));

        return [
            'total' => $total,
            'open' => $open,
            'resolved' => $resolved,
            'unresolvable' => $count(DataQuery::RESOLUTION_UNRESOLVABLE),
            'validated' => (int) ($byStatus[DataQuery::STATUS_VALIDATED] ?? 0),
            'auto_closed' => (int) $this->queries()->where('auto_closed', true)->count(),
            'critical_open' => (int) $this->queries()->whereIn('status', DataQuery::UNRESOLVED)->where('severity', 'critique')->count(),
            'households' => $households,
            'per_100_households' => $households ? round($total * 100 / $households, 1) : null,
            'resolution_rate' => $total ? round($resolved * 100 / $total, 1) : null,
            'avg_resolution_hours' => $delays->isNotEmpty() ? round($delays->avg(), 1) : null,
        ];
    }

    public function bySeverity(): array
    {
        $rows = $this->queries()->selectRaw('severity, status, count(*) as n')->groupBy('severity', 'status')->get();

        return collect(QualityRule::SEVERITIES)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'open' => (int) $rows->where('severity', $key)->whereIn('status', DataQuery::UNRESOLVED)->sum('n'),
            'resolved' => (int) $rows->where('severity', $key)->whereNotIn('status', DataQuery::UNRESOLVED)->sum('n'),
        ])->values()->all();
    }

    public function byStatus(): array
    {
        $rows = $this->queries()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(DataQuery::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'n' => (int) ($rows[$key] ?? 0)])->values()->all();
    }

    public function topRules(): array
    {
        return $this->queries()->leftJoin('quality_rules', 'quality_rules.id', '=', 'data_queries.quality_rule_id')
            ->selectRaw('data_queries.rule_code as code, quality_rules.name as name, count(*) as n, sum(case when data_queries.status in (?, ?) then 1 else 0 end) as open', DataQuery::UNRESOLVED)
            ->groupBy('data_queries.rule_code', 'quality_rules.name')->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name ?? $r->code, 'n' => (int) $r->n, 'open' => (int) $r->open])->all();
    }

    public function byVariable(): array
    {
        return $this->queries()->whereNotNull('variable')
            ->selectRaw('variable, count(*) as n')->groupBy('variable')->orderByDesc('n')->limit(15)->get()
            ->map(fn ($r) => ['variable' => $r->variable, 'n' => (int) $r->n])->all();
    }

    /** Classement par niveau : total, ouvertes, critiques ouvertes et taux pour 100 ménages. */
    public function ranking(string $table, string $column): array
    {
        $households = $this->filters->apply(DB::table('households'), 'households', 'interview_date')
            ->selectRaw("{$column} as id, count(*) as n")->groupBy($column)->pluck('n', 'id');

        return $this->queries()->join($table, "{$table}.id", '=', "data_queries.{$column}")
            ->selectRaw("{$table}.id as id, {$table}.name as name, count(*) as total,
                sum(case when data_queries.status in (?, ?) then 1 else 0 end) as open,
                sum(case when data_queries.status in (?, ?) and data_queries.severity = 'critique' then 1 else 0 end) as critical", [...DataQuery::UNRESOLVED, ...DataQuery::UNRESOLVED])
            ->groupBy("{$table}.id", "{$table}.name")->orderByDesc('open')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'total' => (int) $r->total,
                'open' => (int) $r->open,
                'critical' => (int) $r->critical,
                'households' => (int) ($households[$r->id] ?? 0),
                'rate' => ($households[$r->id] ?? 0) ? round($r->total * 100 / $households[$r->id], 1) : null,
            ])->all();
    }

    /** Classement des binômes : queries et ménages de leurs deux agents. */
    public function teamRanking(): array
    {
        $households = $this->filters->apply(DB::table('households'), 'households', 'interview_date')
            ->join('collectors', 'collectors.id', '=', 'households.collector_id')->whereNotNull('collectors.team_id')
            ->selectRaw('collectors.team_id as id, count(*) as n')->groupBy('collectors.team_id')->pluck('n', 'id');

        return $this->queries()->join('collectors', 'collectors.id', '=', 'data_queries.collector_id')
            ->join('teams', 'teams.id', '=', 'collectors.team_id')
            ->selectRaw('teams.id as id, teams.code as code, teams.name as name, count(*) as total,
                sum(case when data_queries.status in (?, ?) then 1 else 0 end) as open,
                sum(case when data_queries.status in (?, ?) and data_queries.severity = \'critique\' then 1 else 0 end) as critical', [...DataQuery::UNRESOLVED, ...DataQuery::UNRESOLVED])
            ->groupBy('teams.id', 'teams.code', 'teams.name')->orderByDesc('open')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => Team::labelFor($r->code, $r->name),
                'total' => (int) $r->total,
                'open' => (int) $r->open,
                'critical' => (int) $r->critical,
                'households' => (int) ($households[$r->id] ?? 0),
                'rate' => ($households[$r->id] ?? 0) ? round($r->total * 100 / $households[$r->id], 1) : null,
            ])->all();
    }

    /** Heatmap binôme × jour de collecte (30 derniers jours au plus) ; agents sans binôme sous leur nom. */
    public function heatmap(): array
    {
        $rows = $this->queries()->join('collectors', 'collectors.id', '=', 'data_queries.collector_id')
            ->leftJoin('teams', 'teams.id', '=', 'collectors.team_id')
            ->whereNotNull('data_queries.collection_date')
            ->selectRaw('teams.code as team, collectors.name as agent, data_queries.collection_date as d, count(*) as n')
            ->groupBy('teams.code', 'collectors.name', 'data_queries.collection_date')->get()
            // Les deux agents d'un binôme sur une même ligne.
            ->groupBy(fn ($r) => ($r->team !== null ? 'Binôme '.$r->team : $r->agent).'|'.$r->d)
            ->map(fn ($g) => (object) [
                'collector' => $g->first()->team !== null ? 'Binôme '.$g->first()->team : $g->first()->agent,
                'd' => $g->first()->d,
                'n' => $g->sum('n'),
            ])->values();

        $days = $rows->pluck('d')->map(fn ($d) => substr($d, 0, 10))->unique()->sort()->values()->take(-30)->values();
        $collectors = $rows->pluck('collector')->unique()->sort()->values();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row->collector][substr($row->d, 0, 10)] = (int) $row->n;
        }

        return [
            'days' => $days->map(fn ($d) => CarbonImmutable::parse($d)->format('d/m'))->all(),
            'rows' => $collectors->map(fn ($c) => [
                'collector' => $c,
                'values' => $days->map(fn ($d) => $matrix[$c][$d] ?? 0)->all(),
            ])->all(),
            'max' => $rows->max('n') ?? 0,
        ];
    }
}
