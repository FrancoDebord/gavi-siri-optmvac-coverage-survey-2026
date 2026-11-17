<?php

namespace App\Services\Cleaning;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use InvalidArgumentException;

/**
 * Lecture et écriture d'une valeur d'une soumission normalisée à partir de sa variable et du chemin de son membre
 * (ex. « cg[1]/smc_cov_hh_cg_child_2023[2] »), avec la même numérotation que le moteur de queries (SubmissionContext) :
 * caregivers et enfants comptés dans l'ordre de NormalizedSubmission::findRepeat, autres repeats par imbrication directe.
 */
final class SubmissionPaths
{
    /** @return list<array{0: string, 1: int}> segments [repeat, position 1..n] */
    public static function parse(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [];
        }
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (! preg_match('/^([A-Za-z_][\w.-]*)\[(\d+)\]$/', $segment, $m) || (int) $m[2] < 1) {
                throw new InvalidArgumentException("Chemin de membre invalide : « {$path} ».");
            }
            $segments[] = [$m[1], (int) $m[2]];
        }

        return $segments;
    }

    /** Valeur actuelle (null si absente) ; exception si le membre n'existe pas. */
    public static function get(NormalizedSubmission $submission, string $variable, ?string $path): mixed
    {
        $keys = self::address($submission->repeats, self::parse($path), $path);
        if ($keys === []) {
            return $submission->data[$variable] ?? null;
        }
        $node = $submission->repeats;
        foreach ($keys as $key) {
            $node = $node[$key];
        }

        return $node['data'][$variable] ?? null;
    }

    /** Écrit la valeur (null = efface la réponse). */
    public static function set(NormalizedSubmission $submission, string $variable, ?string $path, mixed $value): void
    {
        $keys = self::address($submission->repeats, self::parse($path), $path);
        if ($keys === []) {
            self::assign($submission->data, $variable, $value);

            return;
        }
        $node = &$submission->repeats;
        foreach ($keys as $key) {
            $node = &$node[$key];
        }
        self::assign($node['data'], $variable, $value);
        unset($node);
    }

    public static function exists(NormalizedSubmission $submission, ?string $path): bool
    {
        try {
            self::address($submission->repeats, self::parse($path), $path);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function assign(array &$data, string $variable, mixed $value): void
    {
        if ($value === null) {
            unset($data[$variable]);
        } else {
            $data[$variable] = $value;
        }
    }

    /**
     * Clés successives (repeat, index, 'repeats', repeat, index…) menant à l'instance désignée par le chemin.
     *
     * @param  list<array{0: string, 1: int}>  $segments
     * @return list<string|int>
     */
    private static function address(array $repeats, array $segments, ?string $path): array
    {
        $keys = [];
        $container = $repeats;
        $searched = array_filter([Map::var('repeat_caregivers'), Map::var('repeat_enfants')]);
        foreach ($segments as $i => [$name, $position]) {
            $found = in_array($name, $searched, true)
                ? self::nth($container, $name, $position)
                : (isset($container[$name][$position - 1]) ? [$name, $position - 1] : null);
            if ($found === null) {
                throw new InvalidArgumentException("Membre introuvable dans la soumission : « {$path} ».");
            }
            if ($i > 0) {
                $keys[] = 'repeats';
            }
            array_push($keys, ...$found);
            $node = $container;
            foreach ($found as $key) {
                $node = $node[$key];
            }
            $container = $node['repeats'] ?? [];
        }

        return $keys;
    }

    /**
     * Adresse de la n-ième instance d'un repeat, dans l'ordre de NormalizedSubmission::findRepeat
     * (repeat présent à ce niveau, sinon recherche en profondeur).
     *
     * @return list<string|int>|null
     */
    private static function nth(array $repeats, string $name, int $n): ?array
    {
        if (isset($repeats[$name])) {
            return isset($repeats[$name][$n - 1]) ? [$name, $n - 1] : null;
        }
        foreach ($repeats as $repeat => $instances) {
            foreach ($instances as $index => $instance) {
                $inside = count(NormalizedSubmission::findRepeat($instance['repeats'] ?? [], $name));
                if ($n <= $inside) {
                    $deeper = self::nth($instance['repeats'] ?? [], $name, $n);

                    return $deeper === null ? null : [$repeat, $index, 'repeats', ...$deeper];
                }
                $n -= $inside;
            }
        }

        return null;
    }
}
