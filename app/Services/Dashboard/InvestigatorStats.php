<?php

namespace App\Services\Dashboard;

use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Graphiques de l'investigateur principal (toutes zones, ou une zone) : point journalier par binôme,
 * évolution cumulée par village et par zone sanitaire, ménages sur la carte (recrutés ou non).
 */
class InvestigatorStats
{
    public function __construct(private readonly ?int $zoneId = null) {}

    private function households()
    {
        return DB::table('households')->when($this->zoneId, fn ($q, $id) => $q->where('households.health_zone_id', $id));
    }

    /** KPIs complémentaires : villages et grappes visités, activité du jour (ménages, enfants éligibles, binômes). */
    public function kpis(CarbonImmutable $today): array
    {
        $children = fn () => DB::table('children')->join('households', 'households.id', '=', 'children.household_id')->where('children.eligible', true)
            ->when($this->zoneId, fn ($q, $id) => $q->where('households.health_zone_id', $id));
        $day = $this->households()->whereDate('households.interview_date', $today)
            ->selectRaw('count(*) as visited, sum(case when consented then 1 else 0 end) as surveyed')->first();

        return [
            'villages' => $this->households()->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
                ->selectRaw('count(distinct coalesce(clusters.village_id, households.village_id)) as n')->value('n'),
            'clusters' => $this->households()->whereNotNull('cluster_id')->distinct()->count('cluster_id'),
            'teams' => $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')->whereNotNull('collectors.team_id')->distinct()->count('collectors.team_id'),
            'teams_today' => $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')->whereNotNull('collectors.team_id')
                ->whereDate('households.interview_date', $today)->distinct()->count('collectors.team_id'),
            'visited_today' => (int) $day->visited,
            'surveyed_today' => (int) $day->surveyed,
            'children_today' => $children()->whereDate('households.interview_date', $today)->count(),
            'days' => $this->days()->count(),
        ];
    }

    /** @return Collection<int, string> jours de collecte (Y-m-d), dans l'ordre */
    private function days(): Collection
    {
        return $this->households()->whereNotNull('interview_date')->selectRaw('date(interview_date) as d')->distinct()->orderBy('d')->pluck('d')
            ->map(fn ($d) => substr((string) $d, 0, 10))->values();
    }

    private static function label(string $day): string
    {
        return CarbonImmutable::parse($day)->format('d/m');
    }

    /**
     * Ménages visités et enquêtés par jour et par binôme (barres empilées).
     *
     * @return array{days: list<string>, series: list<array{label: string, zone: ?string, visited: list<int>, surveyed: list<int>}>}
     */
    public function dailyByTeam(): array
    {
        $days = $this->days();
        $rows = $this->households()->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->leftJoin('teams', 'teams.id', '=', 'collectors.team_id')
            ->leftJoin('health_zones', 'health_zones.id', '=', 'households.health_zone_id')
            ->whereNotNull('households.interview_date')
            ->selectRaw('date(households.interview_date) as d, teams.code, teams.name, collectors.name as agent, health_zones.name as zone,
                count(*) as visited, sum(case when households.consented then 1 else 0 end) as surveyed')
            ->groupBy(DB::raw('date(households.interview_date)'), 'teams.code', 'teams.name', 'collectors.name', 'health_zones.name')->get();

        $series = $rows->groupBy(fn ($r) => $r->code !== null ? Team::labelFor($r->code, $r->name) : 'Agent '.$r->agent)
            ->map(function (Collection $items, string $label) use ($days) {
                $byDay = $items->groupBy(fn ($r) => substr((string) $r->d, 0, 10));

                return [
                    'label' => $label,
                    'zone' => $items->first()->zone,
                    'visited' => $days->map(fn ($d) => (int) ($byDay[$d] ?? collect())->sum('visited'))->all(),
                    'surveyed' => $days->map(fn ($d) => (int) ($byDay[$d] ?? collect())->sum('surveyed'))->all(),
                ];
            })
            ->sortBy([['zone', 'asc'], ['label', 'asc']])->values()->all();

        return ['days' => $days->map(fn ($d) => self::label($d))->all(), 'series' => $series];
    }

    /**
     * Ménages enquêtés cumulés par jour, par village (grappes rattachées au village) ou par zone sanitaire.
     *
     * @return array{days: list<string>, series: list<array{label: string, values: list<int>, total: int}>}
     */
    public function cumulative(string $level, int $limit = 12): array
    {
        $days = $this->days();
        [$join, $name] = $level === 'village'
            ? [fn ($q) => $q->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
                ->leftJoin('villages', 'villages.id', '=', DB::raw('coalesce(clusters.village_id, households.village_id)')), 'villages.name']
            : [fn ($q) => $q->leftJoin('health_zones', 'health_zones.id', '=', 'households.health_zone_id'), 'health_zones.name'];

        $rows = $join($this->households())->whereNotNull('households.interview_date')->where('households.consented', true)
            ->selectRaw("{$name} as label, date(households.interview_date) as d, count(*) as n")
            ->groupBy($name, DB::raw('date(households.interview_date)'))->get();

        $series = $rows->groupBy(fn ($r) => $r->label ?? 'Non renseigné')
            ->map(function (Collection $items, string $label) use ($days) {
                $byDay = $items->keyBy(fn ($r) => substr((string) $r->d, 0, 10));
                $total = 0;

                return [
                    'label' => $label,
                    'values' => $days->map(function ($d) use ($byDay, &$total) {
                        $total += (int) ($byDay[$d]->n ?? 0);

                        return $total;
                    })->all(),
                    'total' => $total,
                ];
            })
            ->sortByDesc('total')->take($limit)->values()->all();

        return ['days' => $days->map(fn ($d) => self::label($d))->all(), 'series' => $series];
    }

    /** @return list<array{lat: float, lng: float, consented: bool, status: ?string, code: ?string, zone: ?string, village: ?string, cluster: ?string, team: ?string, date: ?string, url: string}> */
    public function householdPoints(): array
    {
        return $this->households()
            ->whereNotNull('households.latitude')->whereNotNull('households.longitude')
            ->leftJoin('health_zones', 'health_zones.id', '=', 'households.health_zone_id')
            ->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->leftJoin('villages', 'villages.id', '=', DB::raw('coalesce(clusters.village_id, households.village_id)'))
            ->leftJoin('collectors', 'collectors.id', '=', 'households.collector_id')
            ->leftJoin('teams', 'teams.id', '=', 'collectors.team_id')
            ->orderByDesc('households.id')->limit(10000)
            ->get(['households.id', 'households.latitude', 'households.longitude', 'households.household_code', 'households.interview_date', 'households.consented', 'households.survey_status',
                'health_zones.name as zone', 'villages.name as village', 'clusters.code as cluster', 'teams.code as team'])
            ->map(fn ($h) => [
                'lat' => (float) $h->latitude,
                'lng' => (float) $h->longitude,
                'consented' => (bool) $h->consented,
                'status' => $h->survey_status,
                'code' => $h->household_code,
                'zone' => $h->zone,
                'village' => $h->village,
                'cluster' => $h->cluster,
                'team' => $h->team,
                'date' => $h->interview_date ? CarbonImmutable::parse($h->interview_date)->format('d/m/Y') : null,
                'url' => route('households.show', $h->id),
            ])->all();
    }
}
