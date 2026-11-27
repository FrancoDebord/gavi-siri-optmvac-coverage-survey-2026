<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\DocumentSpace;
use App\Models\FieldPhoto;
use App\Models\Household;
use App\Models\PhotoProject;
use App\Models\QualityRule;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use App\Services\Dashboard\ClusterSelection;
use App\Services\Dashboard\RemainingVillages;
use App\Services\Dashboard\TeamActivityReport;
use App\Services\Dashboard\TeamProgress;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Maps\ClusterMapRenderer;
use App\Services\Quality\QueryFocus;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
        // Messages reçus : compte binôme, ou superviseur sur l'espace de son propre binôme.
        $ownTeam = $isTeam || (int) $user->team_id === (int) $team->id;

        return view('team.space', $this->shared($request, $team, $teams, 'progression') + [
            'kpis' => $progress->kpis(),
            'daily' => $progress->daily(),
            'agents' => $progress->agents(),
            'places' => $progress->places(),
            'queries' => $progress->openQueries(),
            'messages' => $ownTeam
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
            ->when(trim((string) $request->input('q')) !== '', fn ($q) => $q->where(fn ($w) => $w->where('household_code', 'like', '%'.trim((string) $request->input('q')).'%')
                // Numéro de query : le ménage concerné.
                ->orWhere('id', DataQuery::householdIdForCode($request->input('q')) ?? 0)))
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
        return $this->queryList($request, 'queries');
    }

    /**
     * Onglet « Villages et agents » : queries village coché hors des villages du binôme, agent d'un autre binôme
     * (règle TEAM_VILLAGE), avec la liste des villages attribués au binôme.
     */
    public function villageQueries(Request $request): View
    {
        return $this->queryList($request, 'villages');
    }

    /** Onglet « Villages restants » : villages du binôme où aucun ménage n'a encore été visité. */
    public function remainingVillages(Request $request): View
    {
        [$team, $teams] = $this->team($request);
        $villages = $team->villages()->get(['villages.id', 'villages.name']);
        $remaining = new RemainingVillages(null, null, $villages->pluck('id')->all());
        $visited = RemainingVillages::visitedCounts();

        return view('team.remaining', $this->shared($request, $team, $teams, 'restants') + [
            'remaining' => $remaining->remaining(),
            'summary' => $remaining->summary(),
            'visitedVillages' => $villages->filter(fn ($v) => ($visited[$v->id] ?? 0) > 0)
                ->map(fn ($v) => ['name' => $v->name, 'visited' => $visited[$v->id]])->values(),
        ]);
    }

    private function queryList(Request $request, string $tab): View
    {
        [$team, $teams] = $this->team($request);
        $base = DataQuery::forTeam($team)
            ->when($tab === 'villages', fn ($q) => $q->where('rule_code', 'TEAM_VILLAGE'));
        $resolution = $request->input('resolution', DataQuery::RESOLUTION_UNRESOLVED);

        $queries = (clone $base)->with(['collector:id,name', 'cluster:id,code'])
            ->when(isset(DataQuery::RESOLUTIONS[$resolution]), fn ($q) => $q->whereIn('status', DataQuery::RESOLUTIONS[$resolution]['statuses']))
            ->when($request->filled('severite'), fn ($q) => $q->where('severity', $request->input('severite')))
            ->when($request->filled('regle'), fn ($q) => $q->where('rule_code', $request->input('regle')))
            // Numéro de query (Q-000123), ID ménage ou texte de l'erreur.
            ->search($request->input('q'))
            ->orderByRaw("case severity when 'critique' then 0 when 'majeure' then 1 else 2 end")->orderByDesc('collection_date')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        // Codes de règle des queries du binôme (filtre), avec le nom de la règle.
        $ruleCodes = (clone $base)->distinct()->orderBy('rule_code')->pluck('rule_code');
        $ruleNames = QualityRule::whereIn('code', $ruleCodes)->pluck('name', 'code');

        $dictionary = Dictionary::current();
        $queries->getCollection()->load(['household', 'child']);

        return view('team.queries', $this->shared($request, $team, $teams, $tab) + [
            'teamVillages' => $tab === 'villages' ? $team->villages()->get(['villages.id', 'villages.name']) : collect(),
            'villageVisits' => $tab === 'villages' ? RemainingVillages::visitedCounts() : [],
            'queries' => $queries,
            'focus' => $queries->getCollection()->mapWithKeys(fn (DataQuery $q) => [$q->id => QueryFocus::for($q, $dictionary)]),
            'resolution' => $resolution,
            'resolutionCounts' => collect(DataQuery::RESOLUTIONS)->map(fn ($r) => collect($r['statuses'])->sum(fn ($s) => $counts[$s] ?? 0)),
            'severities' => QualityRule::SEVERITIES,
            'ruleOptions' => $ruleCodes->mapWithKeys(fn ($code) => [$code => $ruleNames[$code] ?? $code]),
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
            // Base de sondage limitée aux villages du binôme.
            'selection' => (new ClusterSelection(null, $team->collectors->pluck('id')->all()))->build(),
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
     * PDF des grappes du binôme : une page par grappe de ses villages d'intervention, carte sur fond satellite avec
     * les contours et les ménages visités en points (sans nom), pour repérer les ménages restant à visiter.
     */
    public function clustersPdf(Request $request, ClusterMapRenderer $renderer): Response
    {
        @set_time_limit(600);
        [$team] = $this->team($request);
        $households = Household::whereIn('collector_id', $team->collectors->pluck('id'))
            ->get(['id', 'cluster_id', 'latitude', 'longitude', 'consented']);
        $clusters = Cluster::whereIn('id', $households->pluck('cluster_id')->filter()->unique())->with(['village:id,name', 'subClusters'])->get()
            ->sortBy([fn ($a, $b) => strnatcasecmp((string) $a->village?->name, (string) $b->village?->name), fn ($a, $b) => strnatcmp($a->code, $b->code)]);

        $pages = $clusters->map(function (Cluster $cluster) use ($households, $renderer) {
            $own = $households->where('cluster_id', $cluster->id)->values();
            $map = $renderer->render($cluster, $own);

            return [
                'cluster' => $cluster,
                'village' => $cluster->village?->displayName() ?? 'Village non renseigné',
                'map' => 'data:image/png;base64,'.base64_encode($map['png']),
                'visited' => $own->count(),
                'surveyed' => $own->where('consented', true)->count(),
                'outside' => $map['outside'],
                'no_gps' => $own->whereNull('latitude')->count(),
            ];
        })->values();

        AuditLog::record('export.cartes_binome', $team, ['grappes' => $clusters->count()]);

        return Pdf::loadView('pdf.team-clusters', [
            'team' => $team,
            'pages' => $pages,
            'villages' => $pages->groupBy('village')->map->count(),
            'generatedAt' => CarbonImmutable::now(config('app.timezone')),
        ])->setPaper('a4', 'landscape')->download('grappes-binome-'.Str::slug($team->code).'-'.now()->format('Ymd-Hi').'.pdf');
    }

    /**
     * Binôme affiché : celui du compte binôme, sinon celui choisi (?team_id) dans le périmètre de l'utilisateur.
     *
     * @return array{0: Team, 1: Collection<int, Team>}
     */
    /** Même règle que l'ouverture de l'espace binôme : son propre binôme, ou les binômes de la zone supervisée. */
    public static function canSeeTeam(User $user, Team $team): bool
    {
        if ($user->hasRole(User::ROLE_TEAM)) {
            return (int) $user->team_id === (int) $team->id;
        }
        if (! $user->can('view dashboards')) {
            return false;
        }
        $zoneId = $user->supervisedZoneId();

        return ! $zoneId || (int) $team->health_zone_id === (int) $zoneId || (int) $user->team_id === (int) $team->id;
    }

    /**
     * Onglet « Photos » : photos de terrain déposées pour le binôme (tous les espaces photos), les plus récentes
     * d'abord ; clic : visionneuse plein écran.
     */
    public function fieldPhotos(Request $request): View
    {
        [$team, $teams] = $this->team($request);

        return view('team.field-photos', $this->shared($request, $team, $teams, 'photos') + [
            'photos' => FieldPhoto::with(['project:id,name,token', 'team:id,code,name', 'village:id,name'])
                ->where('team_id', $team->id)->latest('id')->paginate(48)->withQueryString(),
        ]);
    }

    /**
     * Onglet « Rapport d'activité » : par semaine de collecte et par jour, ménages visités et enquêtés, mères, enfants
     * de moins de 5 ans et éligibles (même rapport que la page d'administration, limité au binôme), et son PDF.
     */
    public function activity(Request $request): View|Response
    {
        [$team, $teams] = $this->team($request);
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'La date de fin doit suivre la date de début.'], ['from' => 'date de début', 'to' => 'date de fin']);
        [$from, $to] = TeamActivityReport::period($data['from'] ?? null, $data['to'] ?? null);
        $report = $from ? (new TeamActivityReport($from, $to))->build(collect([$team->loadMissing(['collectors', 'healthZone'])]))->first() : null;

        if ($request->routeIs('team.activity.pdf')) {
            abort_unless($report, 404, 'Aucune donnée de collecte.');
            AuditLog::record('export.rapport_activite_binomes', $team, ['du' => $from->toDateString(), 'au' => $to->toDateString()]);

            return Pdf::loadView('pdf.team-activity', [
                'reports' => collect([$report]), 'from' => $from, 'to' => $to, 'generatedAt' => CarbonImmutable::now(config('app.timezone')),
            ])->setPaper('a4', 'portrait')->download('rapport-activite-binome-'.$team->code.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.pdf');
        }

        return view('team.activity', $this->shared($request, $team, $teams, 'activite') + [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'period' => array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
        ]);
    }

    private function team(Request $request): array
    {
        $user = $request->user();
        if ($user->hasRole(User::ROLE_TEAM)) {
            abort_unless($user->team, 403, 'Ce compte n\'est rattaché à aucun binôme : contactez l\'administrateur.');

            return [$user->team->load(['collectors', 'healthZone']), collect()];
        }
        abort_unless($user->can('view dashboards'), 403);
        $zoneId = $user->supervisedZoneId();
        $teams = Team::when($zoneId, fn ($q) => $q->where(fn ($w) => $w->where('health_zone_id', $zoneId)->when($user->team_id, fn ($o, $own) => $o->orWhere('id', $own))))
            ->with('healthZone:id,name')->orderBy('code')->get();
        // Superviseur agent d'un binôme : son binôme par défaut.
        $team = $request->integer('team_id') ? $teams->firstWhere('id', $request->integer('team_id')) : ($teams->firstWhere('id', $user->team_id) ?? $teams->first());
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
            'openQueries' => DataQuery::forTeam($team)->whereIn('status', DataQuery::UNRESOLVED)->count(),
            'openVillageQueries' => DataQuery::forTeam($team)
                ->where('rule_code', 'TEAM_VILLAGE')->whereIn('status', DataQuery::UNRESOLVED)->count(),
        ];
    }

    /**
     * Espace photos de terrain depuis l'espace binôme : compte connecté, donc sans code PIN (déverrouillé pour cette
     * session), puis la page de dépôt. Sans projet précisé : le seul espace ouvert, sinon retour au choix.
     * Réservé aux binômes, aux superviseurs et aux gestionnaires des espaces photos.
     */
    public function photos(Request $request, ?PhotoProject $project = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole([User::ROLE_TEAM, User::ROLE_SUPERVISOR]) || $user->hasOwnTeam() || $user->can('manage field photos'), 403);
        $open = PhotoProject::openForTeams();
        if (! $project) {
            $spaces = $open->get();
            if ($spaces->count() !== 1) {
                // Retour à l'espace d'où vient l'utilisateur (binôme ou superviseur), où le bouton propose le choix.
                $home = $user->hasRole(User::ROLE_TEAM) ? 'team.space' : 'supervision.index';

                return redirect()->route($home)->with('info', $spaces->isEmpty()
                    ? "Aucun espace photos de terrain n'est ouvert pour le moment."
                    : "Choisissez l'espace photos avec le bouton « Photos de terrain ».");
            }
            $project = $spaces->first();
        }
        abort_unless($open->whereKey($project->id)->exists(), 404);
        $request->session()->put($project->sessionKey(), true);

        return redirect()->away($project->publicUrl());
    }

    /**
     * Espace « Factures et rapports » depuis l'application : compte connecté, donc sans code PIN (déverrouillé pour
     * cette session), puis la page de dépôt. Sans espace précisé : le seul ouvert, sinon retour au choix.
     */
    public function documents(Request $request, ?DocumentSpace $space = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PublicDocumentController::canOpenFromAccount($user), 403);
        $open = DocumentSpace::openForTeams();
        if (! $space) {
            $spaces = $open->get();
            if ($spaces->count() !== 1) {
                $home = $user->hasRole(User::ROLE_TEAM) ? 'team.space' : 'supervision.index';

                return redirect()->route($home)->with('info', $spaces->isEmpty()
                    ? "Aucun espace de dépôt des factures et rapports n'est ouvert pour le moment."
                    : "Choisissez l'espace avec le bouton « Factures et rapports ».");
            }
            $space = $spaces->first();
        }
        abort_unless($open->whereKey($space->id)->exists(), 404);
        $request->session()->put($space->sessionKey(), true);

        return redirect()->away($space->publicUrl());
    }

    /** Libellé du statut de visite (liste de choix du formulaire). */
    private function statusLabeler(): \Closure
    {
        $dictionary = Dictionary::current();
        $list = $dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;

        return fn (?string $status) => $status === null ? '—' : ($list ? $dictionary->choiceLabel($list, $status) : null) ?? $status;
    }
}
