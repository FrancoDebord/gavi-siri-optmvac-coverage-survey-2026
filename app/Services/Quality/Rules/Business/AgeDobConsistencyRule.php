<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Âge déclaré (années + mois) incohérent avec la date de naissance. */
class AgeDobConsistencyRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $interview = $context->interviewDate();
        if (! $interview) {
            return;
        }
        $tolerance = $this->params->int('tolerance_months', 2);

        foreach ($context->scopes('child') as $child) {
            $dob = $context->childDob($child);
            $years = $child->own(Map::var('age_annees') ?? '');
            $months = $child->own(Map::var('age_mois') ?? '');
            if (! $dob || (! is_numeric($years) && ! is_numeric($months))) {
                continue;
            }
            $declared = (int) $years * 12 + (int) $months;
            $computed = (int) floor($dob->diffInMonths($interview, false));
            if (abs($declared - $computed) <= $tolerance) {
                continue;
            }

            yield QueryCandidate::for(
                $child,
                sprintf('%s : âge déclaré %d an(s) %d mois (%d mois) mais %d mois d\'après la date de naissance (%s)', $child->label(), (int) $years, (int) $months, $declared, $computed, self::fmt($dob)),
                Map::var('age_mois'),
                "{$declared} mois",
                "{$computed} mois (± {$tolerance})",
            );
        }
    }
}
