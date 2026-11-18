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
        if ($household && $query->variable) {
            try {
                $source = SourceSnapshot::of($household);
                $raw = CorrectionApplier::normalize(SubmissionPaths::get($source, $query->variable, $query->instance_path));
                $current = CorrectionApplier::normalize(SubmissionPaths::get((new CorrectionApplier)->apply($source)[0], $query->variable, $query->instance_path));
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

        $definition = $query->variable ? $dictionary->variable($query->variable) : null;
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
            'raw' => $label($raw),
            'current' => $current !== $raw ? $label($current) : null,
            'suggested' => $suggested !== null ? ($suggested === '' ? '(vide)' : $label($suggested)) : null,
            'has_suggestion' => $suggested !== null,
            'suggested_value' => $suggested,
            'suggested_source' => $source,
            'suggested_note' => $query->suggested_note,
            'is_ai' => $query->suggested_source === 'ia',
            'problem' => $query->message,
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
