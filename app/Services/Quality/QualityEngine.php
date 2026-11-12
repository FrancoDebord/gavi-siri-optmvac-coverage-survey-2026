<?php

namespace App\Services\Quality;

use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Models\QueryEvent;
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

    private array $touched = [];

    private int $opened = 0;

    private Dictionary $dictionary;

    public function run(string $trigger = 'manual', ?int $userId = null): QualityRun
    {
        $run = QualityRun::create(['user_id' => $userId, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now()]);
        $startedAt = now();

        try {
            $dictionary = $this->dictionary = Dictionary::current();
            $today = CarbonImmutable::today();
            $rules = $this->loadRules();

            foreach ($rules as [$model, $handler]) {
                if ($handler instanceof PreparesDataset) {
                    $handler->prepare($model);
                }
            }

            $this->toInsert = $this->touched = [];
            $this->opened = 0;
            $this->existing = DataQuery::get(['id', 'fingerprint', 'rule_code', 'status', 'auto_closed', 'observed_value'])
                ->mapWithKeys(fn ($q) => [$q->fingerprint => ['id' => $q->id, 'rule_code' => $q->rule_code, 'status' => $q->status, 'auto_closed' => $q->auto_closed, 'observed' => $q->observed_value]])
                ->all();

            $checked = 0;
            $failures = [];
            Household::with(['caregivers', 'children'])->chunkById(200, function ($households) use ($rules, $dictionary, $today, &$checked, &$failures, $startedAt) {
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
                'status' => DataQuery::STATUS_OPEN,
                'detected_at' => $now,
                'last_detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            return;
        }

        $this->touched[$fingerprint] = true;
        $updates = $description + [
            'last_detected_at' => $now,
            // Les identifiants d'enfant/caregiver changent quand une soumission est réimportée.
            'caregiver_id' => $candidate->scope?->caregiverId,
            'child_id' => $candidate->scope?->childId,
            'severity' => $rule->severity,
            'collector_id' => $household->collector_id,
            'cluster_id' => $household->cluster_id,
            'collection_date' => $household->interview_date?->toDateString(),
        ];
        if ($observed !== $existing['observed']) {
            $updates += ['observed_value' => $observed, 'message' => mb_substr($candidate->message, 0, 2000), 'expected' => $candidate->expected];
        }
        // L'anomalie réapparaît après une fermeture automatique : la query est rouverte.
        if ($existing['status'] === DataQuery::STATUS_VALIDATED && $existing['auto_closed']) {
            $updates += ['status' => DataQuery::STATUS_OPEN, 'auto_closed' => false, 'closed_at' => null, 'resolved_at' => null];
            QueryEvent::create(['data_query_id' => $existing['id'], 'type' => 'system', 'from_status' => DataQuery::STATUS_VALIDATED, 'to_status' => DataQuery::STATUS_OPEN, 'comment' => 'Anomalie de nouveau détectée : query rouverte.']);
            $this->opened++;
        }
        DataQuery::whereKey($existing['id'])->update($updates);
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
                DataQuery::whereIn('id', $ids)->whereNull('resolved_at')->update(['resolved_at' => now()]);
                DataQuery::whereIn('id', $ids)->update([
                    'status' => DataQuery::STATUS_VALIDATED,
                    'auto_closed' => true,
                    'closed_at' => now(),
                ]);
                QueryEvent::insert($chunk->map(fn ($q) => [
                    'data_query_id' => $q->id,
                    'type' => 'system',
                    'from_status' => $q->status,
                    'to_status' => DataQuery::STATUS_VALIDATED,
                    'comment' => 'Donnée corrigée : anomalie non retrouvée à la dernière vérification, query fermée automatiquement.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }
        });

        return $queries->count();
    }
}
