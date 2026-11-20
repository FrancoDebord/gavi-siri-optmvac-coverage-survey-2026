<?php

namespace App\Http\Controllers;

use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Team;
use App\Services\Dashboard\RemainingVillages;
use App\Services\Import\TeamVillageImporter;
use App\Services\Quality\TeamVillageCheck;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Villages des binômes : ménages dont le village coché n'est pas un village du binôme, ou dont l'agent sélectionné
 * n'est pas du binôme du compte ODK (règle TEAM_VILLAGE). Chaque cas est une query, traitée avec les binômes comme
 * les autres ; la page donne le diagnostic (agent, compte ODK, village coché, village de l'identifiant).
 */
class TeamVillageController extends Controller
{
    public function index(Request $request): View
    {
        $check = TeamVillageCheck::prepared();
        $resolution = array_key_exists((string) $request->input('resolution'), DataQuery::RESOLUTIONS) ? $request->input('resolution') : DataQuery::RESOLUTION_UNRESOLVED;
        $zoneId = $request->integer('health_zone_id') ?: null;
        $case = array_key_exists((string) $request->input('cas'), TeamVillageCheck::CASES) ? $request->input('cas') : null;
        $search = trim((string) $request->input('q'));

        $all = DataQuery::where('rule_code', 'TEAM_VILLAGE')
            ->when($zoneId, fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->search($search)
            ->with(['household', 'assignee:id,name', 'team.collectors:id,team_id,name'])
            ->orderBy('household_code')->get();

        // Diagnostic actuel de chaque ménage (null : plus d'incohérence, la query se fermera au prochain contrôle).
        $rows = $all->map(fn (DataQuery $query) => ['query' => $query, 'diagnosis' => $query->household ? $check->diagnose($query->household) : null]);
        $counts = $rows->filter(fn ($r) => in_array($r['query']->status, DataQuery::UNRESOLVED, true))
            ->countBy(fn ($r) => $r['diagnosis']['case'] ?? 'corrige');
        $rows = $rows->filter(fn ($r) => in_array($r['query']->status, DataQuery::RESOLUTIONS[$resolution]['statuses'], true))
            ->when($case, fn ($c) => $c->filter(fn ($r) => ($r['diagnosis']['case'] ?? null) === $case))->values();

        $accounts = collect(TeamVillageCheck::accountTeams())->map(fn ($a, $name) => $a + ['name' => $name, 'team' => $check->team($a['team_id'])]);

        // Villages attribués à chaque binôme (binomes_villages.csv), avec ses comptes ODK.
        $teams = Team::with(['villages:villages.id,villages.name', 'collectors:id,team_id,name', 'healthZone:id,name'])
            ->when($zoneId, fn ($q, $zone) => $q->where('health_zone_id', $zone))->orderBy('code')->get()
            ->map(fn (Team $team) => [
                'label' => TeamVillageCheck::teamLabel($team),
                'zone' => $team->healthZone?->name ?? '—',
                'villages' => $team->villages->pluck('name')->all(),
                'accounts' => $accounts->filter(fn ($a) => $a['team_id'] === $team->id)->keys()->all(),
            ])->groupBy('zone');

        return view('team-villages.index', [
            'teamLists' => $teams,
            // Villages où aucun ménage n'a encore été visité, avec les binômes qui doivent y aller.
            'remaining' => RemainingVillages::build($zoneId),
            'rows' => $rows,
            'counts' => $counts,
            'accounts' => $accounts,
            'cases' => TeamVillageCheck::CASES,
            'resolution' => $resolution,
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'filters' => ['health_zone_id' => $zoneId, 'cas' => $case, 'q' => $search],
            'listed' => $check->hasLists(),
            'file' => TeamVillageImporter::path(),
        ]);
    }
}
