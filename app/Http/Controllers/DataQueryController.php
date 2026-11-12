<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkQueryRequest;
use App\Http\Requests\QueryCommentRequest;
use App\Http\Requests\QueryStatusRequest;
use App\Jobs\RunQualityChecksJob;
use App\Models\AuditLog;
use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Services\Attachments\SubmissionPhotos;
use App\Services\Dashboard\QueryStats;
use App\Services\Quality\QueryWorkflow;
use App\Support\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class DataQueryController extends Controller
{
    private const SORTABLE = [
        'code' => 'data_queries.id',
        'detected_at' => 'data_queries.detected_at',
        'collection_date' => 'data_queries.collection_date',
        'zone' => 'health_zones.name',
        'commune' => 'communes.name',
        'village' => 'villages.name',
        'cluster' => 'clusters.name',
        'collector' => 'collectors.name',
        'household_code' => 'data_queries.household_code',
        'variable' => 'data_queries.variable',
        'rule_code' => 'data_queries.rule_code',
        'severity' => 'data_queries.severity',
        'status' => 'data_queries.status',
    ];

    public function index(): View
    {
        return view('queries.index', [
            'rules' => QualityRule::orderBy('code')->get(['code', 'name']),
            'lastRun' => QualityRun::latest('id')->first(),
            'variables' => DataQuery::whereNotNull('variable')->distinct()->orderBy('variable')->pluck('variable'),
        ]);
    }

    /** Source DataTables côté serveur. */
    public function datatable(Request $request): JsonResponse
    {
        $stats = new QueryStats(Filters::fromRequest($request), QueryDashboardController::extra($request));
        $base = $stats->queries();
        $total = (clone $base)->count();

        $query = $base
            ->leftJoin('health_zones', 'health_zones.id', '=', 'data_queries.health_zone_id')
            ->leftJoin('communes', 'communes.id', '=', 'data_queries.commune_id')
            ->leftJoin('villages', 'villages.id', '=', 'data_queries.village_id')
            ->leftJoin('clusters', 'clusters.id', '=', 'data_queries.cluster_id')
            ->leftJoin('collectors', 'collectors.id', '=', 'data_queries.collector_id');

        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                // Un code de query (Q-000123) cible directement la query.
                if (preg_match('/^Q-?\d+$/i', $search)) {
                    $q->orWhere('data_queries.id', DataQuery::idFromCode($search));
                }
                foreach (['data_queries.household_code', 'data_queries.variable', 'data_queries.label', 'data_queries.message', 'data_queries.rule_code', 'data_queries.observed_value', 'collectors.name', 'villages.name', 'data_queries.submission_uuid'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }
        $filtered = (clone $query)->count();

        $column = self::SORTABLE[$request->input('order_column')] ?? 'data_queries.collection_date';
        $direction = $request->input('order_dir') === 'asc' ? 'asc' : 'desc';
        $rows = $query->orderBy($column, $direction)->orderByDesc('data_queries.id')
            ->offset(max(0, $request->integer('start')))
            ->limit(min(100, max(1, $request->integer('length', 25))))
            ->get([
                'data_queries.*', 'health_zones.name as zone', 'communes.name as commune', 'villages.name as village',
                'clusters.name as cluster', 'collectors.name as collector',
            ]);

        return response()->json([
            'draw' => $request->integer('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows->map(fn ($q) => [
                'id' => $q->id,
                'code' => DataQuery::codeFor($q->id),
                'label' => $q->label ?? $q->rule_code,
                'suggestion' => $q->suggestion,
                'detected_at' => $q->detected_at ? date('d/m/Y', strtotime($q->detected_at)) : null,
                'resolved_at' => $q->resolved_at ? date('d/m/Y', strtotime($q->resolved_at)) : null,
                'resolution' => DataQuery::resolutionOf($q->status),
                'resolution_label' => DataQuery::RESOLUTIONS[DataQuery::resolutionOf($q->status)]['label'],
                'collection_date' => $q->collection_date ? date('d/m/Y', strtotime($q->collection_date)) : '—',
                'zone' => $q->zone,
                'commune' => $q->commune,
                'village' => $q->village,
                'cluster' => $q->cluster,
                'collector' => $q->collector,
                'household_code' => $q->household_code,
                'household_url' => $q->household_id ? route('households.show', $q->household_id) : '#',
                'variable' => $q->variable,
                'instance' => $q->instance_path,
                'message' => $q->message,
                'observed' => $q->observed_value,
                'rule_code' => $q->rule_code,
                'severity' => $q->severity,
                'severity_label' => QualityRule::SEVERITIES[$q->severity] ?? $q->severity,
                'status' => $q->status,
                'status_label' => DataQuery::STATUSES[$q->status] ?? $q->status,
                'status_color' => DataQuery::STATUS_COLORS[$q->status] ?? 'secondary',
                'url' => route('queries.show', $q->id),
            ]),
        ]);
    }

    public function show(DataQuery $query, SubmissionPhotos $photos): View
    {
        $this->authorize('view', $query);
        $query->load(['rule', 'household', 'child', 'healthZone', 'commune', 'village', 'cluster', 'collector', 'events.user']);

        return view('queries.show', [
            'query' => $query,
            'photos' => $photos->forQuery($query),
            'related' => DataQuery::where('household_id', $query->household_id)->whereKeyNot($query->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function updateStatus(QueryStatusRequest $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('update', $query);
        try {
            $workflow->transition($query, $request->validated('status'), $request->validated('comment'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Statut mis à jour : '.$query->statusLabel().'.');
    }

    public function comment(QueryCommentRequest $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('update', $query);
        $workflow->comment($query, $request->validated('comment'), $request->user());

        return back()->with('success', 'Commentaire ajouté.');
    }

    /** Action rapide depuis la liste : statut et/ou commentaire sur une ou plusieurs queries. */
    public function bulk(BulkQueryRequest $request, QueryWorkflow $workflow): JsonResponse
    {
        $status = $request->validated('status');
        $comment = $request->validated('comment');
        $done = 0;
        $errors = [];

        $queries = DataQuery::visibleTo($request->user())->whereIn('id', $request->validated('ids'))->get();
        DB::transaction(function () use ($queries, $status, $comment, $request, $workflow, &$done, &$errors) {
            foreach ($queries as $query) {
                try {
                    $status
                        ? $workflow->transition($query, $status, $comment, $request->user())
                        : $workflow->comment($query, (string) $comment, $request->user());
                    $done++;
                } catch (InvalidArgumentException $e) {
                    $errors[] = "#{$query->id} : {$e->getMessage()}";
                }
            }
        });

        $message = "{$done} query(s) mise(s) à jour.";
        if ($errors) {
            $message .= ' '.count($errors).' ignorée(s) : '.implode(' ; ', array_slice($errors, 0, 3));
        }

        return response()->json(['message' => $message, 'updated' => $done, 'errors' => $errors]);
    }

    public function run(Request $request): RedirectResponse
    {
        RunQualityChecksJob::dispatch('manual', $request->user()->id);
        AuditLog::record('quality.run');

        return back()->with('success', 'Le moteur de queries a été lancé en arrière-plan. Les résultats apparaîtront dans quelques instants.');
    }
}
