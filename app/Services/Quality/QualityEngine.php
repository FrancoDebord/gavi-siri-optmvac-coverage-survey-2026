<?php

namespace App\Services\Quality;

use App\Models\AppSetting;
use App\Models\Collector;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Models\QueryEvent;
use App\Models\User;
use App\Services\Dictionary\Dictionary;
use App\Services\Quality\Rules\ExpressionRule;
use App\Support\DashboardCache;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moteur de queries :
 * 1. prépare les règles transversales (doublons, volumes, aberrants, centroïdes) ;
 * 2. évalue chaque règle active sur chaque soumission ;
 * 3. crée les nouvelles queries, rafraîchit celles retrouvées (même empreinte),
 *    rouvre celles fermées automatiquement qui réapparaissent ;
 * 4. ferme automatiquement les queries non retrouvées (donnée corrigée).
 */
class QualityEngine
{
    /** @var array<string, array{id: int, status: string, auto_closed: bool, observed: ?string}> */
    private array $existing = [];

    private array $toInsert = [];

    /** @var array<int, int> agent → compte du binôme (affectation automatique) */
    private array $teamAccounts = [];

    /** @var array<int, int> binôme => compte binôme (affectation automatique) */
    private array $teamAccountsByTeam = [];

    private array $touched = [];

    private int $opened = 0;

    private Dictionary $dictionary;

    /**
     * @param  list<int>|null  $householdIds  vérification limitée à ces ménages (après une correction) ; null = tous
     */
    public function run(string $trigger = 'manual', ?int $userId = null, ?array $householdIds = null): QualityRun
    {
        $run = QualityRun::create(['user_id' => $userId, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now()]);
        $startedAt = now();

        try {
            $dictionary = $this->dictionary = Dictionary::current();
            $today = CarbonImmutable::today();
            $rules = $this->loadRules();
            if ($householdIds !== null) {
                // Vérification ciblée (correction) : les observations de supervision (paradonnées), qu'une correction
                // ne change pas, restent à la passe complète ; leurs anomalies ne sont ni réévaluées ni fermées.
                $rules = array_values(array_filter($rules, fn ($r) => ($r[0]->kind ?: QualityRule::KIND_QUERY) === QualityRule::KIND_QUERY));
                Household::$datasetIds = $this->neighbourhood($householdIds);
            }
            try {
                foreach ($rules as [$model, $handler]) {
                    if ($handler instanceof PreparesDataset) {
                        $handler->prepare($model);
                    }
                }
            } finally {
                Household::$datasetIds = null;
            }

            $this->toInsert = $this->touched = [];
            $this->opened = 0;
            // Les queries des soumissions exclues de la base nettoyée ne sont plus réévaluées (fermées à l'exclusion).
            $this->existing = DataQuery::withoutGlobalScope('kind')
                ->when($householdIds !== null, fn ($q) => $q->whereIn('household_id', $householdIds ?: [0]))
                ->where(fn ($q) => $q->whereNull('household_id')->orWhereNotIn('household_id', Household::withoutGlobalScope('included')->whereNotNull('excluded_at')->select('id')))->get(['id', 'fingerprint', 'rule_code', 'status', 'auto_closed', 'observed_value', 'assigned_to', 'suggested_source'])
                ->mapWithKeys(fn ($q) => [$q->fingerprint => ['id' => $q->id, 'rule_code' => $q->rule_code, 'status' => $q->status, 'auto_closed' => $q->auto_closed, 'observed' => $q->observed_value, 'assigned_to' => $q->assigned_to, 'suggested_source' => $q->suggested_source]])
                ->all();
            $this->prepareAutoAssignment();

            $checked = 0;
            $failures = [];
            Household::with(['caregivers', 'children'])
                ->when($householdIds !== null, fn ($q) => $q->whereIn('id', $householdIds ?: [0]))->chunkById(200, function ($households) use ($rules, $dictionary, $today, &$checked, &$failures, $startedAt) {
                    foreach ($households as $household) {
                        $context = new SubmissionContext($household, $dictionary, $today);
                        foreach ($rules as [$model, $handler]) {
                            try {
                                foreach ($handler->evaluate($context, $model) as $candidate) {
                                    $this->collect($model, $household, $candidate, $startedAt);
                                }
                            } catch (Throwable $e) {
                                $failures[$model->code] ??= $e->getMessage();
                            }
                        }
                        $checked++;
                    }
                    $this->flush();
                });

            foreach ($failures as $code => $message) {
                Log::warning("Règle qualité {$code} en erreur : {$message}");
            }

            // Une règle en erreur n'a rien prouvé : ses queries ne sont pas fermées.
            $closed = $this->autoClose(collect($rules)->map(fn ($r) => $r[0]->code)->diff(array_keys($failures))->all());

            $run->update([
                'status' => 'success',
                'households_checked' => $checked,
                'opened_count' => $this->opened,
                'auto_closed_count' => $closed,
                'error' => $failures ? json_encode($failures, JSON_UNESCAPED_UNICODE) : null,
            ]);
            DashboardCache::bump();
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        } finally {
            $run->update(['finished_at' => now()]);
        }

        return $run->fresh();
    }

