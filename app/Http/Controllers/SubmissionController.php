<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Team;
use App\Models\Village;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Support\Filters;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Exploration des soumissions (équipe centrale, investigateur principal, superviseurs dans leur zone) :
 * filtres, liste avec position par rapport aux contours, et carte des ménages sur les contours des grappes.
 */
class SubmissionController extends Controller
{
    private const MAP_LIMIT = 3000;

    public function index(Request $request): View
    {
        $filters = Filters::fromRequest($request);
        $query = $this->query($request, $filters);

        $households = (clone $query)
            ->with(['healthZone:id,name', 'village:id,name', 'cluster' => fn ($q) => $q->select(['id', 'code', 'boundary'])->with('subClusters'), 'collector.team:id,code'])
            ->withCount([
                'children as eligible_children' => fn ($q) => $q->where('eligible', true),
                'queries as open_queries' => fn ($q) => $q->whereIn('status', DataQuery::UNRESOLVED),
            ])
            ->orderByDesc('interview_date')->orderByDesc('submitted_at')->paginate(50)->withQueryString();

        // Position de chaque ménage de la page par rapport au contour de sa grappe et de sa sous-grappe.
        $positions = $households->getCollection()->mapWithKeys(function (Household $h) {
            if ($h->latitude === null || ! $h->cluster) {
                return [$h->id => null];
            }
            $sub = $h->cluster->subClusters->firstWhere('number', $h->sub_cluster);

            return [$h->id => [
                'cluster' => $h->cluster->boundary ? Geo::locate($h->latitude, $h->longitude, $h->cluster->boundary) : null,
                'sub_cluster' => $sub?->boundary ? Geo::locate($h->latitude, $h->longitude, $sub->boundary) : null,
            ]];
        });

        $located = (clone $query)->whereNotNull('latitude')->whereNotNull('longitude');
        $clusterIds = (clone $query)->whereNotNull('cluster_id')->distinct()->limit(300)->pluck('cluster_id');
        $clusters = Cluster::whereIn('id', $clusterIds)->with('subClusters')
            ->where(fn ($q) => $q->whereNotNull('boundary')->orWhereHas('subClusters', fn ($s) => $s->whereNotNull('boundary')))->get();
        $statusLabel = $this->statusLabeler();

        return view('submissions.index', [
            'households' => $households,
            'positions' => $positions,
            'statusLabel' => $statusLabel,
            'totals' => (clone $query)->selectRaw('count(*) as n, sum(case when consented then 1 else 0 end) as surveyed')->first(),
            'options' => $this->options($request),
            'mapLimited' => (clone $located)->count() > self::MAP_LIMIT,
            'mapData' => [
                'points' => (clone $located)->with('cluster:id,code')->latest('id')->limit(self::MAP_LIMIT)
                    ->get(['id', 'latitude', 'longitude', 'household_code', 'consented', 'survey_status', 'sub_cluster', 'cluster_id', 'interview_date'])
                    ->map(fn ($h) => [
                        'lat' => $h->latitude, 'lng' => $h->longitude, 'consented' => $h->consented, 'code' => $h->household_code,
                        'cluster' => $h->cluster?->code, 'sub_cluster' => $h->sub_cluster, 'status' => $statusLabel($h->survey_status),
                        'date' => $h->interview_date?->format('d/m/Y'), 'url' => route('households.show', $h),
                    ])->values()->all(),
                'boundaries' => $clusters->map(fn ($c) => [
                    'code' => $c->code,
                    'name' => $c->name,
                    'url' => route('clusters.show', $c),
                    'boundary' => $c->boundary,
                    'sub_clusters' => $c->subClusters->whereNotNull('boundary')->map(fn ($s) => ['number' => $s->number, 'boundary' => $s->boundary])->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }

    private function query(Request $request, Filters $filters): Builder
    {
        $status = $request->input('statut');

        return $filters->apply(Household::visibleTo($request->user()), 'households', 'interview_date')
            ->when($status === 'enquetes', fn ($q) => $q->where('consented', true))
            ->when($status === 'non_enquetes', fn ($q) => $q->where('consented', false))
            ->when($status === 'avec_queries', fn ($q) => $q->whereHas('queries', fn ($s) => $s->whereIn('status', DataQuery::UNRESOLVED)))
            ->when($status === 'sans_gps', fn ($q) => $q->whereNull('latitude'))
            ->when(trim((string) $request->input('q')) !== '', fn ($q) => $q->where('household_code', 'like', '%'.trim((string) $request->input('q')).'%'));
    }

    /** Listes des filtres, limitées au périmètre de l'utilisateur (zone d'un superviseur). */
    private function options(Request $request): array
    {
        $zoneId = $request->user()->supervisedZoneId();
        $zones = HealthZone::whereHas('communes')->when($zoneId, fn ($q) => $q->whereKey($zoneId))->orderBy('name')->get(['id', 'name']);
        $zoneNames = $zones->pluck('name', 'id');

        return [
            'scoped' => $zoneId !== null,
            'zones' => $zones,
            'villages' => Village::whereIn('health_zone_id', $zones->pluck('id'))->whereHas('households')->orderBy('name')->get(['id', 'name', 'health_zone_id'])
                ->groupBy(fn ($v) => $zoneNames[$v->health_zone_id] ?? ''),
            'clusters' => Cluster::whereIn('health_zone_id', $zones->pluck('id'))->whereHas('households')->orderBy('code')->get(['id', 'code', 'health_zone_id'])
                ->groupBy(fn ($c) => $zoneNames[$c->health_zone_id] ?? ''),
            'teams' => Team::whereIn('health_zone_id', $zones->pluck('id'))->orderBy('code')->get(['id', 'code', 'name', 'health_zone_id'])
                ->groupBy(fn ($t) => $zoneNames[$t->health_zone_id] ?? ''),
            'collectors' => Collector::whereIn('health_zone_id', $zones->pluck('id'))->orderBy('name')->get(['id', 'name', 'code', 'health_zone_id'])
                ->groupBy(fn ($c) => $zoneNames[$c->health_zone_id] ?? ''),
        ];
    }

    private function statusLabeler(): \Closure
    {
        $dictionary = Dictionary::current();
        $list = $dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;

        return fn (?string $status) => $status === null ? '—' : ($list ? $dictionary->choiceLabel($list, $status) : null) ?? $status;
    }
}
