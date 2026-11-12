<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\DataSource;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\Village;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Options des filtres en cascade, limitées au périmètre de l'utilisateur. */
class FilterOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $user->geographicScope();
        $locked = [];

        $zoneId = $request->integer('health_zone_id') ?: null;
        $communeId = $request->integer('commune_id') ?: null;
        $villageId = $request->integer('village_id') ?: null;

        if ($scope) {
            $zoneId = $user->health_zone_id ?? Commune::find($user->commune_id)?->health_zone_id;
            $locked[] = 'health_zone_id';
            if ($scope['column'] === 'commune_id') {
                $communeId = $user->commune_id;
                $locked[] = 'commune_id';
            }
        }

        $zones = HealthZone::when($scope, fn ($q) => $q->whereKey($zoneId))->orderBy('name')->get(['id', 'name']);
        $communes = Commune::when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
            ->when($scope && $scope['column'] === 'commune_id', fn ($q) => $q->whereKey($communeId))
            ->orderBy('name')->get(['id', 'name']);
        $villages = Village::when($communeId, fn ($q) => $q->where('commune_id', $communeId))
            ->when(! $communeId && $zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
            ->orderBy('name')->get(['id', 'name']);
        $clusters = Cluster::query()
            ->where(fn ($q) => $q->whereNotNull('target_households')->orWhereHas('households'))
            ->when($villageId, fn ($q) => $q->where('village_id', $villageId))
            ->when(! $villageId && $communeId, fn ($q) => $q->where('commune_id', $communeId))
            ->when(! $villageId && ! $communeId && $zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
            ->orderBy('name')->get(['id', 'name', 'code'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name.' ('.$c->code.')']);
        $teams = Team::when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->label()]);
        $collectors = Collector::when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
            ->when($request->integer('team_id'), fn ($q, $teamId) => $q->where('team_id', $teamId))
            ->orderBy('name')->get(['id', 'name']);

        $sources = DataSource::orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => (string) $s->id, 'name' => $s->name])
            ->push(['id' => 'import', 'name' => 'Imports de fichiers'])
            ->push(['id' => 'demo', 'name' => 'Données de démonstration']);

        return response()->json([
            'options' => [
                'health_zone_id' => $zones,
                'commune_id' => $communes,
                'village_id' => $villages,
                'cluster_id' => $clusters,
                'team_id' => $teams,
                'collector_id' => $collectors,
                'source' => $sources,
            ],
            'locked' => $locked,
        ]);
    }
}