    /**
     * Voisinage d'une vérification ciblée : les ménages ciblés et ceux de la même grappe ou du même agent, seuls
     * comparables pour les doublons, les valeurs aberrantes (par agent ou par grappe) et la distance à la grappe.
     *
     * @return list<int>
     */
    private function neighbourhood(array $householdIds): array
    {
        $targets = Household::withoutGlobalScope('included')->whereIn('id', $householdIds ?: [0])->get(['id', 'cluster_id', 'collector_id']);
        $clusters = $targets->pluck('cluster_id')->filter()->unique()->all();
        $collectors = $targets->pluck('collector_id')->filter()->unique()->all();

        return Household::query()->where(fn ($q) => $q->whereIn('cluster_id', $clusters ?: [0])->orWhereIn('collector_id', $collectors ?: [0]))
            ->pluck('id')->merge($targets->pluck('id'))->unique()->values()->all();
    }

    /**
     * Affectation automatique des nouvelles queries (paramètre « queries.auto_assign ») :
     * « team » = compte du binôme dont l'agent a collecté le ménage, avec l'échéance par défaut.
     */
    private function prepareAutoAssignment(): void
    {
        $this->teamAccounts = $this->teamAccountsByTeam = [];
        if (AppSetting::get(AppSetting::AUTO_ASSIGN) !== 'team') {
            return;
        }
        $accounts = User::role(User::ROLE_TEAM)->whereNotNull('team_id')->orderBy('id')->get(['id', 'team_id'])
            ->unique('team_id')->pluck('id', 'team_id');
        $this->teamAccountsByTeam = $accounts->all();
        $this->teamAccounts = Collector::whereNotNull('team_id')->pluck('team_id', 'id')
            ->map(fn ($teamId) => $accounts[$teamId] ?? null)->filter()->all();
    }

    /** Colonnes d'affectation d'une nouvelle query (vides sans affectation automatique). */
    private function autoAssignment(QualityRule $rule, Household $household, CarbonInterface $now, ?int $teamId = null): array
    {
        // Binôme désigné par la règle (ex. celui du compte ODK), sinon celui de l'agent sélectionné.
        $assignee = ($teamId ? ($this->teamAccountsByTeam[$teamId] ?? null) : null) ?? $this->teamAccounts[$household->collector_id] ?? null;
        if (! $assignee || ($rule->kind ?: QualityRule::KIND_QUERY) !== QualityRule::KIND_QUERY) {
            return ['assigned_to' => null, 'assigned_at' => null, 'due_on' => null];
        }
        $days = (int) AppSetting::get(AppSetting::DUE_DAYS);

        return [
            'status' => DataQuery::STATUS_SENT,
            'assigned_to' => $assignee,
            'assigned_at' => $now,
            'due_on' => $days > 0 ? CarbonImmutable::parse($now)->addDays($days)->toDateString() : null,
        ];
    }

