<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;

/**
 * Conformité d'un roster au nombre déclaré auparavant : le nombre d'instances saisies dans le repeat doit être égal au
 * nombre déclaré (repeat_count du XLSForm). Un écart vient d'une correction du nombre, d'une modification de la
 * soumission dans ODK Central ou d'une ancienne version du formulaire. La query porte sur le nombre déclaré.
 */
abstract class RosterCountRule extends BusinessRule
{
    /** Écart entre le nombre déclaré (valeur de $variable dans $scope) et le nombre d'instances saisies, sinon null. */
    protected function compare(Scope $scope, ?string $variable, int $entered, string $what, string $roster): ?QueryCandidate
    {
        if (! $variable) {
            return null;
        }
        $declared = $scope->own($variable);
        if (! is_numeric($declared) || (int) $declared === $entered) {
            return null;
        }
        $declared = (int) $declared;

        return QueryCandidate::for(
            $scope,
            sprintf('%s : %d %s déclaré(s) mais %d saisi(s) dans le %s', $scope->label(), $declared, $what, $entered, $roster),
            $variable,
            (string) $declared,
            "{$entered} (nombre saisi dans le {$roster})",
        );
    }

    protected static function variable(string $key): ?string
    {
        return Map::var($key);
    }
}
