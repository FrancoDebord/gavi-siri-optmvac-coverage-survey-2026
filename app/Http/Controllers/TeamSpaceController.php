<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use App\Services\Dashboard\TeamProgress;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Espace binôme, en onglets : progression (jour, cumul, enfants éligibles), soumissions, queries et grappes.
 * Un compte « binôme » ne voit que son binôme ; l'équipe centrale et les superviseurs ouvrent l'espace
 * d'un binôme (?team_id=…) pour voir ce qu'il voit.
 */
class TeamSpaceController extends Controller
{
    public function show(Request $request): View
    {
        [$team, $teams] = $this->team($request);
        $progress = new TeamProgress($team, CarbonImmutable::today());
        $user = $request->user();
        $isTeam = $user->hasRole(User::ROLE_TEAM);

        return view('team.space', $this->shared($request, $team, $teams, 'progression') + [
            'kpis' => $progress->kpis(),
            'daily' => $progress->daily(),
            'agents' => $progress->agents(),
            'places' => $progress->places(),
            'queries' => $progress->openQueries(),
            'messages' => $isTeam
                ? TeamMessage::visibleTo($user)->with('sender:id,name')->withExists(['readers as read_by_me' => fn ($q) => $q->whereKey($user->id)])->latest('id')->limit(10)->get()
                : collect(),
        ]);
    }

    /** Soumissions (ménages) du binôme, avec filtres, et lien vers le détail de chaque soumission. */
    public function submissions(Request $request): View
    {
        [$team, $teams] = $this->team($request);
        $base = Household::whereIn('collector_id', $team->collectors->pluck('id'));
        $status = $request->input('statut');

        $households = (clone $base)
            ->with(['collector:id,name,code', 'cluster:id,code', 'village:id,name'])
            ->withCount([
                'children as eligible_children' => fn ($q) => $q->where('eligible', true),
                'queries as open_queries' => fn ($q) => $q->whereIn('status', DataQuery::UNRESOLVED),
            ])
            ->when($status === 'enquetes', fn ($q) => $q->where('consented', true))
            ->when($status === 'non_enquetes', fn ($q) => $q->where('consented', false))
            ->when($status === 'avec_queries', fn ($q) => $q->whereHas('queries', fn ($s) => $s->whereIn('status', DataQuery::UNRESOLVED)))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('interview_date', $request->date('date')))
            ->when($request->integer('cluster_id'), fn ($q, $id) => $q->where('cluster_id', $id))
            ->when(trim((string) $request->input('q')) !== '', fn ($q) => $q->where('household_code', 'like', '%'.trim((string) $request->input('q')).'%'))
            ->orderByDesc('interview_date')->orderByDesc('submitted_at')->paginate(50)->withQueryString();