    /** @return list<array{0: QualityRule, 1: QualityRuleInterface}> */
    private function loadRules(): array
    {
        $rules = [];
        foreach (QualityRule::where('is_active', true)->get() as $rule) {
            $class = $rule->category === 'expression' ? ExpressionRule::class : ($rule->handler ?: RuleCatalog::find($rule->code)['handler'] ?? null);
            if (! $class || ! class_exists($class)) {
                Log::warning("Règle qualité {$rule->code} : classe introuvable ({$class}).");

                continue;
            }
            $rules[] = [$rule, app($class)];
        }

        return $rules;
    }

    private function collect(QualityRule $rule, Household $household, QueryCandidate $candidate, CarbonInterface $now): void
    {
        $fingerprint = $candidate->fingerprint($rule->code, $household->id);
        if (isset($this->toInsert[$fingerprint]) || isset($this->touched[$fingerprint])) {
            return;
        }
        $observed = $candidate->observedAsString();
        $existing = $this->existing[$fingerprint] ?? null;
        $description = $this->describe($rule, $candidate, $observed);

        if (! $existing) {
            $this->toInsert[$fingerprint] = $description + [
                'fingerprint' => $fingerprint,
                'quality_rule_id' => $rule->id,
                'rule_code' => $rule->code,
                'severity' => $rule->severity,
                'household_id' => $household->id,
                'caregiver_id' => $candidate->scope?->caregiverId,
                'child_id' => $candidate->scope?->childId,
                'team_id' => $candidate->teamId,
                'submission_uuid' => $household->submission_uuid,
                'household_code' => $household->household_code,
                'health_zone_id' => $household->health_zone_id,
                'commune_id' => $household->commune_id,
                'village_id' => $household->village_id,
                'cluster_id' => $household->cluster_id,
                'collector_id' => $household->collector_id,
                'collection_date' => $household->interview_date?->toDateString(),
                'variable' => $candidate->variable,
                'instance_path' => $candidate->scope?->path ?: null,
                'observed_value' => $observed,
                'expected' => $candidate->expected,
                'message' => mb_substr($candidate->message, 0, 2000),
                'kind' => $rule->kind ?: QualityRule::KIND_QUERY,
                'status' => DataQuery::STATUS_OPEN,
                ...$this->ruleSuggestion($candidate, $now),
                'detected_at' => $now,
                'last_detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->toInsert[$fingerprint] = $this->autoAssignment($rule, $household, $now, $candidate->teamId) + $this->toInsert[$fingerprint];

            return;
        }

        $this->touched[$fingerprint] = true;
        $updates = $description + [
            'last_detected_at' => $now,
            // Les identifiants d'enfant/caregiver changent quand une soumission est réimportée.
            'caregiver_id' => $candidate->scope?->caregiverId,
            'child_id' => $candidate->scope?->childId,
            'team_id' => $candidate->teamId,
            'severity' => $rule->severity,
            'kind' => $rule->kind ?: QualityRule::KIND_QUERY,
            'collector_id' => $household->collector_id,
            'cluster_id' => $household->cluster_id,
            'collection_date' => $household->interview_date?->toDateString(),
        ];
        // Valeur proposée par la règle : jamais à la place d'une valeur lue sur la photo ou par l'IA.
        if (in_array($existing['suggested_source'], [null, DataQuery::SUGGESTION_RULE], true)) {
            $updates += $this->ruleSuggestion($candidate, $now);
        }
        if ($observed !== $existing['observed']) {
            $updates += ['observed_value' => $observed, 'message' => mb_substr($candidate->message, 0, 2000), 'expected' => $candidate->expected];
        }
        // L'anomalie réapparaît après une fermeture automatique : la query est rouverte (et rendue à son assigné).
        if ($existing['status'] === DataQuery::STATUS_VALIDATED && $existing['auto_closed']) {
            $reopened = $existing['assigned_to'] ? DataQuery::STATUS_SENT : DataQuery::STATUS_OPEN;
            $updates += ['status' => $reopened, 'auto_closed' => false, 'closed_at' => null, 'resolved_at' => null, 'validated_by' => null];
            QueryEvent::create(['data_query_id' => $existing['id'], 'type' => 'system', 'from_status' => DataQuery::STATUS_VALIDATED, 'to_status' => $reopened, 'comment' => 'Anomalie de nouveau détectée : query rouverte.']);
            $this->opened++;
        }
        DataQuery::withoutGlobalScope('kind')->whereKey($existing['id'])->update($updates);
    }

    /** Valeur suggérée par la règle elle-même (ex. village d'après l'identifiant ménage), sinon aucune. */
    private function ruleSuggestion(QueryCandidate $candidate, CarbonInterface $now): array
    {
        $has = $candidate->suggestedValue !== null;

        return [
            'suggested_value' => $has ? $candidate->suggestedValue : null,
            'suggested_source' => $has ? DataQuery::SUGGESTION_RULE : null,
            'suggested_note' => $has ? mb_substr((string) $candidate->suggestedNote, 0, 255) ?: null : null,
            'suggested_at' => $has ? $now : null,
        ];
    }

    /**
     * Libellé de la query, libellé de la question concernée et suggestion de résolution
     * (gabarit de la règle complété avec la question, la valeur observée et la valeur attendue).
     *
     * @return array{label: string, variable_label: ?string, suggestion: ?string}
     */
    private function describe(QualityRule $rule, QueryCandidate $candidate, ?string $observed): array
    {
        $question = null;
        if ($candidate->variable) {
            $definition = $this->dictionary->variable($candidate->variable) ?? $this->dictionary->group($candidate->variable);
            $question = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($definition['label'] ?? '')))) ?: null;
        }

        $template = $candidate->suggestion ?? $rule->suggestion ?? (RuleCatalog::SUGGESTIONS[$rule->code] ?? null);
        $suggestion = $template ? strtr($template, [
            '{question}' => $question ?? $candidate->variable ?? '—',
            '{variable}' => $candidate->variable ?? '—',
            '{valeur}' => $observed ?? 'vide',
            '{attendu}' => $candidate->expected ?? '—',
        ]) : null;

        $label = $rule->name;
        if ($candidate->variable) {
            $label .= ' – '.($question ? Str::limit($question, 120) : $candidate->variable);
        }

        return [
            'label' => Str::limit($label, 250),
            'variable_label' => $question ? Str::limit($question, 250) : null,
            'suggestion' => $suggestion ? mb_substr($suggestion, 0, 2000) : null,
        ];
    }

    private function flush(): void
    {
        foreach (array_chunk($this->toInsert, 200) as $chunk) {
            DataQuery::insert(array_values($chunk));
            $this->opened += count($chunk);
        }
        foreach (array_keys($this->toInsert) as $fingerprint) {
            $this->touched[$fingerprint] = true;
        }
        $this->toInsert = [];
    }

    /**
     * Queries non retrouvées pendant cette exécution (et dont la règle a tourné) :
     * la donnée a été corrigée, la query est validée automatiquement.
     */
    private function autoClose(array $ruleCodes): int
    {
        $queries = collect($this->existing)
            ->filter(fn ($q, $fingerprint) => ! isset($this->touched[$fingerprint])
                && $q['status'] !== DataQuery::STATUS_VALIDATED
                && in_array($q['rule_code'], $ruleCodes, true))
            ->map(fn ($q) => (object) ['id' => $q['id'], 'status' => $q['status']])
            ->values();

        DB::transaction(function () use ($queries) {
            foreach ($queries->chunk(500) as $chunk) {
                $ids = $chunk->pluck('id');
                DataQuery::withoutGlobalScope('kind')->whereIn('id', $ids)->whereNull('resolved_at')->update(['resolved_at' => now()]);
                DataQuery::withoutGlobalScope('kind')->whereIn('id', $ids)->update([
                    'status' => DataQuery::STATUS_VALIDATED,
                    'auto_closed' => true,
                    'closed_at' => now(),
                ]);
                QueryEvent::insert($chunk->map(fn ($q) => [
                    'data_query_id' => $q->id,
                    'type' => 'system',
                    'from_status' => $q->status,
                    'to_status' => DataQuery::STATUS_VALIDATED,
                    'comment' => 'Anomalie non retrouvée à la dernière vérification (donnée corrigée) : fermeture automatique.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }
        });

        return $queries->count();
    }
}
