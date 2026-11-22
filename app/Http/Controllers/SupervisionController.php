<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use App\Models\DataQuery;
use App\Models\FieldIncident;
use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Models\TeamMessage;
use App\Services\Dashboard\SupervisionStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Espace superviseur : vue simplifiée de la collecte dans la zone (ou la commune) du superviseur.
 * Les autres profils choisissent la zone à afficher.
 */
class SupervisionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);

        if ($scope = $user->geographicScope()) {
            [$column, $id] = [$scope['column'], (int) $scope['id']];
            $zoneId = $column === 'commune_id' ? Commune::whereKey($id)->value('health_zone_id') : $id;
            $title = $column === 'commune_id' ? 'Commune de '.Commune::whereKey($id)->value('name') : HealthZone::whereKey($id)->value('name');
        } else {
            $zoneId = $request->integer('health_zone_id') ?: $zones->first()?->id;
            abort_unless($zoneId, 404, 'Aucune zone sanitaire chargée.');
            [$column, $id] = ['health_zone_id', $zoneId];
            $title = $zones->firstWhere('id', $zoneId)?->name ?? HealthZone::whereKey($zoneId)->value('name');
        }

        $today = CarbonImmutable::today();
        $stats = new SupervisionStats($column, $id, $zoneId, $today);

        return view('supervision.index', [
            'title' => $title,
            'scoped' => (bool) $scope,
            'zones' => $zones,
            'zoneId' => $zoneId,
            'today' => $today,
            'kpis' => $stats->kpis(),
            'daily' => $stats->daily(),
            'teams' => $stats->teams(),
            'villages' => $stats->villages(),
            'incidents' => FieldIncident::where($column, $id)->whereIn('status', FieldIncident::UNRESOLVED)
                ->with(['village:id,name', 'cluster:id,code', 'collector:id,name'])->orderByDesc('occurred_on')->orderByDesc('id')->limit(10)->get(),
            'incidentFilter' => [$column => $id],
            // Queries à traiter par code de règle (filtre vers la liste des queries).
            'ruleCounts' => DataQuery::where($column, $id)->whereIn('status', DataQuery::UNRESOLVED)
                ->selectRaw('rule_code, count(*) as n')->groupBy('rule_code')->orderBy('rule_code')->pluck('n', 'rule_code'),
            'ruleNames' => QualityRule::pluck('name', 'code'),
            'messages' => $scope ? TeamMessage::visibleTo($user)->unreadBy($user)->with('sender:id,name')->latest('id')->limit(5)->get() : collect(),
        ]);
    }
}
