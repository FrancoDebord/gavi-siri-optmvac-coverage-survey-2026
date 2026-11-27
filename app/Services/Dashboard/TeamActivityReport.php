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
 * Jours regroupés en semaines de collecte de 7 jours comptées depuis le début de la collecte (gavi.collection_start),
 * avec un sous-total par semaine et un total général.
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

    /** Premier jour de la collecte : début du rapport et de la semaine 1. */
    public static function collectionStart(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('gavi.collection_start', '2026-11-06'))->startOfDay();
    }

    /** Période par défaut : du début de la collecte au dernier jour de collecte (tous binômes confondus). */
    public static function defaultPeriod(): ?array
    {
        $last = DB::table('households')->whereNull('excluded_at')->whereNotNull('interview_date')->max('interview_date');
        if (! $last) {
            return null;
        }
        $start = self::collectionStart();
        $end = CarbonImmutable::parse($last)->startOfDay();

        return [$start, $end->lessThan($start) ? $start : $end];
    }

    /** Numéro de la semaine de collecte d'un jour (1 = les 7 premiers jours). */
    public static function weekNumber(CarbonImmutable $date): int
    {
        return intdiv((int) self::collectionStart()->diffInDays($date, false), 7) + 1;
    }

    /**
     * Un rapport par binôme : semaines (jours, null pour un jour sans données, et sous-total) et total général.
     *
     * @param  Collection<int, Team>  $teams
     * @return Collection<int, array{team: Team, weeks: list<array>, totals: array<string, int>, active_days: int}>
     */
    public function build(Collection $teams): Collection
    {
        $teamIds = $teams->pluck('id')->all() ?: [0];
        $stats = $this->grouped(DB::table('households'), $teamIds,
            'count(*) as visited, sum(case when households.consented then 1 else 0 end) as surveyed');
        $mothers = $this->grouped(DB::table('caregivers')->join('households', 'households.id', '=', 'caregivers.household_id')
            ->where('caregivers.consented', true), $teamIds, 'count(*) as mothers');
        $children = $this->grouped(DB::table('children')->join('households', 'households.id', '=', 'children.household_id'), $teamIds,
            'count(*) as under5, sum(case when children.eligible then 1 else 0 end) as eligible');

        $dates = collect(CarbonPeriod::create($this->from, $this->to))->map(fn ($d) => CarbonImmutable::parse($d));
        $empty = array_fill_keys(array_keys(self::MEASURES), 0);
        $add = function (array $sum, ?array $values) {
            foreach ($values ?? [] as $measure => $value) {
                $sum[$measure] += $value;
            }

            return $sum;
        };

        return $teams->map(function (Team $team) use ($stats, $mothers, $children, $dates, $empty, $add) {
            $days = $dates->map(function (CarbonImmutable $date) use ($team, $stats, $mothers, $children) {
                $key = $team->id.'|'.$date->toDateString();
                $household = $stats[$key] ?? null;

                return ['date' => $date, 'values' => $household ? [
                    'visited' => (int) $household->visited,
                    'surveyed' => (int) $household->surveyed,
                    'mothers' => (int) ($mothers[$key]->mothers ?? 0),
                    'under5' => (int) ($children[$key]->under5 ?? 0),
                    'eligible' => (int) ($children[$key]->eligible ?? 0),
                ] : null];
            });

            $weeks = $days->groupBy(fn ($day) => self::weekNumber($day['date']))->map(fn (Collection $items, int $number) => [
                'number' => $number,
                'from' => $items->first()['date'],
                'to' => $items->last()['date'],
                'days' => $items->values()->all(),
                'totals' => $items->reduce(fn ($sum, $day) => $add($sum, $day['values']), $empty),
                'active_days' => $items->whereNotNull('values')->count(),
            ])->values();

            return [
                'team' => $team,
                'weeks' => $weeks->all(),
                'totals' => $weeks->reduce(fn ($sum, $week) => $add($sum, $week['totals']), $empty),
                'active_days' => $weeks->sum('active_days'),
            ];
        })->values();
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
