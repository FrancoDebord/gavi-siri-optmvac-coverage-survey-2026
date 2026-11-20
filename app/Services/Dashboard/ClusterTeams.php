<?php

namespace App\Services\Dashboard;

use App\Models\Collector;
use App\Models\Team;
use App\Services\Quality\TeamVillageCheck;
use Illuminate\Support\Facades\DB;

/**
 * Binôme(s) qui ont travaillé dans chaque grappe, d'après les ménages visités : binôme du compte ODK qui a soumis la
 * donnée s'il est connu, sinon binôme de l'agent sélectionné. Calculé une fois par requête (cartes des grappes).
 */
final class ClusterTeams
{
    /** @var array<int, list<array{code: string, label: string, households: int}>>|null */
    private static ?array $memo = null;

    /** @return list<array{code: string, label: string, households: int}> binômes de la grappe, du plus actif au moins actif */
    public static function for(int $clusterId): array
    {
        return self::all()[$clusterId] ?? [];
    }

    /** Libellés courts (« 46-47 (Kora & Barakatou) ») des binômes d'une grappe. */
    public static function labels(int $clusterId): array
    {
        return array_column(self::for($clusterId), 'label');
    }

    /** @return array<int, list<array{code: string, label: string, households: int}>> */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $collectorTeams = Collector::whereNotNull('team_id')->pluck('team_id', 'id')->map(fn ($id) => (int) $id)->all();
        $accountTeams = collect(TeamVillageCheck::accountTeams($collectorTeams))->map(fn ($a) => $a['team_id'])->filter()->all();
        $teams = Team::with('collectors:id,team_id,name')->get()->keyBy('id');

        $counts = [];
        DB::table('households')->whereNull('excluded_at')->whereNotNull('cluster_id')
            ->selectRaw('cluster_id, submitter, collector_id, count(*) as n')->groupBy('cluster_id', 'submitter', 'collector_id')->get()
            ->each(function ($row) use (&$counts, $collectorTeams, $accountTeams) {
                $team = ($row->submitter !== null ? ($accountTeams[$row->submitter] ?? null) : null) ?? ($collectorTeams[$row->collector_id] ?? null);
                if ($team) {
                    $counts[$row->cluster_id][$team] = ($counts[$row->cluster_id][$team] ?? 0) + (int) $row->n;
                }
            });

        $result = [];
        foreach ($counts as $clusterId => $byTeam) {
            arsort($byTeam);
            foreach ($byTeam as $teamId => $n) {
                if ($team = $teams[$teamId] ?? null) {
                    $result[$clusterId][] = ['code' => $team->code, 'label' => TeamVillageCheck::teamLabel($team), 'households' => $n];
                }
            }
        }

        return self::$memo = $result;
    }

    /** Oublie le calcul (tests, traitements longs). */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
