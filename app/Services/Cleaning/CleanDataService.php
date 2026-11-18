<?php

namespace App\Services\Cleaning;

use App\Models\DataCorrection;
use App\Models\DataLock;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QueryEvent;
use App\Models\User;
use App\Services\Dictionary\Dictionary;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Services\Quality\Rules\Dictionary\TypeRule;
use App\Support\DashboardCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        if ($created->isEmpty()) {
            throw new InvalidArgumentException('Saisissez au moins une correction (variable, nouvelle valeur, motif).');
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
    private function checkValue(array $definition, Dictionary $dictionary, string $value, int $n): void
    {
        $type = $definition['base_type'];
        if (! TypeRule::valid($type, $value)) {
            throw new InvalidArgumentException("Correction {$n} : valeur « {$value} » incompatible avec le type « {$type} » de « {$definition['name']} ».");
        }
        if (in_array($type, ['select_one', 'select_multiple'], true) && $definition['list_name']) {
            $choices = array_map('strval', array_keys($dictionary->choices($definition['list_name']) ?? []));
            $values = $type === 'select_multiple' ? preg_split('/\s+/', $value) : [$value];
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
    public function decide(DataQuery $query, bool $accept, ?User $user, bool $signed = false, ?string $comment = null): int
    {
        $pending = DataCorrection::where('data_query_id', $query->id)->where('status', DataCorrection::STATUS_PROPOSED)->get();
        foreach ($pending as $correction) {
            if ($accept) {
                DataCorrection::where('submission_uuid', $correction->submission_uuid)->where('variable', $correction->variable)
                    ->where('instance_path', $correction->instance_path)->where('status', DataCorrection::STATUS_VALIDATED)
                    ->update(['status' => DataCorrection::STATUS_SUPERSEDED, 'superseded_by' => $correction->id]);
            }
            $correction->update([
                'status' => $accept ? DataCorrection::STATUS_VALIDATED : DataCorrection::STATUS_REJECTED,
                'decided_by' => $user?->id,
                'decided_at' => now(),
                'signed' => $signed,
                'decision_comment' => $comment,
            ]);
        }

        return $pending->count();
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
        DashboardCache::bump();
    }
}
