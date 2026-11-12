<?php

namespace App\Services\Dashboard;

use App\Models\FieldDecision;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\Village;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport de synthèse de l'investigateur principal : chiffres clés (villages visités, ménages visités, ménages
 * enquêtés, enfants éligibles de 12 à 35 mois) par zone sanitaire, par zone et binôme, par zone et village,
 * par zone, binôme et date de collecte, puis les décisions de collecte. Les villages sont comptés sans doublon à chaque niveau.
 */
class PiSummary
{
    private const KEYS = [
        'zone' => 'households.health_zone_id',
        'team' => 'collectors.team_id',
        'date' => 'date(households.interview_date)',
        'village' => 'coalesce(clusters.village_id, households.village_id)',
    ];

    public function __construct(
        private readonly ?int $zoneId = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
    ) {}

    private function households()
    {
        return DB::table('households')
            ->leftJoin('collectors', 'collectors.id', '=', 'households.collector_id')
            ->leftJoin('clusters', 'clusters.id', '=', 'households.cluster_id')
            ->when($this->zoneId, fn ($q, $id) => $q->where('households.health_zone_id', $id))
            ->when($this->from, fn ($q, $d) => $q->whereDate('households.interview_date', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->whereDate('households.interview_date', '<=', $d));
    }

    /**
     * Chiffres clés groupés selon les niveaux demandés, indexés par « zone|binôme|date ».
     *
     * @param  list<string>  $levels  parmi zone, team, date
     * @return array<string, array{villages: int, visited: int, surveyed: int, children: int}>
     */
    private function aggregate(array $levels): array
    {
        $select = collect($levels)->map(fn ($l) => self::KEYS[$l]." as {$l}")->implode(', ');
        $groups = array_map(fn ($l) => DB::raw(self::KEYS[$l]), $levels);
        $prefix = $select !== '' ? $select.', ' : '';

        $households = $this->households()->selectRaw($prefix.'count(*) as visited,
                sum(case when households.consented then 1 else 0 end) as surveyed,
                count(distinct coalesce(clusters.village_id, households.village_id)) as villages')
            ->when($groups, fn ($q) => $q->groupBy($groups))->get();
        $children = $this->households()->join('children', 'children.household_id', '=', 'households.id')->where('children.eligible', true)
            ->selectRaw($prefix.'count(*) as n')->when($groups, fn ($q) => $q->groupBy($groups))->get();

        $key = fn ($row) => implode('|', array_map(fn ($l) => $l === 'date' ? substr((string) $row->{$l}, 0, 10) : (string) $row->{$l}, $levels));
        $childCounts = $children->mapWithKeys(fn ($r) => [$key($r) => (int) $r->n]);

        return $households->mapWithKeys(fn ($r) => [$key($r) => [
            'villages' => (int) $r->villages,
            'visited' => (int) $r->visited,
            'surveyed' => (int) $r->surveyed,
            'children' => $childCounts[$key($r)] ?? 0,
        ]])->all();
    }

    /**
     * @return array{total: array, zones: list<array{id: int|null, name: string, kpis: array, teams: list<array{label: string, kpis: array, days: list<array{date: string, label: string, kpis: array}>}>}>}
     */
    public function build(): array
    {
        $empty = ['villages' => 0, 'visited' => 0, 'surveyed' => 0, 'children' => 0];
        $total = $this->aggregate([])[''] ?? $empty;
        $byZone = $this->aggregate(['zone']);
        $byTeam = $this->aggregate(['zone', 'team']);
        $byDay = $this->aggregate(['zone', 'team', 'date']);
        $byVillage = $this->aggregate(['zone', 'village']);

        $zoneNames = HealthZone::pluck('name', 'id');
        $teams = Team::all()->keyBy('id');
        $villageNames = Village::whereIn('id', collect($byVillage)->keys()->map(fn ($k) => explode('|', $k)[1])->filter())->pluck('name', 'id');

        $teamLabel = fn (string $id) => $id === '' ? 'Sans binôme' : ($teams[$id] ?? null)?->label() ?? 'Binôme #'.$id;
        $villageRows = fn (string $zone) => collect($byVillage)->filter(fn ($v, $k) => explode('|', $k)[0] === $zone)
            ->map(fn (array $kpis, string $key) => [
                'name' => ($village = explode('|', $key)[1]) !== '' ? ($villageNames[$village] ?? 'Village #'.$village) : 'Village non renseigné',
                'kpis' => $kpis,
            ])
            ->sortBy([fn ($a, $b) => str_starts_with($a['name'], 'Village non') <=> str_starts_with($b['name'], 'Village non'), fn ($a, $b) => strnatcasecmp($a['name'], $b['name'])])
            ->values()->all();

        $zones = collect($byZone)->map(function (array $kpis, string $zone) use ($byTeam, $byDay, $zoneNames, $teamLabel, $villageRows) {
            $teamRows = collect($byTeam)->filter(fn ($v, $k) => explode('|', $k)[0] === $zone)
                ->map(function (array $kpis, string $key) use ($byDay, $teamLabel) {
                    $team = explode('|', $key)[1];
                    $days = collect($byDay)->filter(fn ($v, $k) => str_starts_with($k, $key.'|'))
                        ->map(fn (array $kpis, string $k) => ['date' => $d = explode('|', $k)[2], 'label' => $d !== '' ? CarbonImmutable::parse($d)->format('d/m/Y') : 'Date non renseignée', 'kpis' => $kpis])
                        ->sortBy('date')->values()->all();

                    return ['label' => $teamLabel($team), 'code' => $team, 'kpis' => $kpis, 'days' => $days];
                })
                ->sortBy([fn ($a, $b) => ($a['code'] === '') <=> ($b['code'] === ''), fn ($a, $b) => strnatcmp($a['label'], $b['label'])])->values()->all();

            return [
                'id' => $zone !== '' ? (int) $zone : null,
                'name' => $zone !== '' ? ($zoneNames[$zone] ?? 'Zone #'.$zone) : 'Zone non renseignée',
                'kpis' => $kpis,
                'teams' => $teamRows,
                'villages' => $villageRows($zone),
            ];
        })->sortBy('name')->values()->all();

        return ['total' => $total, 'zones' => $zones];
    }

    /**
     * Première et dernière date de collecte des ménages retenus (null si aucun ménage), et nombre de jours de collecte.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null, 2: int}
     */
    public function collectionDates(): array
    {
        $row = $this->households()->selectRaw('min(households.interview_date) as first_day, max(households.interview_date) as last_day,
                count(distinct date(households.interview_date)) as days')->first();

        return [
            $row?->first_day ? CarbonImmutable::parse($row->first_day)->startOfDay() : null,
            $row?->last_day ? CarbonImmutable::parse($row->last_day)->startOfDay() : null,
            (int) ($row?->days ?? 0),
        ];
    }

    /**
     * Décisions de collecte : générales et propres à la zone choisie, sur la période (date de décision).
     * $inSummary : true = affichées dans le rapport, false = masquées, null = toutes.
     */
    public function decisions(?bool $inSummary = true): Collection
    {
        return FieldDecision::with(['category', 'healthZone:id,name', 'village:id,name', 'cluster:id,code'])
            ->when($inSummary !== null, fn ($q) => $q->where('in_summary', $inSummary))
            ->when($this->zoneId, fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('health_zone_id')->orWhere('health_zone_id', $id)))
            ->when($this->from, fn ($q, $d) => $q->whereDate('decided_on', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->whereDate('decided_on', '<=', $d))
            ->orderBy('decided_on')->orderBy('id')->get();
    }
}