        return view('team.submissions', $this->shared($request, $team, $teams, 'soumissions') + [
            'households' => $households,
            'statusLabel' => $this->statusLabeler(),
            'dates' => (clone $base)->whereNotNull('interview_date')->selectRaw('date(interview_date) as d')->distinct()->orderByDesc('d')->pluck('d')->map(fn ($d) => substr((string) $d, 0, 10)),
            'clusterOptions' => Cluster::whereIn('id', (clone $base)->whereNotNull('cluster_id')->select('cluster_id'))->orderBy('code')->get(['id', 'code']),
            'totals' => (clone $base)->selectRaw('count(*) as n, sum(case when consented then 1 else 0 end) as surveyed')->first(),
        ]);
    }

    /** Queries du binôme : non résolues par défaut, avec accès à la fiche détaillée de chaque query. */
    public function queries(Request $request): View
    {
        [$team, $teams] = $this->team($request);
        $base = DataQuery::whereIn('collector_id', $team->collectors->pluck('id'));
        $resolution = $request->input('resolution', DataQuery::RESOLUTION_UNRESOLVED);

        $queries = (clone $base)->with(['collector:id,name', 'cluster:id,code'])
            ->when(isset(DataQuery::RESOLUTIONS[$resolution]), fn ($q) => $q->whereIn('status', DataQuery::RESOLUTIONS[$resolution]['statuses']))
            ->when($request->filled('severite'), fn ($q) => $q->where('severity', $request->input('severite')))
            ->when(trim((string) $request->input('q')) !== '', fn ($q) => $q->where(fn ($s) => $s->where('household_code', 'like', '%'.trim((string) $request->input('q')).'%')
                ->orWhere('message', 'like', '%'.trim((string) $request->input('q')).'%')))
            ->orderByRaw("case severity when 'critique' then 0 when 'majeure' then 1 else 2 end")->orderByDesc('collection_date')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('team.queries', $this->shared($request, $team, $teams, 'queries') + [
            'queries' => $queries,
            'resolution' => $resolution,
            'resolutionCounts' => collect(DataQuery::RESOLUTIONS)->map(fn ($r) => collect($r['statuses'])->sum(fn ($s) => $counts[$s] ?? 0)),
            'severities' => QualityRule::SEVERITIES,
        ]);
    }

    /** Grappes du binôme : contours relevés (grappe, sous-grappes, hameaux) et ménages sur la carte. */
    public function clusters(Request $request): View
    {
        [$team, $teams] = $this->team($request);
        $households = Household::whereIn('collector_id', $team->collectors->pluck('id'));
        $statusLabel = $this->statusLabeler();

        $clusters = Cluster::whereIn('id', (clone $households)->whereNotNull('cluster_id')->select('cluster_id'))
            ->with(['village:id,name', 'subClusters'])->withCount(['households as team_households' => fn ($q) => $q->whereIn('collector_id', $team->collectors->pluck('id'))])
            ->orderBy('code')->get();

        return view('team.clusters', $this->shared($request, $team, $teams, 'grappes') + [
            'clusters' => $clusters,
            'mapData' => [
                'boundaries' => $clusters->filter->hasMapBoundary()->map->mapBoundary()->values()->all(),
                'points' => (clone $households)->whereNotNull('latitude')->whereNotNull('longitude')->with('cluster:id,code')
                    ->get(['id', 'latitude', 'longitude', 'household_code', 'consented', 'survey_status', 'sub_cluster', 'cluster_id', 'interview_date'])
                    ->map(fn ($h) => [
                        'lat' => $h->latitude, 'lng' => $h->longitude, 'consented' => $h->consented, 'code' => $h->household_code,
                        'cluster' => $h->cluster?->code, 'sub_cluster' => $h->sub_cluster, 'status' => $statusLabel($h->survey_status),
                        'date' => $h->interview_date?->format('d/m/Y'), 'url' => route('households.show', $h),
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * Binôme affiché : celui du compte binôme, sinon celui choisi (?team_id) dans le périmètre de l'utilisateur.
     *
     * @return array{0: Team, 1: Collection<int, Team>}
     */
    private function team(Request $request): array
    {
        $user = $request->user();
        if ($user->hasRole(User::ROLE_TEAM)) {
            abort_unless($user->team, 403, 'Ce compte n\'est rattaché à aucun binôme : contactez l\'administrateur.');

            return [$user->team->load(['collectors', 'healthZone']), collect()];
        }
        abort_unless($user->can('view dashboards'), 403);
        $zoneId = $user->supervisedZoneId();
        $teams = Team::when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))->with('healthZone:id,name')->orderBy('code')->get();
        $team = $request->integer('team_id') ? $teams->firstWhere('id', $request->integer('team_id')) : $teams->first();
        abort_unless($team, $request->integer('team_id') ? 403 : 404);

        return [$team->load(['collectors', 'healthZone']), $teams];
    }

    private function shared(Request $request, Team $team, Collection $teams, string $tab): array
    {
        $isTeam = $request->user()->hasRole(User::ROLE_TEAM);

        return [
            'team' => $team,
            'teams' => $teams,
            'isTeam' => $isTeam,
            'tab' => $tab,
            'today' => CarbonImmutable::today(),
            // Le binôme choisi suit l'utilisateur d'un onglet à l'autre (équipe centrale, superviseurs).
            'teamParam' => $isTeam ? [] : ['team_id' => $team->id],
            'openQueries' => DataQuery::whereIn('collector_id', $team->collectors->pluck('id'))->whereIn('status', DataQuery::UNRESOLVED)->count(),
        ];
    }

    /** Libellé du statut de visite (liste de choix du formulaire). */
    private function statusLabeler(): \Closure
    {
        $dictionary = Dictionary::current();
        $list = $dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;

        return fn (?string $status) => $status === null ? '—' : ($list ? $dictionary->choiceLabel($list, $status) : null) ?? $status;
    }
}
