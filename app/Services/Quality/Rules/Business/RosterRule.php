<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/** Règles sur le roster des membres du ménage (repeat smc_cov_roster_2023). */
abstract class RosterRule extends BusinessRule
{
    /** @return list<Scope> membres saisis dans le roster */
    protected static function members(SubmissionContext $context): array
    {
        $repeat = Map::var('repeat_membres');

        return $repeat ? array_values(array_filter($context->scopesForRepeat($repeat), fn (Scope $s) => $s->parent === $context->root)) : [];
    }

    /** Membres dont la valeur de $variable vérifie $test (valeurs vides ignorées). */
    protected static function countWhere(array $members, string $variable, callable $test): int
    {
        return count(array_filter($members, fn (Scope $m) => is_numeric($m->own($variable)) && $test((int) $m->own($variable))));
    }
}
