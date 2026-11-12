<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataQuery;
use App\Models\FieldIncident;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\Village;
use App\Services\Dashboard\InvestigatorStats;
use App\Services\Dashboard\PiSummary;
use App\Services\Dashboard\ProgressStats;
use App\Services\Dashboard\QueryStats;
use App\Services\Dashboard\SupervisionStats;
use App\Services\Dashboard\TeamProgress;
use App\Support\Filters;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Tableau de bord de l'investigateur principal et rapport de l'état de la collecte. */
class InvestigatorController extends Controller
{
    public function index(Request $request): View
    {
        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);
        $zoneId = $request->integer('health_zone_id') ?: null;
        $stats = new InvestigatorStats($zoneId);
        $filters = new Filters(array_filter(['health_zone_id' => $zoneId]), $request->user());
        $queries = new QueryStats($filters);

        return view('investigator.index', [
            'zones' => $zones,
            'zoneId' => $zoneId,
            'kpis' => (new ProgressStats($filters))->kpis(),
            'more' => $stats->kpis(CarbonImmutable::today()),
            'followQueries' => DataQuery::with(['collector.team:id,code', 'village:id,name'])->whereIn('status', DataQuery::UNRESOLVED)
                ->when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
                ->orderByRaw("case severity when 'critique' then 0 when 'majeure' then 1 else 2 end")->orderByDesc('detected_at')->limit(10)->get(),
            'charts' => [
                'teams' => $stats->dailyByTeam(),
                'villages' => $stats->cumulative('village'),
                'zones' => $stats->cumulative('zone'),
                'points' => $stats->householdPoints(),
                'boundaries' => (new ProgressStats($filters))->boundaries(),
            ],
            'queryKpis' => $queries->kpis(),
            'querySeverity' => $queries->bySeverity(),
            'queryZones' => $queries->ranking('health_zones', 'health_zone_id'),
            'queryTeams' => array_slice($queries->teamRanking(), 0, 8),
            'openIncidents' => FieldIncident::when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
                ->whereIn('status', FieldIncident::UNRESOLVED)->count(),
            'recentMessages' => TeamMessage::with('healthZone')->latest('id')->limit(5)->get(),
            'villages' => Village::whereIn('health_zone_id', $zones->pluck('id'))->whereHas('households')->orderBy('name')->get(['id', 'name', 'health_zone_id']),
        ]);
    }

    /**
     * Onglet « Progression » : comme celui des binômes, pour toutes les zones ou une zone — KPIs du jour et cumulés
     * (villages, ménages visités et enquêtés, enfants éligibles), point journalier, détail par zone et par binôme.
     */
    public function progress(Request $request): View
    {
        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);
        $zoneId = $request->integer('health_zone_id') ?: null;
        $today = CarbonImmutable::today();
        $progress = TeamProgress::forZone($zoneId, $today);

        return view('investigator.progress', [
            'zones' => $zones,
            'zoneId' => $zoneId,
            'today' => $today,
            'kpis' => $progress->kpis(),
            'daily' => $progress->daily(),
            'byZone' => $zoneId ? [] : $zones->map(fn ($zone) => ['zone' => $zone] + TeamProgress::forZone($zone->id, $today)->kpis())->all(),
            'byTeam' => Team::with(['collectors', 'healthZone:id,name'])->when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))->get()
                ->map(fn (Team $team) => ['team' => $team] + (new TeamProgress($team, $today))->kpis())
                ->sortByDesc(fn ($row) => [$row['today']['visited'], $row['total']['visited']])->values()->all(),
        ]);
    }

    /**
     * Rapport de synthèse : chiffres clés par zone, par zone et binôme, par zone, binôme et date, puis décisions de
     * collecte. Page web de consultation, ou PDF à imprimer (même contenu, mêmes filtres).
     */
    public function summary(Request $request): View|Response
    {
        $data = $request->validate([
            'health_zone_id' => ['nullable', 'integer', 'exists:health_zones,id'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
        ], [], ['health_zone_id' => 'zone sanitaire', 'du' => 'date de début', 'au' => 'date de fin']);
        $zoneId = isset($data['health_zone_id']) ? (int) $data['health_zone_id'] : null;
        $from = $data['du'] ?? null;
        $to = $data['au'] ?? null;
        $today = CarbonImmutable::today();
        $summary = new PiSummary($zoneId, $from, $to);
        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);

        $period = $from || $to
            ? 'du '.($from ? CarbonImmutable::parse($from)->format('d/m/Y') : 'début').' au '.($to ? CarbonImmutable::parse($to)->format('d/m/Y') : $today->format('d/m/Y'))
            : 'depuis le début de la collecte';
        $zoneLabel = $zoneId ? 'Zone sanitaire de '.$zones->firstWhere('id', $zoneId)?->name : 'Toutes les zones sanitaires';
        [$firstDay, $lastDay, $collectionDays] = $summary->collectionDates();
        $viewData = [
            'zones' => $zones,
            'zoneId' => $zoneId,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            // Date d'édition du rapport (heure locale) et période couverte, affichées en tête du rapport.
            'generatedAt' => CarbonImmutable::now(config('app.timezone')),
            'zoneLabel' => $zoneLabel,
            'periodLabel' => $from || $to ? ucfirst($period) : 'Depuis le début de la collecte',
            'firstDay' => $firstDay,
            'lastDay' => $lastDay,
            'collectionDays' => $collectionDays,
            'scopeLabel' => $zoneLabel.' · '.$period,
            'report' => $summary->build(),
            'decisions' => $summary->decisions(),
            'hiddenDecisions' => $summary->decisions(false),
        ];

        if ($request->routeIs('investigator.summary.pdf')) {
            AuditLog::record('export.rapport_synthese', null, array_filter(['zone' => $zoneId, 'du' => $from, 'au' => $to]));

            return Pdf::loadView('pdf.pi-summary', $viewData)->setPaper('a4', 'portrait')
                ->download('synthese-collecte-'.now()->format('Ymd-Hi').'.pdf');
        }

        return view('investigator.summary', $viewData);
    }

    /**
     * Rapports PDF :
     * - etat : état de la collecte, toutes les zones, une zone ou un village (niveau) ;
     * - villages : détail de chaque village visité (toutes zones ou une zone) ;
     * - binomes : synthèse globale des binômes et de leurs agents ;
     * - binomes_jour : point journalier détaillé de chaque binôme (période facultative).
     */
    public function report(Request $request): Response
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['synthese', 'etat', 'villages', 'binomes', 'binomes_jour'])],
            'niveau' => ['nullable', Rule::in(['tous', 'zone', 'village'])],
            'health_zone_id' => ['nullable', Rule::requiredIf(fn () => ($request->input('type') ?? 'etat') === 'etat' && $request->input('niveau') === 'zone'), 'integer', 'exists:health_zones,id'],
            'village_id' => ['nullable', Rule::requiredIf(fn () => ($request->input('type') ?? 'etat') === 'etat' && $request->input('niveau') === 'village'), 'integer', 'exists:villages,id'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
        ], [], ['health_zone_id' => 'zone sanitaire', 'village_id' => 'village', 'du' => 'date de début', 'au' => 'date de fin']);
        $type = $data['type'] ?? 'etat';
        $level = $data['niveau'] ?? 'tous';
        $today = CarbonImmutable::today();
        $zoneId = isset($data['health_zone_id']) ? (int) $data['health_zone_id'] : null;

        if ($type === 'synthese') {
            return redirect()->route('investigator.summary.pdf', array_filter(['health_zone_id' => $zoneId, 'du' => $data['du'] ?? null, 'au' => $data['au'] ?? null]));
        }
        if (in_array($type, ['binomes', 'binomes_jour'], true)) {
            return $this->teamsReport($type, $zoneId, $data['du'] ?? null, $data['au'] ?? null, $today);
        }

        $section = function (string $column, int $id, string $title, ?int $zoneId) use ($today) {
            $stats = new SupervisionStats($column, $id, $zoneId, $today);
            $queries = new QueryStats(new Filters([$column => $id]));

            return [
                'title' => $title,
                'kpis' => $stats->kpis(),
                'daily' => array_slice($stats->daily(), -14),
                'teams' => $stats->teams(),
                'villages' => $stats->villages(),
                'queries' => $queries->kpis(),
                'severity' => $queries->bySeverity(),
                'top_rules' => array_slice($queries->topRules(), 0, 5),
                'incidents' => FieldIncident::where($column, $id)->whereIn('status', FieldIncident::UNRESOLVED)
                    ->with(['village:id,name', 'cluster:id,code'])->orderByDesc('occurred_on')->limit(20)->get(),
            ];
        };

        $overviewLabel = 'Zone sanitaire';
        if ($type === 'villages') {
            // Détail de tous les villages visités (ménages déclarés dans le village), une section par village.
            $villages = Village::with('healthZone')->whereHas('households')->when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))
                ->get()->sortBy([fn ($a, $b) => strcmp((string) $a->healthZone?->name, (string) $b->healthZone?->name), fn ($a, $b) => strnatcasecmp($a->name, $b->name)]);
            $scopeLabel = 'Détail de tous les villages'.($zoneId ? ' · zone sanitaire de '.HealthZone::whereKey($zoneId)->value('name') : ' · toutes les zones');
            $sections = $villages->map(fn ($v) => $section('village_id', $v->id, $v->name.' ('.($v->healthZone?->name ?? '—').')', null))->values()->all();
            $overviewLabel = 'Village';
            $level = 'villages';
        } elseif ($level === 'village') {
            $village = Village::with('healthZone')->findOrFail($data['village_id']);
            $scopeLabel = 'Village de '.$village->name.' ('.($village->healthZone?->name ?? '—').')';
            $sections = [$section('village_id', $village->id, 'Village de '.$village->name, null)];
        } else {
            $zones = HealthZone::whereHas('communes')->when($level === 'zone', fn ($q) => $q->whereKey($zoneId))->orderBy('name')->get();
            $scopeLabel = $level === 'zone' ? 'Zone sanitaire de '.$zones->first()?->name : 'Toutes les zones sanitaires';
            $sections = $zones->map(fn ($zone) => $section('health_zone_id', $zone->id, 'Zone sanitaire de '.$zone->name, $zone->id))->all();
        }
        AuditLog::record('export.rapport_collecte', null, ['type' => $type, 'niveau' => $level] + array_filter($data));

        return Pdf::loadView('pdf.collection-report', ['scopeLabel' => $scopeLabel, 'sections' => $sections, 'today' => $today, 'level' => $level, 'overviewLabel' => $overviewLabel])
            ->setPaper('a4', 'landscape')
            ->download(($type === 'villages' ? 'detail-villages' : 'etat-collecte-'.($level === 'tous' ? 'national' : $level)).'-'.now()->format('Ymd-Hi').'.pdf');
    }

    /** Rapport des binômes : synthèse globale, ou point journalier détaillé (période facultative). */
    private function teamsReport(string $type, ?int $zoneId, ?string $from, ?string $to, CarbonImmutable $today): Response
    {
        $teams = Team::with(['collectors', 'healthZone'])->when($zoneId, fn ($q) => $q->where('health_zone_id', $zoneId))->get()
            ->sortBy([fn ($a, $b) => strcmp((string) $a->healthZone?->name, (string) $b->healthZone?->name), fn ($a, $b) => strnatcmp($a->code, $b->code)]);

        $rows = $teams->map(function (Team $team) use ($type, $from, $to, $today) {
            $progress = new TeamProgress($team, $today, $from, $to);

            return [
                'team' => $team,
                'kpis' => $progress->kpis(),
                'agents' => $progress->agents(),
                'places' => $type === 'binomes' ? $progress->places() : [],
                'daily' => $type === 'binomes_jour' ? $progress->daily() : [],
            ];
        })->values();

        $period = $from || $to
            ? 'du '.($from ? CarbonImmutable::parse($from)->format('d/m/Y') : 'début').' au '.($to ? CarbonImmutable::parse($to)->format('d/m/Y') : $today->format('d/m/Y'))
            : 'depuis le début de la collecte';
        AuditLog::record('export.rapport_binomes', null, array_filter(['type' => $type, 'zone' => $zoneId, 'du' => $from, 'au' => $to]));

        return Pdf::loadView('pdf.teams-report', [
            'type' => $type,
            'rows' => $rows,
            'today' => $today,
            'scopeLabel' => ($zoneId ? 'Zone sanitaire de '.HealthZone::whereKey($zoneId)->value('name') : 'Toutes les zones sanitaires').' · '.$period,
        ])->setPaper('a4', 'landscape')
            ->download(($type === 'binomes' ? 'binomes-synthese' : 'binomes-journalier').'-'.now()->format('Ymd-Hi').'.pdf');
    }
}
