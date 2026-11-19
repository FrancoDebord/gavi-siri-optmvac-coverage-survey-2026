<?php

namespace App\Services\Quality;

use App\Models\DataQuery;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use InvalidArgumentException;

/**
 * Queries qui comparent deux valeurs (VPO 3 avant VPO 2, vaccin avant la naissance…) : l'erreur peut venir de
 * l'une ou de l'autre. Retrouve, pour le même membre, les autres questions en cause, que l'agent pourra corriger
 * à la place (ou en plus) de la question de la query.
 */
final class RelatedFields
{
    /**
     * @return list<array{variable: string, label: string}> questions saisissables en cause, hors celle de la query
     */
    public static function for(DataQuery $query, NormalizedSubmission $clean): array
    {
        if (! $query->variable) {
            return [];
        }
        $variables = match ($query->rule_code) {
            'VAC_DOSE_ORDER', 'VAC_DOSE_INTERVAL' => [self::previousDose($query, $clean)],
            'VAC_BEFORE_DOB', 'VAC_MIN_AGE' => [self::dobVariable($query, $clean)],
            'ELIG_AGE_DOB' => [Map::var('age_annees'), self::dobVariable($query, $clean)],
            default => [],
        };
        $dictionary = Dictionary::current();
        $fields = [];
        foreach (array_unique(array_filter($variables)) as $variable) {
            $definition = $dictionary->variable($variable);
            if ($variable === $query->variable || ! $definition || ! $dictionary->isInput($definition)) {
                continue;
            }
            $fields[] = ['variable' => $variable, 'label' => self::label($variable)];
        }

        return $fields;
    }

    /** Libellé court d'une variable connue du mapping (ex. « VPO 2 », « Date de naissance »), sinon son nom. */
    public static function label(string $variable): string
    {
        foreach (array_keys(config('gavi.vaccines')) as $code) {
            if (Map::vaccineDate($code) === $variable) {
                return config("gavi.vaccines.{$code}.label", $code);
            }
        }
        foreach (Map::defaults() as $key => $row) {
            if (Map::var($key) === $variable) {
                return $row['label'];
            }
        }

        return $variable;
    }

    /** Dose précédente renseignée de la même série (ex. VPO 2 pour une query sur VPO 3). */
    private static function previousDose(DataQuery $query, NormalizedSubmission $clean): ?string
    {
        $code = collect(array_keys(config('gavi.vaccines')))->first(fn ($c) => Map::vaccineDate($c) === $query->variable);
        if (! $code) {
            return null;
        }
        foreach (config('gavi.dose_series') as $series) {
            $position = array_search($code, $series, true);
            if ($position === false) {
                continue;
            }
            // La dose précédente la plus proche qui a une date (une dose manquante est ignorée, comme dans la règle).
            for ($i = $position - 1; $i >= 0; $i--) {
                $variable = Map::vaccineDate($series[$i]);
                if ($variable && SubmissionProcessor::date(self::value($clean, $variable, $query->instance_path))) {
                    return $variable;
                }
            }
        }

        return null;
    }

    /** Date de naissance utilisée par les règles : documentée, sinon déclarée. */
    private static function dobVariable(DataQuery $query, NormalizedSubmission $clean): ?string
    {
        $documented = Map::var('date_naissance_enfant');
        if ($documented && SubmissionProcessor::date(self::value($clean, $documented, $query->instance_path))) {
            return $documented;
        }
        $declared = Map::var('date_naissance_declaree');

        return $declared && SubmissionProcessor::date(self::value($clean, $declared, $query->instance_path)) ? $declared : $documented;
    }

    private static function value(NormalizedSubmission $clean, string $variable, ?string $path): mixed
    {
        try {
            return SubmissionPaths::get($clean, $variable, $path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
