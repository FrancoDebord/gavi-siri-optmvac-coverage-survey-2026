<?php

namespace App\Http\Controllers;

use App\Models\DataQuery;
use App\Models\HealthZone;
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
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('household_code', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%")))
            ->with(['household', 'assignee:id,name', 'team.collectors:id,team_id,name'])
            ->orderBy('household_code')->get();

        // Diagnostic actuel de chaque ménage (null : plus d'incohérence, la query se fermera au prochain contrôle).
        $rows = $all->map(fn (DataQuery $query) => ['query' => $query, 'diagnosis' => $query->household ? $check->diagnose($query->household) : null]);
        $counts = $rows->filter(fn ($r) => in_array($r['query']->status, DataQuery::UNRESOLVED, true))
            ->countBy(fn ($r) => $r['diagnosis']['case'] ?? 'corrige');
        $rows = $rows->filter(fn ($r) => in_array($r['query']->status, DataQuery::RESOLUTIONS[$resolution]['statuses'], true))
            ->when($case, fn ($c) => $c->filter(fn ($r) => ($r['diagnosis']['case'] ?? null) === $case))->values();

        $accounts = collect(TeamVillageCheck::accountTeams())->map(fn ($a, $name) => $a + ['name' => $name, 'team' => $check->team($a['team_id'])]);

        return view('team-villages.index', [
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
