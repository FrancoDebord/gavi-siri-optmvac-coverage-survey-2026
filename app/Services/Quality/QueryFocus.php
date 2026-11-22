<?php

namespace App\Services\Quality;

use App\Exports\QueriesExport;
use App\Models\Caregiver;
use App\Models\DataQuery;
use App\Models\PhotoCheck;
use App\Services\Cleaning\CorrectionApplier;
use App\Services\Cleaning\SourceSnapshot;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Dictionary\Dictionary;
use Throwable;

/**
 * Ce qu'il faut corriger, en clair pour les agents : la question, le membre concerné et son identifiant (URI ODK),
 * la valeur saisie (données brutes ODK), la valeur actuelle de la base nettoyée si elle diffère, et la valeur
 * suggérée d'après la photo du document (vérifications photo_checks).
 */
final class QueryFocus
{
    /**
     * @return array{question: ?string, variable: ?string, member: ?string, uri: ?string, submission: ?string,
     *     raw: ?string, current: ?string, suggested: ?string, has_suggestion: bool, problem: string}
     */
    public static function for(DataQuery $query, ?Dictionary $dictionary = null): array
    {
        $dictionary ??= Dictionary::current();
        $household = $query->household;
        $raw = $current = null;
        $related = [];
        $definition = $query->variable ? $dictionary->variable($query->variable) : null;
        // Question d'un membre (ex. nombre d'enfants de chaque caregiver) contrôlée sur tout le ménage : pas de valeur
        // unique à lire, la règle donne le détail des valeurs lues.
        $wholeHousehold = $definition && ($definition['repeat_name'] ?? null) && ! $query->instance_path;
        if ($wholeHousehold) {
            $raw = $query->observed_value;
        } elseif ($household && $query->variable) {
            try {
                $source = SourceSnapshot::of($household);
                $clean = (new CorrectionApplier)->apply($source)[0];
                $raw = CorrectionApplier::normalize(SubmissionPaths::get($source, $query->variable, $query->instance_path));
                $current = CorrectionApplier::normalize(SubmissionPaths::get($clean, $query->variable, $query->instance_path));
                // Autres valeurs comparées par la règle (ex. VPO 2 pour « VPO 3 avant VPO 2 ») : l'erreur peut venir d'elles.
                foreach (RelatedFields::for($query, $clean) as $field) {
                    $related[] = $field + ['value' => CorrectionApplier::normalize(SubmissionPaths::get($clean, $field['variable'], $query->instance_path))];
                }
            } catch (Throwable) {
                $raw = $current = CorrectionApplier::normalize($query->observed_value);
            }
        }

        // Valeur lue sur la photo : vérification enregistrée pour ce champ ('' = doit être vide).
        $check = $query->submission_uuid && $query->variable
            ? PhotoCheck::where('submission_uuid', $query->submission_uuid)->where('variable', $query->variable)
                ->where(fn ($q) => $query->instance_path ? $q->where('instance_path', $query->instance_path) : $q->whereNull('instance_path')->orWhere('instance_path', ''))
                ->whereNotNull('expected')->latest('id')->first()
            : null;

        $label = fn (?string $value) => self::label($dictionary, $definition, $value);
        $suggested = $query->suggested_source !== null ? (string) $query->suggested_value : $check?->expected;
        $source = $query->suggested_source !== null
            ? (DataQuery::SUGGESTION_SOURCES[$query->suggested_source] ?? $query->suggested_source).($query->suggester ? ' par '.$query->suggester->name : '')
            : ($check ? 'lue sur la photo' : null);

        return [
            'question' => $query->variable_label,
            'variable' => $query->variable,
            'member' => $query->instance_path ? QueriesExport::memberLabel($query->instance_path) : null,
            'uri' => self::uri($query),
            'submission' => $query->submission_uuid,
            'raw' => $wholeHousehold ? $raw : $label($raw),
            'whole_household' => $wholeHousehold,
            'submitter' => $household?->submitter,
            'current' => $current !== $raw ? $label($current) : null,
            'suggested' => $suggested !== null ? ($suggested === '' ? '(vide)' : $label($suggested)) : null,
            'has_suggestion' => $suggested !== null,
            'suggested_value' => $suggested,
            'suggested_source' => $source,
            'suggested_note' => $query->suggested_note,
            'is_ai' => $query->suggested_source === 'ia',
            'is_rule' => $query->suggested_source === DataQuery::SUGGESTION_RULE,
            'problem' => $query->message,
            'short_label' => $query->variable ? RelatedFields::label($query->variable) : null,
            'related' => $related,
        ];
    }

    /** Identifiant ODK de l'instance concernée (enfant ou caregiver), sinon de la soumission. */
    private static function uri(DataQuery $query): ?string
    {
        if ($query->child_id) {
            return $query->child?->repeat_key ?: null;
        }
        if ($query->caregiver_id) {
            return Caregiver::whereKey($query->caregiver_id)->value('repeat_key');
        }

        return null;
    }

    /** Valeur avec le libellé du choix (ex. « 2 – Visite 2 »). */
    private static function label(Dictionary $dictionary, ?array $definition, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($definition && $definition['list_name'] && in_array($definition['base_type'], ['select_one', 'select_multiple'], true)) {
            $labels = array_map(fn ($code) => $code.' – '.trim(strip_tags((string) ($dictionary->choiceLabel($definition['list_name'], $code) ?? '?'))), preg_split('/\s+/', $value));

            return implode(' ; ', $labels);
        }

        return $value;
    }
}
