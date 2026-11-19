<?php

namespace App\Http\Controllers;

use App\Exports\QueriesExport;
use App\Http\Requests\BulkQueryRequest;
use App\Http\Requests\QueryAssignRequest;
use App\Http\Requests\QueryCommentRequest;
use App\Http\Requests\QueryStatusRequest;
use App\Jobs\RunQualityChecksJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Models\User;
use App\Services\Attachments\PhotoReader;
use App\Services\Attachments\SubmissionPhotos;
use App\Services\Cleaning\CleanDataService;
use App\Services\Cleaning\CorrectionApplier;
use App\Services\Cleaning\SourceSnapshot;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Dashboard\QueryStats;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Export\DatasetExporter;
use App\Services\Quality\QueryFocus;
use App\Services\Quality\QueryWorkflow;
use App\Support\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

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

    /** Personnes à qui une query peut être affectée : data managers, superviseurs, comptes binômes (actifs). */
    public static function assignableUsers(): Collection
    {
        return User::role([User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_TEAM])->where('is_active', true)
            ->with(['roles:id,name', 'team:id,code,name'])->orderBy('name')->get(['id', 'name', 'team_id']);
    }

    /** Personnes à qui confier la validation : superviseurs, validateurs, data managers (actifs). */
    public static function reviewerUsers(): Collection
    {
        return User::role([User::ROLE_SUPERVISOR, User::ROLE_VALIDATOR, User::ROLE_DATA_MANAGER])->where('is_active', true)
            ->with(['roles:id,name', 'team:id,code,name'])->orderBy('name')->get(['id', 'name', 'team_id']);
    }

    public static function assigneeLabel(User $user): string
    {
        $role = User::ROLES[$user->roles->first()?->name] ?? '';

        return $user->name.($user->team ? ' · binôme '.$user->team->code : ($role ? ' · '.$role : ''));
    }

    public function index(Request $request): View
    {
        return view('queries.index', [
            'assignees' => $request->user()->can('assign queries') || $request->user()->can('view dashboards') ? self::assignableUsers() : collect(),
            'reviewers' => $request->user()->can('validate queries') ? self::reviewerUsers() : collect(),
            'rules' => QualityRule::where('kind', QualityRule::KIND_QUERY)->orderBy('code')->get(['code', 'name']),
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
            ->leftJoin('collectors', 'collectors.id', '=', 'data_queries.collector_id')
            ->leftJoin('users as assignees', 'assignees.id', '=', 'data_queries.assigned_to')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'data_queries.reviewer_id');

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
                'clusters.name as cluster', 'collectors.name as collector', 'assignees.name as assignee', 'reviewers.name as reviewer',
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
                'assignee' => $q->assignee,
                'reviewer' => $q->reviewer,
                'due_on' => $q->due_on ? date('d/m/Y', strtotime($q->due_on)) : null,
                'overdue' => $q->due_on && in_array($q->status, DataQuery::UNRESOLVED, true) && $q->due_on < now()->toDateString(),
                'url' => route('queries.show', $q->id),
            ]),
        ]);
    }

    public function show(DataQuery $query, SubmissionPhotos $photos): View
    {
        $this->authorize('view', $query);
        $query->load(['rule', 'household', 'child', 'healthZone', 'commune', 'village', 'cluster', 'collector', 'events.user', 'assignee', 'responder', 'validator', 'suggester', 'reviewer']);

        $user = request()->user();

        $focus = QueryFocus::for($query);
        $geo = $this->geoField($query);

        return view('queries.show', [
            'query' => $query,
            'focus' => $focus,
            'corrections' => DataCorrection::where('data_query_id', $query->id)->with(['proposer:id,name', 'decider:id,name'])->latest('id')->get(),
            'correctionField' => $user->can('respond', $query) && ! $geo ? $this->correctionField($query, $focus['suggested_value']) : null,
            'geoField' => $user->can('respond', $query) ? $geo : null,
            'reasons' => DataCorrection::REASONS,
            'members' => $user->can('respond', $query) ? $this->members($query) : [],
            'memberValues' => $user->can('respond', $query) ? $this->memberValues($query) : [],
            'variableOptions' => $user->can('respond', $query) ? $this->variableOptions() : [],
            'assignees' => request()->user()->can('assign', $query) ? self::assignableUsers() : collect(),
            'reviewers' => request()->user()->can('assignReview', $query) ? self::reviewerUsers() : collect(),
            'doubleValidation' => AppSetting::requiresDoubleValidation($query->severity),
            'photos' => $photos->forQuery($query),
            'related' => DataQuery::where('household_id', $query->household_id)->whereKeyNot($query->id)->latest('id')->limit(20)->get(),
        ]);
    }

    /** Règles portant sur la localisation (village, arrondissement, grappe) : correction par listes à cocher. */
    public const GEO_RULES = ['GEO_HIERARCHY', 'HH_CODE', 'GPS_CLUSTER_DISTANCE'];

    /**
     * Arrondissement et village à cocher (listes du dictionnaire) : arrondissements de la commune déclarée, villages
     * de chacun (filtre « parent » du formulaire), valeurs actuelles cochées.
     */
    private function geoField(DataQuery $query): ?array
    {
        $dictionary = Dictionary::current();
        [$arrVar, $villageVar, $communeVar] = [Map::var('arrondissement'), Map::var('village'), Map::var('commune')];
        $arrDef = $arrVar ? $dictionary->variable($arrVar) : null;
        $villageDef = $villageVar ? $dictionary->variable($villageVar) : null;
        if (! $query->household || ! $arrDef || ! $villageDef || ! (in_array($query->rule_code, self::GEO_RULES, true) || in_array($query->variable, [$arrVar, $villageVar], true))) {
            return null;
        }
        $clean = app(CleanDataService::class)->cleanSubmission($query->household);
        $commune = (string) ($clean->data[$communeVar] ?? '');
        $arrondissements = collect($dictionary->choiceRows($arrDef['list_name']));
        $inCommune = $arrondissements->filter(fn ($r) => (string) ($r['extra']['l2'] ?? $r['extra'][$communeVar] ?? '') === $commune);
        $arrondissements = $inCommune->isNotEmpty() ? $inCommune : $arrondissements;
        $codes = $arrondissements->pluck('name')->all();
        $villages = collect($dictionary->choiceRows($villageDef['list_name']))
            ->filter(fn ($r) => in_array((string) ($r['extra']['parent'] ?? ''), $codes, true))
            ->sortBy(fn ($r) => mb_strtolower((string) $r['label']));
        $label = fn ($r) => trim(strip_tags((string) $r['label'])) ?: $r['name'];

        return [
            'arr_var' => $arrVar,
            'village_var' => $villageVar,
            'arr_label' => trim(strip_tags((string) $arrDef['label'])),
            'village_label' => trim(strip_tags((string) $villageDef['label'])),
            'arr_current' => (string) ($clean->data[$arrVar] ?? ''),
            'village_current' => (string) ($clean->data[$villageVar] ?? ''),
            'arrondissements' => $arrondissements->sortBy(fn ($r) => mb_strtolower((string) $r['label']))->map(fn ($r) => ['code' => $r['name'], 'label' => $label($r)])->values()->all(),
            'villages' => $villages->map(fn ($r) => ['code' => $r['name'], 'label' => $label($r), 'parent' => (string) $r['extra']['parent']])->values()->all(),
        ];
    }

    /** Membres de la soumission (ménage, caregivers, enfants…) : chemin => libellé lisible. */
    private function members(DataQuery $query): array
    {
        if (! $query->household) {
            return [];
        }
        $clean = app(CleanDataService::class)->cleanSubmission($query->household);
        $members = ['' => 'Ménage'];
        foreach ((new DatasetExporter)->flatten($clean) as $table => $rows) {
            foreach ($rows as $row) {
                if ($row['path']) {
                    $members[$row['path']] = QueriesExport::memberLabel($row['path']);
                }
            }
        }

        return $members;
    }

    /**
     * Valeurs de chaque membre de la soumission, pour les afficher et pré-remplir la correction :
     * chemin => variable => [valeur actuelle (base nettoyée), valeur saisie dans ODK].
     *
     * @return array<string, array<string, array{0: ?string, 1: ?string}>>
     */
    private function memberValues(DataQuery $query): array
    {
        if (! $query->household) {
            return [];
        }
        $exporter = new DatasetExporter;
        $source = SourceSnapshot::of($query->household);
        $flatten = function ($submission) use ($exporter) {
            $values = [];
            foreach ($exporter->flatten($submission) as $rows) {
                foreach ($rows as $row) {
                    foreach ($row['data'] as $variable => $value) {
                        $values[$row['path'] ?? ''][$variable] = CorrectionApplier::normalize($value);
                    }
                }
            }

            return $values;
        };
        $clean = $flatten((new CorrectionApplier)->apply($source)[0]);
        $raw = $flatten($source);
        $result = [];
        foreach ($clean + $raw as $path => $variables) {
            foreach (($clean[$path] ?? []) + ($raw[$path] ?? []) as $variable => $unused) {
                $result[$path][$variable] = [$clean[$path][$variable] ?? null, $raw[$path][$variable] ?? null];
            }
        }

        return $result;
    }

    /**
     * Questions saisissables du formulaire, dans l'ordre : libellé, nom de variable, repeat, type et choix
     * (listes courtes) pour adapter la saisie de la nouvelle valeur.
     *
     * @return list<array{name: string, label: string, repeat: string, type: string, choices: array}>
     */
    private function variableOptions(): array
    {
        $dictionary = Dictionary::current();

        return collect($dictionary->variables())->filter(fn ($v) => $dictionary->isInput($v))->sortBy('position')
            ->map(function ($v, $name) use ($dictionary) {
                $choices = $v['list_name'] ? ($dictionary->choices($v['list_name']) ?? []) : [];

                return [
                    'name' => $name,
                    'label' => trim(preg_replace('/\s+/', ' ', strip_tags((string) $v['label']))) ?: $name,
                    'repeat' => (string) ($v['repeat_name'] ?? ''),
                    'type' => $v['base_type'],
                    'choices' => count($choices) <= 40 ? collect($choices)->map(fn ($l, $c) => ['code' => (string) $c, 'label' => trim(strip_tags((string) $l))])->values()->all() : [],
                ];
            })->values()->all();
    }

    /**
     * Champ de la query à corriger : valeur actuelle (base nettoyée), valeur reçue d'ODK, type et choix du dictionnaire.
     */
    private function correctionField(DataQuery $query, ?string $suggested = null): ?array
    {
        $dictionary = Dictionary::current();
        $definition = $query->variable ? $dictionary->variable($query->variable) : null;
        if (! $query->household || ! $definition || ! $dictionary->isInput($definition)) {
            return null;
        }
        try {
            $clean = app(CleanDataService::class)->cleanSubmission($query->household);
            $source = SourceSnapshot::of($query->household);
            $current = SubmissionPaths::get($clean, $query->variable, $query->instance_path);
            $original = SubmissionPaths::get($source, $query->variable, $query->instance_path);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'variable' => $query->variable,
            'path' => $query->instance_path,
            'label' => $query->variable_label,
            'current' => CorrectionApplier::normalize($current),
            'source' => CorrectionApplier::normalize($original),
            'type' => $definition['base_type'],
            'choices' => $definition['list_name'] ? ($dictionary->choices($definition['list_name']) ?? []) : [],
            'suggested' => $suggested,
        ];
    }

    /**
     * Valeur lue sur une photo de la soumission (data manager, superviseur) : devient la valeur suggérée à l'agent,
     * contrôlée selon le dictionnaire. Vide = suggestion retirée. Jamais appliquée sans réponse de l'agent.
     */
    public function suggest(Request $request, DataQuery $query, CleanDataService $cleaning): RedirectResponse
    {
        abort_unless($request->user()->can('update', $query) || $request->user()->can('validate', $query), 403);
        $data = $request->validate([
            'suggested_value' => ['nullable', 'string', 'max:2000'],
            'suggested_note' => ['nullable', 'string', 'max:255'],
        ], [], ['suggested_value' => 'valeur lue', 'suggested_note' => 'précision']);
        $value = CorrectionApplier::normalize($data['suggested_value'] ?? null);
        $definition = $query->variable ? Dictionary::current()->variable($query->variable) : null;
        if ($value !== null && $definition) {
            try {
                $cleaning->checkValue($definition, Dictionary::current(), $value);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['suggested_value' => str_replace('Correction 1 : ', '', $e->getMessage())])->withInput();
            }
        }

        $query->update($value === null
            ? ['suggested_value' => null, 'suggested_source' => null, 'suggested_note' => null, 'suggested_by' => null, 'suggested_at' => null]
            : ['suggested_value' => $value, 'suggested_source' => 'photo', 'suggested_note' => $data['suggested_note'] ?? null, 'suggested_by' => $request->user()->id, 'suggested_at' => now()]);
        $workflow = app(QueryWorkflow::class);
        $workflow->comment($query, $value === null ? 'Valeur suggérée retirée.' : 'Valeur lue sur la photo : '.$value.(($data['suggested_note'] ?? null) ? ' ('.$data['suggested_note'].')' : ''), $request->user());

        return back()->with('success', $value === null ? 'Suggestion retirée.' : 'Valeur lue enregistrée : elle est proposée à l\'agent.');
    }

    /**
     * Lecture de la valeur sur les photos par l'IA (Claude) : devient la suggestion « lue par l'IA, à vérifier »,
     * contrôlée selon le dictionnaire. Jamais appliquée sans réponse de l'agent et validation.
     */
    public function suggestAi(Request $request, DataQuery $query, PhotoReader $reader, CleanDataService $cleaning): RedirectResponse
    {
        abort_unless($request->user()->can('update', $query) || $request->user()->can('validate', $query), 403);
        abort_unless(PhotoReader::enabled(), 403, 'Lecture IA désactivée (Paramètres).');
        try {
            $result = $reader->read($query);
        } catch (RuntimeException $e) {
            return back()->with('danger', $e->getMessage());
        }
        $workflow = app(QueryWorkflow::class);
        if (! $result['found']) {
            $workflow->comment($query, trim('Lecture IA : valeur non lisible sur les photos. '.$result['note']), $request->user());

            return back()->with('danger', trim('L\'IA n\'a pas pu lire la valeur sur les photos. '.$result['note']));
        }
        $definition = Dictionary::current()->variable($query->variable);
        if ($definition) {
            try {
                $cleaning->checkValue($definition, Dictionary::current(), $result['value']);
            } catch (InvalidArgumentException $e) {
                $workflow->comment($query, 'Lecture IA écartée ('.$result['value'].') : '.str_replace('Correction 1 : ', '', $e->getMessage()), $request->user());

                return back()->with('danger', 'Valeur lue par l\'IA non conforme au dictionnaire ('.$result['value'].') : à lire manuellement.');
            }
        }
        $note = 'Confiance '.$result['confidence'].($result['photo'] ? ' · '.$result['photo'] : '').($result['note'] !== '' ? ' · '.$result['note'] : '');
        $query->update(['suggested_value' => $result['value'], 'suggested_source' => 'ia', 'suggested_note' => mb_substr($note, 0, 255),
            'suggested_by' => $request->user()->id, 'suggested_at' => now()]);
        $workflow->comment($query, 'Valeur lue par l\'IA : '.$result['value'].' ('.$note.')', $request->user());

        return back()->with('success', 'Valeur lue par l\'IA : '.$result['value'].' (confiance '.$result['confidence'].'). Vérifiez-la sur la photo : elle est proposée à l\'agent.');
    }

    public function updateStatus(QueryStatusRequest $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('view', $query);
        try {
            // Lignes de correction vides (formulaire) ignorées.
            $corrections = array_values(array_filter($request->validated('corrections') ?? [], fn ($row) => trim((string) ($row['variable'] ?? '')) !== ''));
            // Lignes groupées (arrondissement + village) : un seul motif, saisi sur la première.
            $shared = collect($corrections)->filter(fn ($row) => ! empty($row['group']) && ! empty($row['reason']))->keyBy('group');
            foreach ($corrections as &$row) {
                if (! empty($row['group']) && empty($row['reason']) && isset($shared[$row['group']])) {
                    $row += array_intersect_key($shared[$row['group']], array_flip(['source_reference', 'comment']));
                    $row['reason'] = $shared[$row['group']]['reason'];
                }
            }
            unset($row);
            $workflow->transition($query, $request->validated('status'), $request->validated('comment'), $request->user(), $request->validated('password'), $corrections);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        $auto = $workflow->lastAutoApply;

        return back()->with('success', match (true) {
            $auto !== null && $auto['applied'] => 'Correction prise en compte : l\'erreur est levée.',
            $auto !== null => 'Correction enregistrée ; elle sera examinée par le data manager ('.$auto['reason'].').',
            in_array($request->validated('status'), DataQuery::RESPONSES, true) => 'Réponse enregistrée : en attente de validation.',
            default => 'Statut mis à jour : '.$query->fresh()->statusLabel().'.',
        });
    }

    public function comment(QueryCommentRequest $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('comment', $query);
        $workflow->comment($query, $request->validated('comment'), $request->user());

        return back()->with('success', 'Commentaire ajouté.');
    }

    public function assign(QueryAssignRequest $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('assign', $query);
        $assignee = $request->validated('assigned_to') ? User::find($request->validated('assigned_to')) : null;
        try {
            $workflow->assign($query, $assignee, $request->validated('due_on'), $request->user(), $request->validated('comment'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['assigned_to' => $e->getMessage()])->withInput();
        }

        return back()->with('success', $assignee ? "Query affectée à {$assignee->name}." : 'Affectation retirée.');
    }

    /** Confier la validation d'une query (data manager, administrateur). */
    public function assignReview(Request $request, DataQuery $query, QueryWorkflow $workflow): RedirectResponse
    {
        $this->authorize('assignReview', $query);
        $data = $request->validate(['reviewer_id' => ['nullable', 'integer', 'exists:users,id'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $reviewer = isset($data['reviewer_id']) ? User::find($data['reviewer_id']) : null;
        try {
            $workflow->assignReview($query, $reviewer, $request->user(), $data['comment'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reviewer_id' => $e->getMessage()]);
        }

        return back()->with('success', $reviewer ? "Validation confiée à {$reviewer->name}." : 'Validation reprise.');
    }

    /** Confier la validation de plusieurs queries. */
    public function bulkAssignReview(Request $request, QueryWorkflow $workflow): JsonResponse
    {
        abort_unless($request->user()->can('validate queries'), 403);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'], 'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $reviewer = isset($data['reviewer_id']) ? User::find($data['reviewer_id']) : null;
        $done = 0;
        $errors = [];
        foreach (DataQuery::visibleTo($request->user())->whereIn('id', $data['ids'])->get() as $query) {
            try {
                if ($request->user()->cannot('assignReview', $query)) {
                    throw new InvalidArgumentException('hors de votre périmètre');
                }
                $workflow->assignReview($query, $reviewer, $request->user(), $data['comment'] ?? null);
                $done++;
            } catch (InvalidArgumentException $e) {
                $errors[] = "{$query->code} : {$e->getMessage()}";
            }
        }

        return response()->json(self::bulkResult($done, $errors, $reviewer ? 'confiée(s) à '.$reviewer->name.' pour validation' : 'reprise(s)'));
    }

    /** Affectation groupée depuis la liste. */
    public function bulkAssign(QueryAssignRequest $request, QueryWorkflow $workflow): JsonResponse
    {
        $request->validate(['ids' => ['required']]);
        $assignee = $request->validated('assigned_to') ? User::find($request->validated('assigned_to')) : null;
        $done = 0;
        $errors = [];
        $queries = DataQuery::visibleTo($request->user())->whereIn('id', $request->validated('ids'))->get();
        DB::transaction(function () use ($queries, $assignee, $request, $workflow, &$done, &$errors) {
            foreach ($queries as $query) {
                try {
                    if ($request->user()->cannot('assign', $query)) {
                        throw new InvalidArgumentException('hors de votre périmètre');
                    }
                    $workflow->assign($query, $assignee, $request->validated('due_on'), $request->user(), $request->validated('comment'));
                    $done++;
                } catch (InvalidArgumentException $e) {
                    $errors[] = "{$query->code} : {$e->getMessage()}";
                }
            }
        });

        return response()->json(self::bulkResult($done, $errors, $assignee ? 'affectée(s) à '.$assignee->name : 'désaffectée(s)'));
    }

    private static function bulkResult(int $done, array $errors, string $verb): array
    {
        $message = "{$done} query(s) {$verb}.";
        if ($errors) {
            $message .= ' '.count($errors).' ignorée(s) : '.implode(' ; ', array_slice($errors, 0, 3));
        }

        return ['message' => $message, 'updated' => $done, 'errors' => $errors];
    }

    /**
     * Action groupée depuis la liste : réponse, validation en bloc (signature unique pour les queries qui l'exigent),
     * rejet, irrésoluble, ou commentaire. Chaque query est contrôlée individuellement ; les refus sont listés.
     */
    public function bulk(BulkQueryRequest $request, QueryWorkflow $workflow): JsonResponse
    {
        $status = $request->validated('status');
        $comment = $request->validated('comment');
        $done = 0;
        $errors = [];

        $queries = DataQuery::visibleTo($request->user())->whereIn('id', $request->validated('ids'))->get();
        // Validation en bloc : corrections appliquées puis une seule vérification par le moteur.
        $workflow->deferVerification(function () use ($queries, $status, $comment, $request, $workflow, &$done, &$errors) {
            DB::transaction(function () use ($queries, $status, $comment, $request, $workflow, &$done, &$errors) {
                foreach ($queries as $query) {
                    try {
                        if (! $status && $request->user()->cannot('comment', $query)) {
                            throw new InvalidArgumentException('hors de votre périmètre');
                        }
                        $status
                            ? $workflow->transition($query, $status, $comment, $request->user(), $request->validated('password'))
                            : $workflow->comment($query, (string) $comment, $request->user());
                        $done++;
                    } catch (InvalidArgumentException $e) {
                        $errors[] = "{$query->code} : {$e->getMessage()}";
                    }
                }
            });
        }, $request->user());

        return response()->json(self::bulkResult($done, $errors, 'mise(s) à jour'));
    }

    public function run(Request $request): RedirectResponse
    {
        RunQualityChecksJob::dispatch('manual', $request->user()->id);
        AuditLog::record('quality.run');

        return back()->with('success', 'Le moteur de queries a été lancé en arrière-plan. Les résultats apparaîtront dans quelques instants.');
    }
}
