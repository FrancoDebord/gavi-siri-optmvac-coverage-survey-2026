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
 */
final class RemainingVillages
{
    /** @return array<int, int> village => ménages visités (base nettoyée) */
    public static function visitedCounts(): array
    {
        return Household::whereNotNull('village_id')->selectRaw('village_id, count(*) as n')->groupBy('village_id')
            ->pluck('n', 'village_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Villages sans ménage visité, par zone sanitaire.
     *
     * @return Collection<string, Collection<int, array{village: string, teams: list<string>, clusters: int, enumerated: int}>>
     */
    public static function build(?int $zoneId = null): Collection
    {
        $visited = self::visitedCounts();
        $teamsOf = [];
        foreach (Team::with(['collectors:id,team_id,name', 'villages:villages.id'])->get() as $team) {
            foreach ($team->villages as $village) {
                $teamsOf[$village->id][] = TeamVillageCheck::teamLabel($team);
            }
        }
        // Grappes prévues et ménages dénombrés par village dans la base de sondage.
        $frame = [];
        foreach (ClusterFrame::get(['village_ids', 'households_enumerated']) as $row) {
            foreach ($row->village_ids ?? [] as $id) {
                $frame[$id]['clusters'] = ($frame[$id]['clusters'] ?? 0) + 1;
                $frame[$id]['enumerated'] = ($frame[$id]['enumerated'] ?? 0) + (int) $row->households_enumerated;
            }
        }
        $ids = array_unique([...array_keys($teamsOf), ...array_keys($frame), ...DB::table('team_village')->pluck('village_id')->all()]);

        return Village::with('healthZone:id,name')->whereIn('id', $ids)->when($zoneId, fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->orderBy('name')->get()
            ->reject(fn (Village $v) => ($visited[$v->id] ?? 0) > 0)
            ->map(fn (Village $v) => [
                'zone' => $v->healthZone?->name ?? '—',
                'village' => $v->name,
                'teams' => $teamsOf[$v->id] ?? [],
                'clusters' => $frame[$v->id]['clusters'] ?? 0,
                'enumerated' => $frame[$v->id]['enumerated'] ?? 0,
            ])
            ->sortBy([['zone', 'asc'], [fn ($a, $b) => strcmp($a['teams'][0] ?? 'zzz', $b['teams'][0] ?? 'zzz')], ['village', 'asc']])
            ->groupBy('zone');
    }
}
