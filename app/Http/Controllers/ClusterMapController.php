<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Household;
use App\Models\User;
use App\Models\Village;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Carte des grappes par village : toutes les grappes et sous-grappes du village choisi, avec leurs contours
 * et les ménages visités. Chacun selon son niveau : un binôme voit ses villages (et ses ménages), un
 * superviseur les villages de sa zone, les autres profils tous les villages.
 */
class ClusterMapController extends Controller
{
    private const POINTS_LIMIT = 5000;

    public function index(Request $request): View
    {
        $user = $request->user();
        $villages = $this->villages($user);
        $village = $villages->firstWhere('id', $request->integer('village_id'))
            ?? $villages->first(fn ($v) => $v->has_boundary) ?? $villages->first();
        $statusLabel = $this->statusLabeler();

        $clusters = collect();
        $points = [];
        if ($village) {
            $clusters = Cluster::where('village_id', $village->id)
                ->where(fn ($q) => $q->whereHas('households')->orWhereNotNull('boundary')->orWhereHas('subClusters', fn ($s) => $s->whereNotNull('boundary')))
                ->with('subClusters')
                ->withCount(['households as visible_households' => fn ($q) => $q->visibleTo($user)])
                ->orderBy('code')->get();
            $points = Household::visibleTo($user)
                ->where(fn ($q) => $q->whereIn('cluster_id', $clusters->pluck('id'))->orWhere('village_id', $village->id))
                ->whereNotNull('latitude')->whereNotNull('longitude')->with(['cluster:id,code', 'collector.team:id,code'])
                ->latest('id')->limit(self::POINTS_LIMIT)
                ->get(['id', 'latitude', 'longitude', 'household_code', 'consented', 'survey_status', 'sub_cluster', 'cluster_id', 'collector_id', 'interview_date'])
                ->map(fn ($h) => [
                    'lat' => $h->latitude, 'lng' => $h->longitude, 'consented' => $h->consented, 'code' => $h->household_code,
                    'cluster' => $h->cluster?->code, 'sub_cluster' => $h->sub_cluster, 'team' => $h->collector?->team?->code,
                    'status' => $statusLabel($h->survey_status), 'date' => $h->interview_date?->format('d/m/Y'), 'url' => route('households.show', $h),
                ])->values()->all();
        }

        $index = $village ? $villages->search(fn ($v) => $v->id === $village->id) : false;

        return view('maps.clusters', [
            'villages' => $villages,
            'village' => $village,
            'previous' => $index !== false ? $villages->get($index - 1) : null,
            'next' => $index !== false ? $villages->get($index + 1) : null,
            'clusters' => $clusters,
            'window' => $request->boolean('fenetre'),
            'scopeLabel' => match (true) {
                $user->hasRole(User::ROLE_TEAM) => 'villages où votre binôme a travaillé',
                $user->geographicScope() !== null => 'villages de votre zone',
                default => 'tous les villages',
            },
            'mapData' => [
                'points' => $points,
                'boundaries' => $clusters->filter(fn ($c) => $c->boundary || $c->subClusters->whereNotNull('boundary')->isNotEmpty())->map(fn ($c) => [
                    'code' => $c->code,
                    'name' => $c->name,
                    'url' => route('clusters.show', $c),
                    'boundary' => $c->boundary,
                    'sub_clusters' => $c->subClusters->whereNotNull('boundary')->map(fn ($s) => ['number' => $s->number, 'boundary' => $s->boundary])->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * Villages proposés selon le niveau : ceux d'un binôme (où ses agents ont travaillé), ceux de la zone
     * d'un superviseur, ou tous ; seuls les villages ayant des ménages ou un contour relevé sont listés.
     *
     * @return Collection<int, Village>
     */
    private function villages(User $user): Collection
    {
        $clustered = Cluster::query()->whereNotNull('village_id')
            ->where(fn ($q) => $q->whereHas('households')->orWhereNotNull('boundary')->orWhereHas('subClusters', fn ($s) => $s->whereNotNull('boundary')));

        $query = Village::query()->with('healthZone:id,name');
        if ($user->hasRole(User::ROLE_TEAM)) {
            $agents = Collector::where('team_id', $user->team_id ?? 0)->select('id');
            $query->where(fn ($q) => $q
                ->whereIn('id', Cluster::whereIn('id', Household::whereIn('collector_id', $agents)->whereNotNull('cluster_id')->select('cluster_id'))->select('village_id'))
                ->orWhereIn('id', Household::whereIn('collector_id', $agents)->whereNotNull('village_id')->select('village_id')));
        } else {
            $query->where(fn ($q) => $q->whereIn('id', (clone $clustered)->select('village_id'))->orWhereHas('households'))
                ->when($user->supervisedZoneId(), fn ($q, $zoneId) => $q->where('health_zone_id', $zoneId));
        }
        $withBoundary = (clone $clustered)->where(fn ($q) => $q->whereNotNull('boundary')->orWhereHas('subClusters', fn ($s) => $s->whereNotNull('boundary')))
            ->pluck('village_id')->unique()->flip();

        return $query->get(['id', 'name', 'health_zone_id'])
            ->each(function ($v) use ($withBoundary) {
                $v->has_boundary = isset($withBoundary[$v->id]); // pas de fonction fléchée : each() s'arrête si le rappel renvoie false
            })
            ->sortBy([fn ($a, $b) => strcmp((string) $a->healthZone?->name, (string) $b->healthZone?->name), fn ($a, $b) => strnatcasecmp($a->name, $b->name)])
            ->values();
    }

    private function statusLabeler(): \Closure
    {
        $dictionary = Dictionary::current();
        $list = $dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;

        return fn (?string $status) => $status === null ? '—' : ($list ? $dictionary->choiceLabel($list, $status) : null) ?? $status;
    }
}
