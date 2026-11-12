<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\User;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Fiche d'une grappe : contour et sous-grappes sur la carte, ménages visités, répartition par sous-grappe. */
class ClusterController extends Controller
{
    public function show(Request $request, Cluster $cluster): View
    {
        $scope = $request->user()->geographicScope();
        abort_if($scope && (int) $cluster->{$scope['column']} !== (int) $scope['id'], 403);
        // Compte binôme : seulement les grappes où ses agents ont travaillé (et seulement ses ménages, via visibleTo).
        abort_if($request->user()->hasRole(User::ROLE_TEAM) && ! Household::visibleTo($request->user())->where('cluster_id', $cluster->id)->exists(), 403);

        $cluster->load(['village.arrondissement', 'commune', 'healthZone', 'subClusters']);
        $households = Household::visibleTo($request->user())->where('cluster_id', $cluster->id)
            ->with(['collector:id,name,team_id', 'collector.team:id,code,name'])
            ->withCount([
                'children as eligible_children' => fn ($q) => $q->where('eligible', true),
                'queries as open_queries' => fn ($q) => $q->whereIn('status', DataQuery::UNRESOLVED),
            ])
            ->orderBy('household_code')->get();

        $dictionary = Dictionary::current();
        $statusList = $dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;
        $statusLabel = fn (?string $status) => $status === null ? '—' : ($statusList ? $dictionary->choiceLabel($statusList, $status) : null) ?? $status;
        $values = fn (string $key, string $default) => array_map('trim', explode(',', Map::value($key, $default)));
        $refused = $values('statut_refus', '1');
        $absent = $values('statut_absent', '2,4');
        $noChildren = $values('statut_sans_enfant', '3');

        $summarize = fn ($group) => [
            'visited' => $group->count(),
            'surveyed' => $group->where('consented', true)->count(),
            'no_children' => $group->filter(fn ($h) => ! $h->consented && in_array($h->survey_status, $noChildren, true))->count(),
            'refused' => $group->filter(fn ($h) => ! $h->consented && in_array($h->survey_status, $refused, true))->count(),
            'absent' => $group->filter(fn ($h) => ! $h->consented && in_array($h->survey_status, $absent, true))->count(),
            'children' => $group->sum('eligible_children'),
        ];

        $subClusterNumbers = $households->pluck('sub_cluster')->merge($cluster->subClusters->pluck('number'))->unique()->sort()->values();
        $bySubCluster = $subClusterNumbers->map(fn ($n) => ['number' => $n] + $summarize($households->where('sub_cluster', $n)))->all();

        return view('clusters.show', [
            'cluster' => $cluster,
            'households' => $households,
            'totals' => $summarize($households),
            'bySubCluster' => $bySubCluster,
            'statusLabel' => $statusLabel,
            'mapData' => [
                'boundaries' => [[
                    'code' => $cluster->code,
                    'name' => $cluster->name,
                    'boundary' => $cluster->boundary,
                    'sub_clusters' => $cluster->subClusters->whereNotNull('boundary')
                        ->map(fn ($s) => ['number' => $s->number, 'boundary' => $s->boundary])->values()->all(),
                ]],
                'points' => $households->whereNotNull('latitude')->map(fn ($h) => [
                    'lat' => $h->latitude,
                    'lng' => $h->longitude,
                    'code' => $h->household_code,
                    'sub_cluster' => $h->sub_cluster,
                    'consented' => $h->consented,
                    'status' => $statusLabel($h->survey_status),
                    'url' => route('households.show', $h),
                ])->values()->all(),
            ],
        ]);
    }
}
