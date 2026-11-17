<?php

namespace App\Services\Cleaning;

use App\Models\Household;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;

/**
 * Soumission source (telle que reçue, sans correction) d'un ménage : households.source_data, ou, pour un ménage
 * importé avant la base nettoyée (aucune correction n'existait alors), reconstitution depuis les tables.
 */
final class SourceSnapshot
{
    public static function serialize(NormalizedSubmission $s): array
    {
        return [
            'data' => $s->data,
            'repeats' => $s->repeats,
            'submitted_at' => $s->submittedAt,
            'form_version' => $s->formVersion,
            'review_state' => $s->reviewState,
            'submitter' => $s->submitter,
        ];
    }

    public static function of(Household $household): NormalizedSubmission
    {
        $source = $household->source_data;
        if (is_array($source) && array_key_exists('data', $source)) {
            return new NormalizedSubmission(
                $household->submission_uuid, $source['data'] ?? [], $source['repeats'] ?? [], $household->raw_data ?? [],
                $source['submitted_at'] ?? null, $source['form_version'] ?? null, $source['review_state'] ?? null, $source['submitter'] ?? null,
            );
        }

        return self::fromTables($household);
    }

    private static function fromTables(Household $household): NormalizedSubmission
    {
        $household->loadMissing(['caregivers', 'children']);
        $data = $household->data ?? [];
        $repeats = $data['__repeats'] ?? [];
        unset($data['__repeats']);

        $cgRepeat = Map::var('repeat_caregivers');
        $childRepeat = Map::var('repeat_enfants');
        $child = fn ($c) => ['key' => $c->repeat_key, 'data' => $c->data ?? [], 'repeats' => []];
        if ($cgRepeat && $household->caregivers->isNotEmpty()) {
            $repeats[$cgRepeat] = $household->caregivers->sortBy('position')->map(fn ($cg) => [
                'key' => $cg->repeat_key,
                'data' => $cg->data ?? [],
                'repeats' => $childRepeat ? [$childRepeat => $household->children->where('caregiver_id', $cg->id)->sortBy('position')->map($child)->values()->all()] : [],
            ])->values()->all();
        } elseif ($childRepeat && $household->children->isNotEmpty()) {
            $repeats[$childRepeat] = $household->children->sortBy('position')->map($child)->values()->all();
        }

        return new NormalizedSubmission(
            $household->submission_uuid, $data, $repeats, $household->raw_data ?? [],
            $household->submitted_at?->toIso8601String(), $household->form_version, $household->review_state,
        );
    }
}
