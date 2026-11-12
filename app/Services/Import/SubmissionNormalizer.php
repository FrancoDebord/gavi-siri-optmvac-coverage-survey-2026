<?php

namespace App\Services\Import;

/**
 * Convertit les soumissions JSON d'ODK Central (OData, groupes imbriqués, GeoJSON)
 * et de KoboToolbox (clés « groupe/sous-groupe/variable ») vers NormalizedSubmission.
 */
class SubmissionNormalizer
{
    /** Soumission OData ODK Central (avec $expand=*). */
    public function fromOdk(array $submission): NormalizedSubmission
    {
        $data = [];
        $repeats = [];
        $this->walk($submission, $data, $repeats);

        $system = $submission['__system'] ?? [];
        $uuid = (string) ($submission['__id'] ?? $submission['meta']['instanceID'] ?? $data['instanceID'] ?? '');

        return new NormalizedSubmission(
            uuid: $uuid,
            data: $data,
            repeats: $repeats,
            raw: $submission,
            submittedAt: $system['submissionDate'] ?? null,
            formVersion: $system['formVersion'] ?? null,
            reviewState: $system['reviewState'] ?? null,
            submitter: $system['submitterName'] ?? null,
        );
    }

    /** Soumission de l'API v2 KoboToolbox (data.json). */
    public function fromKobo(array $submission): NormalizedSubmission
    {
        $data = [];
        $repeats = [];
        $this->walk($submission, $data, $repeats);

        $uuid = $submission['meta/instanceID'] ?? (isset($submission['_uuid']) ? 'uuid:'.$submission['_uuid'] : '');
        $validation = $submission['_validation_status']['uid'] ?? null;

        return new NormalizedSubmission(
            uuid: (string) $uuid,
            data: $data,
            repeats: $repeats,
            raw: $submission,
            submittedAt: $submission['_submission_time'] ?? null,
            formVersion: $submission['__version__'] ?? null,
            reviewState: $validation,
            submitter: $submission['_submitted_by'] ?? null,
        );
    }

    /**
     * Parcourt un nœud : les objets sont des groupes (aplatis), les listes d'objets des repeats.
     */
    public function walk(array $node, array &$data, array &$repeats): void
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if (str_contains($key, '@odata')) {
                continue;
            }
            $name = self::leaf($key);
            if ($name === '' || str_starts_with($name, '_') || in_array($key, ['meta/instanceID', 'formhub/uuid'], true)) {
                continue; // métadonnées (__id, __system, _uuid, _attachments…)
            }

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                if (array_is_list($value) && is_array($value[0])) {
                    $repeats[$name] = array_map(fn (array $item) => $this->instance($item), $value);

                    continue;
                }
                if (isset($value['type'], $value['coordinates']) && $value['type'] === 'Point') {
                    $data[$name] = self::geoJsonToString($value);

                    continue;
                }
                if (array_is_list($value)) {
                    $data[$name] = implode(' ', array_map('strval', $value));

                    continue;
                }
                $this->walk($value, $data, $repeats);

                continue;
            }

            $data[$name] = self::scalar($value);
        }
    }

    /** @return array{key: string|null, data: array, repeats: array} */
    private function instance(array $item): array
    {
        $data = [];
        $repeats = [];
        $this->walk($item, $data, $repeats);

        return ['key' => isset($item['__id']) ? (string) $item['__id'] : null, 'data' => $data, 'repeats' => $repeats];
    }

    /** Dernier segment d'un chemin « a/b/c » (Kobo) ou « a-b-c » non concerné ici. */
    public static function leaf(string $key): string
    {
        $pos = strrpos($key, '/');

        return trim($pos === false ? $key : substr($key, $pos + 1));
    }

    public static function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value;
    }

    /** GeoJSON ODK → « lat lon alt précision » (format ODK/Kobo texte). */
    public static function geoJsonToString(array $point): string
    {
        [$lon, $lat] = $point['coordinates'];
        $alt = $point['coordinates'][2] ?? 0;
        $accuracy = $point['properties']['accuracy'] ?? 0;

        return trim("{$lat} {$lon} {$alt} {$accuracy}");
    }
}
