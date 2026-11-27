<?php

namespace App\Services\Dashboard;

use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport d'activité journalier des binômes : pour chaque jour de la période, ménages visités et enquêtés, mères
 * (caregivers interrogés), enfants de moins de 5 ans recensés et enfants éligibles (12–35 mois), d'après la date
 * d'entretien des ménages (mêmes définitions que le tableau des taux de réponse). Un jour sans aucune donnée pour le
 * binôme est un jour « hors collecte » (préparation du terrain, contrôle qualité, correction des erreurs).
 */
class TeamActivityReport
{
    public const MEASURES = [
        'visited' => 'Ménages visités',
        'surveyed' => 'Ménages enquêtés',
        'mothers' => 'Mères d\'enfants',
        'under5' => 'Enfants de moins de 5 ans',
        'eligible' => 'Enfants éligibles',
    ];

    public const OFF_DAY = 'Préparation du terrain, contrôle qualité des données, correction des erreurs';

    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    /** Période par défaut : du premier au dernier jour de collecte (tous binômes confondus). */
    public static function defaultPeriod(): ?array
    {
        $range = DB::table('households')->whereNull('excluded_at')->whereNotNull('interview_date')
            ->selectRaw('min(interview_date) as first, max(interview_date) as last')->first();

        return $range?->first ? [CarbonImmutable::parse($range->first)->startOfDay(), CarbonImmutable::parse($range->last)->startOfDay()] : null;
    }

    /**
     * Un rapport par binôme : lignes journalières (null pour un jour sans données) et totaux.
     *
     * @param  Collection<int, Team>  $teams
     * @return Collection<int, array{team: Team, days: list<array>, totals: array<string, int>, active_days: int}>
     */
    public function build(Collection $teams): Collection
    {
        $teamIds = $teams->pluck('id')->all() ?: [0];
        $stats = $this->households($teamIds);
        $mothers = $this->grouped(DB::table('caregivers')->join('households', 'households.id', '=', 'caregivers.household_id')
            ->where('caregivers.consented', true), $teamIds, 'count(*) as mothers');
        $children = $this->grouped(DB::table('children')->join('households', 'households.id', '=', 'children.household_id'), $teamIds,
            'count(*) as under5, sum(case when children.eligible then 1 else 0 end) as eligible');

        $dates = collect(CarbonPeriod::create($this->from, $this->to))->map(fn ($d) => CarbonImmutable::parse($d));

        return $teams->map(function (Team $team) use ($stats, $mothers, $children, $dates) {
            $totals = array_fill_keys(array_keys(self::MEASURES), 0);
            $days = $dates->map(function (CarbonImmutable $date) use ($team, $stats, $mothers, $children, &$totals) {
                $key = $team->id.'|'.$date->toDateString();
                $household = $stats[$key] ?? null;
                $values = $household ? [
                    'visited' => (int) $household->visited,
                    'surveyed' => (int) $household->surveyed,
                    'mothers' => (int) ($mothers[$key]->mothers ?? 0),
                    'under5' => (int) ($children[$key]->under5 ?? 0),
                    'eligible' => (int) ($children[$key]->eligible ?? 0),
                ] : null;
                foreach ($values ?? [] as $measure => $value) {
                    $totals[$measure] += $value;
                }

                return ['date' => $date, 'values' => $values];
            })->all();

            return [
                'team' => $team,
                'days' => $days,
                'totals' => $totals,
                'active_days' => count(array_filter($days, fn ($d) => $d['values'] !== null)),
            ];
        })->values();
    }

    /** Ménages visités et enquêtés par binôme et par jour, indexés « binôme|date ». */
    private function households(array $teamIds): Collection
    {
        return $this->grouped(DB::table('households'), $teamIds,
            'count(*) as visited, sum(case when households.consented then 1 else 0 end) as surveyed');
    }

    private function grouped($query, array $teamIds, string $select): Collection
    {
        return $query->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->whereNull('households.excluded_at')->whereIn('collectors.team_id', $teamIds)
            ->whereNotNull('households.interview_date')
            ->whereDate('households.interview_date', '>=', $this->from->toDateString())
            ->whereDate('households.interview_date', '<=', $this->to->toDateString())
            ->selectRaw('collectors.team_id as team_id, date(households.interview_date) as d, '.$select)
            ->groupBy('collectors.team_id', DB::raw('date(households.interview_date)'))->get()
            ->keyBy(fn ($r) => $r->team_id.'|'.substr((string) $r->d, 0, 10));
    }
}
