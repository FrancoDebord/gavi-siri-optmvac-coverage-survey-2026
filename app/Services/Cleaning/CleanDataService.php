<?php

namespace App\Services\Cleaning;

use App\Models\DataCorrection;
use App\Models\DataLock;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QueryEvent;
use App\Models\User;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Services\Quality\QueryWorkflow;
use App\Services\Quality\Rules\Dictionary\TypeRule;
use App\Support\DashboardCache;
use App\Support\MultipleChoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Base nettoyée : propositions de correction (contrôlées selon le dictionnaire), décision du data manager,
 * reconstruction des ménages (source + corrections validées) et vérification des queries par le moteur :
 * la query est résolue si l'anomalie a disparu de la base nettoyée, sinon elle revient à l'assigné.
 */
class CleanDataService
{
    public function __construct(private readonly QualityEngine $engine) {}

    /** Soumission nettoyée actuelle d'un ménage (source + corrections validées). */
    public function cleanSubmission(Household $household): NormalizedSubmission
    {
        return (new CorrectionApplier)->apply(SourceSnapshot::of($household))[0];
    }

    /**
     * Enregistre les corrections proposées en réponse à une query (statut « proposée »).
     *
     * @param  list<array{variable?: string, instance_path?: ?string, new_value?: ?string, reason?: string, source_reference?: ?string, comment?: ?string}>  $rows
     * @return Collection<int, DataCorrection>
     */
    public function propose(DataQuery $query, array $rows, ?User $user): Collection
    {
        $household = $query->household ?? throw new InvalidArgumentException('Query sans soumission : aucune valeur à corriger.');
        $dictionary = Dictionary::current();
        $source = SourceSnapshot::of($household);
        $clean = $this->cleanSubmission($household);
        $created = collect();
        $seen = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $variable = trim((string) ($row['variable'] ?? ''));
            $path = trim((string) ($row['instance_path'] ?? '')) ?: null;
            $reason = (string) ($row['reason'] ?? '');
            $newValue = CorrectionApplier::normalize($row['new_value'] ?? null);
            $comment = trim((string) ($row['comment'] ?? '')) ?: null;

            // Exclusion de la soumission (doublon) : pseudo-variable au niveau du ménage.
            if ($variable === DataCorrection::EXCLUSION) {
                if ($path !== null || ! array_key_exists($reason, DataCorrection::REASONS)) {
                    throw new InvalidArgumentException("Correction {$n} : l'exclusion porte sur toute la soumission ; indiquez le motif.");
                }
                if (isset($clean->data[DataCorrection::EXCLUSION])) {
                    throw new InvalidArgumentException('Cette soumission est déjà exclue de la base nettoyée.');
                }
                if (! $comment) {
                    throw new InvalidArgumentException('Exclusion : indiquez dans le commentaire la soumission conservée et pourquoi celle-ci est écartée.');
                }
                $created->push(new DataCorrection([
                    'submission_uuid' => $household->submission_uuid, 'household_id' => $household->id, 'data_query_id' => $query->id,
                    'variable' => DataCorrection::EXCLUSION, 'instance_path' => null, 'source_value' => null, 'old_value' => null, 'new_value' => '1',
                    'reason' => $reason, 'source_reference' => trim((string) ($row['source_reference'] ?? '')) ?: null, 'comment' => $comment,
                    'status' => DataCorrection::STATUS_PROPOSED, 'proposed_by' => $user?->id, 'proposed_at' => now(),
                ]));

                continue;
            }

            $definition = $dictionary->variable($variable);
            if (! $definition || ! $dictionary->isInput($definition)) {
                throw new InvalidArgumentException("Correction {$n} : « {$variable} » n'est pas une question saisissable du formulaire.");
            }
            $expected = $dictionary->repeatPath($variable);
            $given = array_column(SubmissionPaths::parse($path), 0);
            if ($given !== $expected) {
                throw new InvalidArgumentException("Correction {$n} : « {$variable} » se trouve ".($expected ? 'dans le repeat '.implode(' › ', $expected).' : précisez le membre (ex. '.implode('/', array_map(fn ($r) => $r.'[1]', $expected)).').' : 'au niveau du ménage : pas de membre à préciser.'));
            }
            if (! SubmissionPaths::exists($clean, $path)) {
                throw new InvalidArgumentException("Correction {$n} : membre « {$path} » introuvable dans la soumission.");
            }
            if (! array_key_exists($reason, DataCorrection::REASONS)) {
                throw new InvalidArgumentException("Correction {$n} : choisissez le motif de la correction.");
            }
            if ($reason === 'autre' && ! $comment) {
                throw new InvalidArgumentException("Correction {$n} : précisez le motif dans le commentaire.");
            }
            if ($newValue !== null) {
                $this->checkValue($definition, $dictionary, $newValue, $n);
            }
            $oldValue = CorrectionApplier::normalize(SubmissionPaths::get($clean, $variable, $path));
            // Ligne facultative (listes à cocher arrondissement / village) : ignorée si la valeur ne change pas.
            if (! empty($row['optional']) && ($oldValue === $newValue || $newValue === null)) {
                continue;
            }
            if ($oldValue === $newValue) {
                throw new InvalidArgumentException("Correction {$n} : la nouvelle valeur de « {$variable} » est identique à la valeur actuelle.");
            }
            $key = $variable.'@'.$path;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Correction {$n} : « {$variable} » est corrigée deux fois.");
            }
            $seen[$key] = true;

