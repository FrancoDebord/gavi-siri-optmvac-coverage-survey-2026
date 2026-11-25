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
use App\Models\Collector;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Models\Team;
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
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryFocus;
use App\Services\Quality\QueryWorkflow;
use App\Services\Quality\RelatedFields;
use App\Services\Quality\TeamVillageCheck;
use App\Support\Filters;
use App\Support\HouseholdCode;
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
                // Un numéro de query (Q-000123, Q123, 123) cible directement la query.
                if (($id = DataQuery::idFromCode($search)) !== null) {
                    $q->orWhere('data_queries.id', $id);
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

        // Barre de progression : mêmes filtres, tous statuts de résolution confondus.
        $extra = ['status' => null, 'resolution' => null, 'reviewer' => null, 'overdue' => null, 'code' => null] + QueryDashboardController::extra($request);
        $byStatus = (new QueryStats(Filters::fromRequest($request), $extra))->queries()->selectRaw('data_queries.status, count(*) as n')
            ->groupBy('data_queries.status')->pluck('n', 'status');

        return response()->json([
            'draw' => $request->integer('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'progress' => collect(DataQuery::RESOLUTIONS)->map(fn ($r) => (int) collect($r['statuses'])->sum(fn ($s) => $byStatus[$s] ?? 0)),
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
        $query->load(['rule', 'household', 'child', 'healthZone', 'commune', 'village', 'cluster', 'collector', 'events.user', 'assignee', 'responder', 'validator', 'suggester', 'reviewer', 'supervisor']);

        $user = request()->user();

        $focus = QueryFocus::for($query);
        $geo = $this->geoField($query);
        $agent = $user->can('respond', $query) ? $this->agentField($query) : null;

        return view('queries.show', [
            'query' => $query,
            'focus' => $focus,
            'corrections' => DataCorrection::where('data_query_id', $query->id)->with(['proposer:id,name', 'decider:id,name'])->latest('id')->get(),
            'pendingCorrections' => $query->household_id ? DataCorrection::where('household_id', $query->household_id)->where('status', DataCorrection::STATUS_PROPOSED)
                ->where('variable', '!=', DataCorrection::EXCLUSION)->count() : 0,
            'correctionField' => $user->can('respond', $query) && ! $geo && ! $agent ? $this->correctionField($query, $focus['suggested_value']) : null,
            'geoField' => $user->can('respond', $query) ? $geo : null,
            'agentField' => $agent,
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
    public const GEO_RULES = ['GEO_HIERARCHY', 'HH_CODE', 'GPS_CLUSTER_DISTANCE', 'TEAM_VILLAGE'];

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
        $geoRule = in_array($query->rule_code, self::GEO_RULES, true) && ($query->rule_code !== 'TEAM_VILLAGE' || $query->variable === $villageVar);
        if (! $query->household || ! $arrDef || ! $villageDef || ! ($geoRule || in_array($query->variable, [$arrVar, $villageVar], true))) {
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
        $arrCurrent = (string) ($clean->data[$arrVar] ?? '');
        $villageCurrent = (string) ($clean->data[$villageVar] ?? '');
        // Village proposé (ex. d'après l'identifiant ménage) : pré-coché avec son arrondissement, s'il est de la commune.
        $proposed = $query->variable === $villageVar && $query->suggested_value !== null
            ? $villages->first(fn ($r) => (string) $r['name'] === (string) $query->suggested_value) : null;

        $codeVar = Map::var('id_menage');
        $codeCurrent = (string) ($clean->data[$codeVar] ?? '');

        return [
            // Identifiant ménage, modifiable avec le village (il peut aussi être en cause).
            'code_var' => $codeVar,
            'code_current' => $codeCurrent,
            'code_proposed' => $query->variable === $codeVar && $query->suggested_value !== null ? (string) $query->suggested_value : HouseholdCode::normalize($codeCurrent),
            'arr_selected' => $proposed ? (string) $proposed['extra']['parent'] : $arrCurrent,
            'village_selected' => $proposed ? (string) $proposed['name'] : $villageCurrent,
            'village_proposed' => $proposed ? (string) $proposed['name'] : null,
            'arr_var' => $arrVar,
            'village_var' => $villageVar,
            'arr_label' => trim(strip_tags((string) $arrDef['label'])),
            'village_label' => trim(strip_tags((string) $villageDef['label'])),
            'arr_current' => $arrCurrent,
            'village_current' => $villageCurrent,
            'arrondissements' => $arrondissements->sortBy(fn ($r) => mb_strtolower((string) $r['label']))->map(fn ($r) => ['code' => $r['name'], 'label' => $label($r)])->values()->all(),
            'villages' => $villages->map(fn ($r) => ['code' => $r['name'], 'label' => $label($r), 'parent' => (string) $r['extra']['parent']])->values()->all(),
        ];
    }

    /**
     * Agent à corriger (ex. agent d'un autre binôme sélectionné) : binômes de la zone sanitaire du ménage avec leurs
     * agents, à cocher ; binôme désigné par la query (compte ODK) en tête, agent saisi signalé.
     */
    private function agentField(DataQuery $query): ?array
    {
        $variable = Map::var('binome');
        if (! $variable || $query->variable !== $variable || ! $query->household) {
            return null;
        }
        $current = (string) (app(CleanDataService::class)->cleanSubmission($query->household)->data[$variable] ?? '');
        $zone = $query->household->health_zone_id;
        $agent = fn (Collector $c) => ['code' => (string) $c->code, 'label' => $c->name];
        $teams = Team::with('collectors')->when($zone, fn ($q) => $q->where('health_zone_id', $zone))->orderBy('code')->get()
            ->map(fn (Team $t) => ['id' => $t->id, 'label' => TeamVillageCheck::teamLabel($t), 'proposed' => (int) $t->id === (int) $query->team_id, 'agents' => $t->collectors->map($agent)->all()])
            ->sortByDesc('proposed')->values();
        // Agents de la zone sans binôme (ex. superviseurs).
        $others = Collector::whereNull('team_id')->when($zone, fn ($q) => $q->where('health_zone_id', $zone))->orderBy('code')->get();
        if ($others->isNotEmpty()) {
            $teams->push(['id' => null, 'label' => 'Agents sans binôme', 'proposed' => false, 'agents' => $others->map($agent)->all()]);
        }

        return ['variable' => $variable, 'current' => $current, 'teams' => $teams->all()];
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
        if (! $query->household || ! $definition) {
            return null;
        }
        // Variable calculée (ex. âge en mois), ou question d'un membre contrôlée sur tout le ménage (ex. enfants déclarés
        // par chaque caregiver) : on corrige à la place les questions en cause, chacune dans son membre.
        $computed = ! $dictionary->isInput($definition) || (($definition['repeat_name'] ?? null) && ! $query->instance_path);
        try {
            $clean = app(CleanDataService::class)->cleanSubmission($query->household);
            $source = SourceSnapshot::of($query->household);
            $related = collect(RelatedFields::for($query, $clean))->map(function (array $field) use ($dictionary, $clean, $source, $query) {
                $definition = $dictionary->variable($field['variable']);
                $path = $field['path'] ?? $query->instance_path;

                return ['path' => $path] + $field + [
                    'question' => self::questionLabel($definition),
                    'current' => CorrectionApplier::normalize(SubmissionPaths::get($clean, $field['variable'], $path)),
                    'source' => CorrectionApplier::normalize(SubmissionPaths::get($source, $field['variable'], $path)),
                    'type' => $definition['base_type'],
                    'choices' => self::choicesFor($dictionary, $definition, $clean, $path),
                ];
            })->all();
            if ($computed) {
                if ($related === []) {
                    return null;
                }
                $field = array_shift($related) + ['suggested' => null];
                $field['short_label'] = $field['label'];
                $field['label'] = $field['question'];
            } else {
                $field = [
                    'variable' => $query->variable,
                    'path' => $query->instance_path,
                    'label' => $query->variable_label,
                    'question' => $query->variable_label ?: self::questionLabel($definition),
                    'current' => CorrectionApplier::normalize(SubmissionPaths::get($clean, $query->variable, $query->instance_path)),
                    'source' => CorrectionApplier::normalize(SubmissionPaths::get($source, $query->variable, $query->instance_path)),
                    'type' => $definition['base_type'],
                    'choices' => self::choicesFor($dictionary, $definition, $clean, $query->instance_path),
                    'suggested' => $suggested,
                    'short_label' => RelatedFields::label($query->variable),
                ];
            }
        } catch (InvalidArgumentException) {
            return null;
        }
        // Autres questions en cause (ex. VPO 2 pour « VPO 3 avant VPO 2 ») : l'agent choisit celle qui est fausse.
        $field['related'] = $related;
        // Choix « quelle valeur est fausse » : la question de la query puis les autres, valeur lisible (date jj/mm/aaaa).
        // Règle avec un choix « aucune valeur fausse » (ex. MAJ_18, âge confirmé) : choix proposé même pour une seule question.
        $field['pair'] = ($field['related'] || isset(self::NO_ERROR[$query->rule_code])) ? array_map(fn (array $f) => $f + [
            'display' => ($f['type'] === 'date' ? SubmissionProcessor::date($f['current'])?->format('d/m/Y') : null) ?? DataCorrection::displayFor($f['variable'], $f['current']),
        ], array_merge([$field], $field['related'])) : [];

        return $field;
    }

    /** Libellé de la question dans le formulaire, sans balises (« 52. L'enfant a-t-il déjà reçu… »). */
    private static function questionLabel(?array $definition): ?string
    {
        $label = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($definition['label'] ?? ''))));

        return $label !== '' ? $label : null;
    }

    /**
     * Choix proposés pour corriger une question : ceux du dictionnaire, restreints comme dans le formulaire quand la
     * question a un filtre simple « colonne=${variable} » (ex. campagnes du pays : country=${country}).
     *
     * @return array<string, mixed> code => libellé
     */
    private static function choicesFor(Dictionary $dictionary, array $definition, NormalizedSubmission $clean, ?string $path): array
    {
        $choices = $definition['list_name'] ? ($dictionary->choices($definition['list_name']) ?? []) : [];
        if (! $choices || ! preg_match('/^\s*(\w+)\s*=\s*\$\{(\w+)\}\s*$/', (string) ($definition['choice_filter'] ?? ''), $m)) {
            return $choices;
        }
        $value = SubmissionPaths::get($clean, $m[2], $path) ?? $clean->data[$m[2]] ?? null;
        if ($value === null || $value === '') {
            return $choices;
        }
        $kept = collect($dictionary->choiceRows($definition['list_name']))
            ->filter(fn (array $row) => (string) ($row['extra'][$m[1]] ?? '') === (string) $value)->pluck('name')->all();

        return $kept ? array_intersect_key($choices, array_flip($kept)) : $choices;
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

    /**
     * Choix « aucune valeur fausse » dans « Quelle valeur est fausse ? » : rien n'est corrigé, la query est enregistrée
     * comme valeur confirmée avec cette justification (complétée par le commentaire de l'agent).
     */
    public const NO_ERROR = [
        'MAJ_37' => [
            'label' => 'Aucune : les dates saisies sont bien celles de la carte, l\'erreur vient de la carte CPS elle-même',
            'comment' => 'Aucune date fausse : les dates saisies sont conformes à la carte CPS, l\'erreur vient de la carte elle-même.',
        ],
        'MAJ_10' => [
            'label' => 'Aucune : les tranches d\'âge sont justes, le ménage n\'a pas de membre de 15 ans ou plus',
            'comment' => 'Tranches d\'âge vérifiées et confirmées : le ménage n\'a aucun membre de 15 ans ou plus.',
        ],
        // Âge confirmé : l'enfant a bien 5 ans ou plus (listé à tort). Rien à corriger ; à la validation finale, la query
        // est fermée comme irrésoluble (closes_as).
        'MAJ_18' => [
            'legend' => 'L\'âge de l\'enfant est-il faux ?',
            'hint' => 'Vérifiez l\'âge avec le caregiver ou sur le document de naissance, puis corrigez la valeur fausse.',
            'label' => 'Non : l\'âge saisi est juste, l\'enfant a bien 5 ans ou plus (listé à tort)',
            'comment' => 'Âge confirmé : l\'enfant a bien 5 ans ou plus et a été listé à tort ; query à fermer comme irrésoluble.',
            'closes_as' => DataQuery::STATUS_UNRESOLVABLE,
        ],
    ];

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
            $status = $request->validated('status');
            $comment = $request->validated('comment');
            // Aucune valeur fausse (ex. erreur sur la carte CPS elle-même, ménage réellement sans adulte) : valeurs
            // confirmées, sans correction.
            if ($status === DataQuery::STATUS_CORRECTED && in_array($request->validated('pair_choice'), ['none', 'card'], true) && isset(self::NO_ERROR[$query->rule_code])) {
                [$status, $corrections] = [DataQuery::STATUS_JUSTIFIED, []];
                $comment = trim(self::NO_ERROR[$query->rule_code]['comment'].' '.trim((string) $comment));
                $closesAs = self::NO_ERROR[$query->rule_code]['closes_as'] ?? null;
                // Data manager / administrateur : fermeture directe (irrésoluble), sans passer par la validation.
                if ($closesAs && QueryWorkflow::allowed($request->user(), $query, $closesAs) && $query->canTransitionTo($closesAs)) {
                    [$status, $closesAs] = [$closesAs, null];
                }
            }
            // Réponse du binôme : fermeture attendue à la validation finale (ex. irrésoluble), ou aucune.
            if (in_array($status, DataQuery::RESPONSES, true)) {
                $query->closes_as = $closesAs ?? null;
            }
            $workflow->transition($query, $status, $comment, $request->user(), $request->validated('password'), $corrections);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->withInput();
        }

        $auto = $workflow->lastAutoApply;

        return back()->with('success', match (true) {
            $auto !== null && $auto['applied'] => 'Correction prise en compte : l\'erreur est levée.',
            $auto !== null => 'Correction enregistrée ; elle sera examinée par le data manager ('.$auto['reason'].').',
            in_array($request->validated('status'), DataQuery::RESPONSES, true) => 'Réponse envoyée au superviseur.',
            $request->validated('status') === DataQuery::STATUS_SUPERVISED => 'Réponse vérifiée : envoyée en validation finale.',
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

    /**
     * Recharge les données du ménage avec les corrections proposées : les queries dont l'erreur disparaît suivent
     * la correction (fermées à sa validation). Seules les queries auxquelles l'utilisateur peut répondre changent.
     */
    public function reload(Request $request, DataQuery $query, CleanDataService $cleaning): RedirectResponse
    {
        $this->authorize('comment', $query);
        abort_unless($query->household, 404);
        try {
            $result = $cleaning->reloadProposed($query->household, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('danger', $e->getMessage());
        }
        AuditLog::record('queries.rechargement', $query, ['resolues' => count($result['resolved'])]);
        if ($result['origins'] === 0) {
            return back()->with('info', 'Aucune correction en attente sur ce ménage : rien à recharger.');
        }
        if ($result['resolved'] === []) {
            return back()->with('info', 'Données rechargées : aucune autre query n\'est résolue par les corrections proposées.');
        }

        return back()->with('success', sprintf('Données rechargées : %d query(s) résolue(s) par les corrections proposées (%s). Elles seront fermées à la validation de la correction.',
            count($result['resolved']), implode(', ', array_map(fn ($r) => $r['query']->code, $result['resolved']))));
    }

    public function run(Request $request): RedirectResponse
    {
        RunQualityChecksJob::dispatch('manual', $request->user()->id);
        AuditLog::record('quality.run');

        return back()->with('success', 'Le moteur de queries a été lancé en arrière-plan. Les résultats apparaîtront dans quelques instants.');
    }
}
