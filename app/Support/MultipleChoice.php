<?php

namespace App\Support;

/**
 * Réponses d'une question à choix multiple (select_multiple) : codes séparés par des espaces, « rr-bj opv-bj ».
 * Certaines listes ont des codes avec des espaces autour du tiret (« opv - bj » dans campaigns.csv) : ODK les
 * transmet collés (« opv-bj »). Les deux écritures désignent le même choix.
 */
final class MultipleChoice
{
    /** Code sans espaces autour des tirets : « opv - bj » → « opv-bj » (pas « 6.5 -2.3 », coordonnées GPS). */
    public static function compact(string $code): string
    {
        return preg_replace('/\s+-\s+/', '-', trim($code));
    }

    /**
     * Codes choisis, dans l'ordre. Avec la liste des choix : chaque code est rendu sous sa forme de la liste
     * (« opv-bj » → « opv - bj ») ; un code inconnu est gardé tel quel.
     *
     * @param  array<string|int, mixed>|null  $choices  code => libellé
     * @return list<string>
     */
    public static function split(mixed $value, ?array $choices = null): array
    {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', $value));
        }
        $codes = array_values(array_filter(preg_split('/\s+/', self::compact((string) $value)), fn ($c) => $c !== ''));
        if ($choices === null) {
            return $codes;
        }
        $known = [];
        foreach (array_keys($choices) as $key) {
            $known[self::compact((string) $key)] = (string) $key;
        }

        return array_map(fn ($code) => $known[$code] ?? $code, $codes);
    }

    /** Valeur à enregistrer pour des codes cochés : codes collés, séparés par une espace (format ODK). */
    public static function join(array $codes): string
    {
        return implode(' ', array_values(array_unique(array_filter(array_map(fn ($c) => self::compact((string) $c), $codes), fn ($c) => $c !== ''))));
    }
}
