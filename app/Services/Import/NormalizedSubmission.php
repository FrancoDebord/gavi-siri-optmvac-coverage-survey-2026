<?php

namespace App\Services\Import;

/**
 * Format interne commun à ODK Central, KoboToolbox et aux imports CSV/XLSX.
 *
 * - $data : variables hors repeats, à plat (nom XLSForm => valeur)
 * - $repeats : nom du repeat => liste d'instances ['key' => ?string, 'data' => [...], 'repeats' => [...]]
 */
final class NormalizedSubmission
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<array{key: string|null, data: array, repeats: array}>>  $repeats
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public array $data,
        public array $repeats,
        public array $raw,
        public ?string $submittedAt = null,
        public ?string $formVersion = null,
        public ?string $reviewState = null,
        public ?string $submitter = null,
    ) {}

    /**
     * Instances d'un repeat, recherchées à n'importe quelle profondeur
     * (les enfants sont imbriqués dans le repeat des caregivers).
     *
     * @return list<array{key: string|null, data: array, repeats: array}>
     */
    public static function findRepeat(array $repeats, string $name): array
    {
        if (isset($repeats[$name])) {
            return $repeats[$name];
        }
        $found = [];
        foreach ($repeats as $instances) {
            foreach ($instances as $instance) {
                array_push($found, ...self::findRepeat($instance['repeats'] ?? [], $name));
            }
        }

        return $found;
    }

    /**
     * Empreinte du contenu, indépendante de l'ordre des champs : MySQL réordonne les clés des colonnes JSON, une
     * soumission relue depuis la base doit garder l'empreinte de celle reçue d'ODK (sinon chaque synchronisation
     * la croit modifiée). L'ordre des instances de repeat (listes) est conservé.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([self::canonical($this->data), self::canonical($this->repeats), $this->reviewState]));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