            $created->push(new DataCorrection([
                'submission_uuid' => $household->submission_uuid,
                'household_id' => $household->id,
                'data_query_id' => $query->id,
                'variable' => $variable,
                'instance_path' => $path,
                'source_value' => CorrectionApplier::normalize(SubmissionPaths::get($source, $variable, $path)),
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'reason' => $reason,
                'source_reference' => trim((string) ($row['source_reference'] ?? '')) ?: null,
                'comment' => $comment,
                'status' => DataCorrection::STATUS_PROPOSED,
                'proposed_by' => $user?->id,
                'proposed_at' => now(),
            ]));
        }
        // Arrondissement corrigé : la variable calculée qui filtre les villages (village_parent) le suit.
        $arrondissement = $created->first(fn (DataCorrection $c) => $c->variable === Map::var('arrondissement') && $c->instance_path === null);
        $parent = 'village_parent';
        if ($arrondissement && $dictionary->variable($parent) && ! isset($seen[$parent.'@'])
            && CorrectionApplier::normalize($clean->data[$parent] ?? null) === $arrondissement->old_value && $arrondissement->old_value !== null) {
            $created->push(new DataCorrection([
                'submission_uuid' => $household->submission_uuid, 'household_id' => $household->id, 'data_query_id' => $query->id,
                'variable' => $parent, 'instance_path' => null,
                'source_value' => CorrectionApplier::normalize($source->data[$parent] ?? null), 'old_value' => $arrondissement->old_value,
                'new_value' => $arrondissement->new_value, 'reason' => $arrondissement->reason, 'source_reference' => $arrondissement->source_reference,
                'comment' => 'Mise à jour automatique : suit l\'arrondissement corrigé.',
                'status' => DataCorrection::STATUS_PROPOSED, 'proposed_by' => $user?->id, 'proposed_at' => now(),
            ]));
        }
        if ($created->isEmpty()) {
            throw new InvalidArgumentException('Aucune valeur modifiée : saisissez ou cochez au moins une nouvelle valeur.');
        }

        // Une nouvelle réponse remplace les propositions encore en attente sur cette query.
        $pending = DataCorrection::where('data_query_id', $query->id)->where('status', DataCorrection::STATUS_PROPOSED)->get();
        $created->each->save();
        foreach ($pending as $old) {
            $old->update(['status' => DataCorrection::STATUS_SUPERSEDED, 'superseded_by' => $created->first()->id]);
        }

        return $created;
    }

    /** Type et liste de choix de la nouvelle valeur. */
    public function checkValue(array $definition, Dictionary $dictionary, string $value, int $n = 1): void
    {
        $type = $definition['base_type'];
        if (! TypeRule::valid($type, $value)) {
            throw new InvalidArgumentException("Correction {$n} : valeur « {$value} » incompatible avec le type « {$type} » de « {$definition['name']} ».");
        }
        if (in_array($type, ['select_one', 'select_multiple', 'select_one_from_file', 'select_multiple_from_file'], true) && $definition['list_name']) {
            $list = $dictionary->choices($definition['list_name']) ?? [];
            $choices = array_map('strval', array_keys($list));
            $values = str_starts_with($type, 'select_multiple') ? MultipleChoice::split($value, $list) : [$value];
            foreach ($values as $item) {
                if (! in_array($item, $choices, true)) {
                    throw new InvalidArgumentException("Correction {$n} : « {$item} » n'est pas un choix de « {$definition['name']} » (".implode(', ', $choices).').');
                }
            }
        }
    }

    /**
     * Décision du data manager sur les corrections proposées d'une query : validées (et appliquées) ou rejetées.
     * Une correction validée remplace la correction validée précédente du même champ.
     */
    public function decide(DataQuery $query, bool $accept, ?User $user, bool $signed = false, ?string $comment = null, bool $auto = false): int
    {
        $pending = DataCorrection::where('data_query_id', $query->id)->where('status', DataCorrection::STATUS_PROPOSED)->get();
        foreach ($pending as $correction) {
            if ($accept) {
                self::sameField($correction)->where('status', DataCorrection::STATUS_VALIDATED)
                    ->update(['status' => DataCorrection::STATUS_SUPERSEDED, 'superseded_by' => $correction->id]);
            }
            $correction->update([
                'status' => $accept ? DataCorrection::STATUS_VALIDATED : DataCorrection::STATUS_REJECTED,
                'decided_by' => $user?->id,
                'decided_at' => now(),
                'signed' => $signed,
                'decision_comment' => $comment,
                'auto_applied' => $accept && $auto,
            ]);
        }

        return $pending->count();
    }

    /** Autres corrections du même champ (même soumission, variable et membre). */
    private static function sameField(DataCorrection $correction)
    {
        return DataCorrection::where('submission_uuid', $correction->submission_uuid)->where('variable', $correction->variable)
            ->whereKeyNot($correction->id)
            ->where(fn ($q) => $correction->instance_path === null ? $q->whereNull('instance_path') : $q->where('instance_path', $correction->instance_path));
    }

    /**
     * Application immédiate d'une correction proposée : appliquée à la base nettoyée et vérifiée par le moteur,
     * elle est conservée si la query est levée et qu'aucune autre anomalie n'apparaît sur la soumission ;
     * sinon tout est annulé et la correction attend la décision du data manager. Revue a posteriori possible.
     *
     * @return array{applied: bool, reason: ?string}
     */
    public function tryAutoApply(DataQuery $query, ?User $user): array
    {
        $household = $query->household_id;
        if (! $household || DataLock::isFrozen()) {
            return ['applied' => false, 'reason' => 'base gelée ou soumission introuvable'];
        }
        $openBefore = DataQuery::where('household_id', $household)->whereNotIn('status', [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE])->pluck('id')->all();
        $lastId = (int) DataQuery::withoutGlobalScope('kind')->max('id');

        DB::beginTransaction();
        try {
            $this->decide($query, true, null, false, 'Appliquée immédiatement : anomalie levée, aucune nouvelle query.', true);
            $this->rebuild([$household]);
            $run = $this->engine->run('correction', $user?->id, [$household]);
            $query->refresh();
            $new = DataQuery::where('household_id', $household)->where('id', '>', $lastId)->pluck('label');
            $reopened = DataQuery::where('household_id', $household)->whereIn('status', DataQuery::UNRESOLVED)->whereNotIn('id', $openBefore)->count();

            $reason = match (true) {
                $run->status !== 'success' => 'vérification par le moteur impossible',
                $query->status !== DataQuery::STATUS_VALIDATED => 'l\'erreur est toujours détectée avec la nouvelle valeur',
                $new->isNotEmpty() => 'la nouvelle valeur crée une autre erreur ('.$new->take(3)->implode(' ; ').')',
                $reopened > 0 => 'la nouvelle valeur fait réapparaître une autre erreur',
                default => null,
            };
            if ($reason) {
                DB::rollBack();
                $query->refresh();

                return ['applied' => false, 'reason' => $reason];
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);
            $query->refresh();

            return ['applied' => false, 'reason' => 'erreur pendant la vérification'];
        }
        DashboardCache::bump();

        return ['applied' => true, 'reason' => null];
    }

    /**
     * Rechargement des données d'un ménage avec les corrections proposées (pas encore validées) : chaque query qui
     * attend encore une réponse est réévaluée. Si une correction proposée dans une autre query fait disparaître son
     * erreur, elle passe en « correction proposée » et suit cette correction : fermée à sa validation, rendue à
     * l'agent à son refus. Les données ne changent pas tant que la correction n'est pas validée.
     *
     * @return array{origins: int, resolved: list<array{query: DataQuery, origin: DataQuery}>}
     */
    public function reloadProposed(Household $household, User $user): array
    {
        if (DataLock::isFrozen()) {
            throw new InvalidArgumentException('Base gelée : aucun rechargement avant le dégel.');
        }
        $originIds = DataCorrection::where('household_id', $household->id)->where('status', DataCorrection::STATUS_PROPOSED)
            ->whereNotNull('data_query_id')->where('variable', '!=', DataCorrection::EXCLUSION)->orderBy('id')->pluck('data_query_id')->unique()->values();
        $origins = DataQuery::withoutGlobalScope('kind')->whereIn('id', $originIds)->get()->sortBy(fn ($q) => $originIds->search($q->id))->values();
        $candidates = DataQuery::where('household_id', $household->id)->whereIn('status', [DataQuery::STATUS_OPEN, DataQuery::STATUS_SENT])
            ->whereNotIn('id', $originIds)->get()->filter(fn (DataQuery $q) => QueryWorkflow::allowed($user, $q, DataQuery::STATUS_CORRECTED))->keyBy('id');

        // Chaque correction proposée est essayée seule (rien n'est conservé) : on note les queries dont l'erreur disparaît.
        $resolvedBy = [];
        foreach ($origins as $origin) {
            $remaining = $candidates->keys()->diff(array_keys($resolvedBy))->values()->all();
            if ($remaining === []) {
                break;
            }
            DB::beginTransaction();
            try {
                $this->decide($origin, true, null);
                $this->rebuild([$household->id]);
                $run = $this->engine->run('rechargement', $user->id, [$household->id]);
                $closed = $run->status === 'success'
                    ? DataQuery::whereIn('id', $remaining)->where('status', DataQuery::STATUS_VALIDATED)->pluck('id')->all() : [];
            } finally {
                DB::rollBack();
            }
            foreach ($closed as $id) {
                $resolvedBy[$id] = $origin;
            }
        }

        $resolved = [];
        DB::transaction(function () use ($resolvedBy, $candidates, $user, &$resolved) {
            foreach ($resolvedBy as $id => $origin) {
                $query = $candidates[$id];
                $from = $query->status;
                $query->update(['status' => DataQuery::STATUS_CORRECTED, 'resolved_by_query_id' => $origin->id, 'auto_closed' => false,
                    'responded_by' => $user->id, 'responded_at' => now(), 'supervised_by' => null, 'supervised_at' => null]);
                QueryEvent::create(['data_query_id' => $query->id, 'user_id' => $user->id, 'type' => 'status', 'from_status' => $from, 'to_status' => DataQuery::STATUS_CORRECTED,
                    'comment' => "Données rechargées : l'erreur disparaît avec la correction proposée dans {$origin->code}. La query suit cette correction : fermée à sa validation, renvoyée si elle est refusée."]);
                $resolved[] = ['query' => $query, 'origin' => $origin];
            }
        });
        DashboardCache::bump();

        return ['origins' => $origins->count(), 'resolved' => $resolved];
    }

    /** Correction d'une query refusée : les queries qui la suivaient reviennent à leur assigné. */
    public function releaseDependents(DataQuery $origin, ?User $user, string $why): void
    {
        foreach (DataQuery::withoutGlobalScope('kind')->where('resolved_by_query_id', $origin->id)->get() as $query) {
            $unresolved = in_array($query->status, [DataQuery::STATUS_CORRECTED, DataQuery::STATUS_SUPERVISED], true);
            $from = $query->status;
            $query->update(['resolved_by_query_id' => null] + ($unresolved ? [
                'status' => $query->assigned_to ? DataQuery::STATUS_SENT : DataQuery::STATUS_OPEN,
                'responded_by' => null, 'responded_at' => null, 'supervised_by' => null, 'supervised_at' => null,
            ] : []));
            if ($unresolved) {
                QueryEvent::create(['data_query_id' => $query->id, 'user_id' => $user?->id, 'type' => 'system', 'from_status' => $from, 'to_status' => $query->status,
                    'comment' => "{$why} ({$origin->code}) : cette query attend une nouvelle réponse."]);
            }
        }
    }

    /**
     * Refus a posteriori d'une correction validée (data manager) : la valeur précédente est rétablie (correction
     * remplacée remise en vigueur, sinon valeur ODK), le moteur repasse et la query rouvre si l'erreur revient.
     */
    public function revoke(DataCorrection $correction, User $user, string $reason): void
    {
        if (DataLock::isFrozen()) {
            throw new InvalidArgumentException('Base gelée : aucune modification avant le dégel.');
        }
        if ($correction->status !== DataCorrection::STATUS_VALIDATED) {
            throw new InvalidArgumentException('Seule une correction en vigueur peut être refusée.');
        }
        DB::transaction(function () use ($correction, $user, $reason) {
            $correction->update(['status' => DataCorrection::STATUS_REJECTED, 'decided_by' => $user->id, 'decided_at' => now(),
                'decision_comment' => trim('Refusée après application : '.$reason), 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            DataCorrection::where('superseded_by', $correction->id)->where('status', DataCorrection::STATUS_SUPERSEDED)->latest('id')->limit(1)
                ->update(['status' => DataCorrection::STATUS_VALIDATED, 'superseded_by' => null]);
            if ($correction->data_query_id) {
                QueryEvent::create(['data_query_id' => $correction->data_query_id, 'user_id' => $user->id, 'type' => 'correction',
                    'comment' => 'Correction C-'.str_pad((string) $correction->id, 6, '0', STR_PAD_LEFT).' refusée par le data manager : '.$reason]);
            }
        });
        if ($correction->household_id) {
            $this->rebuild([$correction->household_id]);
            $this->engine->run('correction', $user->id, [$correction->household_id]);
        }
        DashboardCache::bump();
    }

    /** Revue a posteriori : corrections appliquées immédiatement acceptées par le data manager. */
    public function markReviewed(iterable $corrections, User $user): int
    {
        $ids = collect($corrections)->filter(fn (DataCorrection $c) => $c->auto_applied && ! $c->reviewed_at && $c->status === DataCorrection::STATUS_VALIDATED)->pluck('id');
        DataCorrection::whereIn('id', $ids)->update(['reviewed_by' => $user->id, 'reviewed_at' => now()]);

        return $ids->count();
    }

    /** Ferme les queries ouvertes des soumissions exclues de la base nettoyée. */
    private function closeExcluded(array $householdIds, ?User $user): void
    {
        $open = DataQuery::whereIn('household_id', $householdIds)->whereNotIn('status', [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE])->get();
        DB::transaction(function () use ($open, $user) {
            foreach ($open as $query) {
                $from = $query->status;
                $query->update(['status' => DataQuery::STATUS_VALIDATED, 'validated_by' => $user?->id, 'resolved_at' => now(), 'closed_at' => now(), 'auto_closed' => false]);
                QueryEvent::create(['data_query_id' => $query->id, 'user_id' => $user?->id, 'type' => 'system', 'from_status' => $from, 'to_status' => DataQuery::STATUS_VALIDATED,
                    'comment' => 'Soumission exclue de la base nettoyée (correction validée) : query fermée. La soumission reste dans la base brute.']);
            }
        });
    }

    /** Reconstruit les ménages : source + corrections validées → tables de travail (base nettoyée). */
    public function rebuild(array $householdIds): void
    {
        if (DataLock::isFrozen()) {
            throw new InvalidArgumentException('Base gelée : reconstruction impossible avant le dégel.');
        }
        $processor = new SubmissionProcessor;
        foreach (Household::withoutGlobalScope('included')->whereIn('id', $householdIds)->get() as $household) {
            $processor->process(SourceSnapshot::of($household), [
                'data_source_id' => $household->data_source_id,
                'import_batch_id' => $household->import_batch_id,
                'source_type' => $household->source_type,
            ]);
        }
        DashboardCache::bump();
    }

    /**
     * Après validation des corrections : reconstruit les ménages, relance les règles sur la base nettoyée et
     * conclut chaque query — résolue si l'anomalie a disparu, sinon renvoyée à l'assigné.
     *
     * @param  iterable<DataQuery>  $queries
     */
    public function applyAndVerify(iterable $queries, ?User $user): void
    {
        $queries = collect($queries);
        if ($queries->isEmpty()) {
            return;
        }
        $households = $queries->pluck('household_id')->filter()->unique()->values()->all();
        $this->rebuild($households);

        // Soumissions exclues : leurs queries sont fermées (motif tracé) ; le moteur repasse sur tout
        // (les doublons disparaissent aussi chez la soumission conservée).
        $excluded = Household::withoutGlobalScope('included')->whereIn('id', $households)->whereNotNull('excluded_at')->pluck('id')->all();
        if ($excluded) {
            $this->closeExcluded($excluded, $user);
        }
        $run = $this->engine->run('correction', $user?->id, $excluded ? null : $households);
        if ($run->status !== 'success') {
            // Vérification impossible : les queries restent « correction proposée », corrections déjà appliquées.
            foreach ($queries as $query) {
                QueryEvent::create(['data_query_id' => $query->id, 'user_id' => $user?->id, 'type' => 'system',
                    'comment' => 'Corrections appliquées, mais la vérification par le moteur a échoué : relancez le moteur de queries.']);
            }

            return;
        }

        foreach ($queries as $query) {
            $query->refresh();
            if ($query->status === DataQuery::STATUS_VALIDATED) {
                // Anomalie absente de la base nettoyée : fermeture constatée par le moteur, validée par le data manager.
                $query->update(['validated_by' => $user?->id]);

                continue;
            }
            DB::transaction(function () use ($query, $user) {
                $from = $query->status;
                $query->update([
                    'status' => $query->assigned_to ? DataQuery::STATUS_SENT : DataQuery::STATUS_OPEN,
                    'responded_by' => null, 'responded_at' => null, 'validated_by' => null, 'resolved_at' => null, 'closed_at' => null,
                ]);
                QueryEvent::create([
                    'data_query_id' => $query->id, 'user_id' => $user?->id, 'type' => 'system', 'from_status' => $from, 'to_status' => $query->status,
                    'comment' => 'Correction appliquée à la base nettoyée, mais l\'anomalie est toujours détectée : query renvoyée pour une nouvelle réponse.',
                ]);
            });
        }
        // Queries qui suivaient ces corrections : fermées par le moteur si l'erreur a disparu, sinon rendues à l'agent.
        foreach ($queries as $origin) {
            foreach (DataQuery::withoutGlobalScope('kind')->where('resolved_by_query_id', $origin->id)->get() as $dependent) {
                if ($dependent->status === DataQuery::STATUS_VALIDATED) {
                    $dependent->update(['validated_by' => $user?->id]);
                    QueryEvent::create(['data_query_id' => $dependent->id, 'user_id' => $user?->id, 'type' => 'system',
                        'comment' => "Correction de {$origin->code} validée : l'erreur de cette query est levée."]);
                }
            }
            $this->releaseDependents($origin, $user, 'Correction validée, mais l\'erreur de cette query est toujours détectée');
        }
        DashboardCache::bump();
    }
}
