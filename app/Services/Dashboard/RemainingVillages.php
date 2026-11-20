<?php

namespace App\Services\Dashboard;

use App\Models\ClusterFrame;
use App\Models\Household;
use App\Models\Team;
use App\Models\Village;
use App\Services\Quality\TeamVillageCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Villages à couvrir (attribués à un binôme ou présents dans la base de sondage) et ménages déjà visités dans chacun :
 * les villages restants sont ceux où aucun ménage n'a encore été visité, avec le ou les binômes qui doivent y aller.
 * Périmètre : zone sanitaire, commune (superviseur) ou liste de villages (binôme).
 */
final class RemainingVillages
{
    /**
     * @param  list<int>|null  $villageIds  villages d'un binôme
     */
    public function __construct(
        private readonly ?int $zoneId = null,
        private readonly ?int $communeId = null,
        private readonly ?array $villageIds = null,
    ) {}

    /** @return array<int, int> village => ménages visités (base nettoyée) */
    public static function visitedCounts(): array
    {
        return Household::whereNotNull('village_id')->selectRaw('village_id, count(*) as n')->groupBy('village_id')
            ->pluck('n', 'village_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Villages sans ménage visité, par zone sanitaire (compatibilité : sans périmètre ou par zone).
     *
     * @return Collection<string, Collection<int, array{village: string, teams: list<string>, clusters: int, enumerated: int}>>
     */
    public static function build(?int $zoneId = null): Collection
    {
        return (new self($zoneId))->remaining();
    }

    /**
     * @return Collection<string, Collection<int, array{village: string, commune: ?string, teams: list<string>, clusters: int, enumerated: int}>>
     */
    public function remaining(): Collection
    {
        $visited = self::visitedCounts();
        [$villages, $teamsOf, $frame] = $this->candidates();

        return $villages->reject(fn (Village $v) => ($visited[$v->id] ?? 0) > 0)
            ->map(fn (Village $v) => [
                'zone' => $v->healthZone?->name ?? '—',
                'village' => $v->name,
                'commune' => $v->commune?->name,
                'teams' => $teamsOf[$v->id] ?? [],
                'clusters' => $frame[$v->id]['clusters'] ?? 0,
                'enumerated' => $frame[$v->id]['enumerated'] ?? 0,
                'per_cluster' => $frame[$v->id]['per_cluster'] ?? [],
            ])
            ->sortBy([['zone', 'asc'], [fn ($a, $b) => strcmp($a['teams'][0] ?? 'zzz', $b['teams'][0] ?? 'zzz')], ['village', 'asc']])
            ->groupBy('zone');
    }

    /** @return array{total: int, visited: int, remaining: int} villages à couvrir dans le périmètre */
    public function summary(): array
    {
        $visited = self::visitedCounts();
        [$villages] = $this->candidates();
        $done = $villages->filter(fn (Village $v) => ($visited[$v->id] ?? 0) > 0)->count();

        return ['total' => $villages->count(), 'visited' => $done, 'remaining' => $villages->count() - $done];
    }

    /** @return array{0: Collection<int, Village>, 1: array<int, list<string>>, 2: array<int, array{clusters: int, enumerated: int}>} */
    private function candidates(): array
    {
        $teamsOf = [];
        foreach (Team::with(['collectors:id,team_id,name', 'villages:villages.id'])->get() as $team) {
            foreach ($team->villages as $village) {
                $teamsOf[$village->id][] = TeamVillageCheck::teamLabel($team);
            }
        }
        // Base de sondage par village : nombre de grappes ; pour chaque grappe, subdivisions prévues et ménages dénombrés.
        $frame = [];
        foreach (ClusterFrame::orderBy('cluster_number')->get(['village_ids', 'cluster_number', 'subdivisions', 'households_enumerated']) as $row) {
            foreach ($row->village_ids ?? [] as $id) {
                $frame[$id]['clusters'] = ($frame[$id]['clusters'] ?? 0) + 1;
                $frame[$id]['enumerated'] = ($frame[$id]['enumerated'] ?? 0) + (int) $row->households_enumerated;
                $frame[$id]['per_cluster'][] = ['number' => (int) $row->cluster_number, 'subdivisions' => max(1, (int) $row->subdivisions), 'enumerated' => (int) $row->households_enumerated];
            }
        }
        $ids = $this->villageIds ?? array_unique([...array_keys($teamsOf), ...array_keys($frame), ...DB::table('team_village')->pluck('village_id')->all()]);
        $villages = Village::with(['healthZone:id,name', 'commune:id,name'])->whereIn('id', $ids ?: [0])
            ->when($this->zoneId, fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->when($this->communeId, fn ($q, $commune) => $q->where('commune_id', $commune))
            ->orderBy('name')->get();

        return [$villages, $teamsOf, $frame];
    }
}
