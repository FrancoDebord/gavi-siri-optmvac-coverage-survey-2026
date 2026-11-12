<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Enfant enrôlé (section vaccination remplie) dont l'âge recalculé à la date
 * d'interview depuis la date de naissance est hors de la tranche ciblée.
 */
class ChildAgeEligibilityRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $interview = $context->interviewDate();
        if (! $interview) {
            return;
        }
        [$low, $high] = $context->ageRange($this->params);

        foreach ($context->scopes('child') as $child) {
            $dob = $context->childDob($child);
            if (! $dob || ! self::enrolled($child)) {
                continue;
            }
            $months = (int) floor($dob->diffInMonths($interview, false));
            if ($months >= $low && $months <= $high) {
                continue;
            }

            yield QueryCandidate::for(
                $child,
                sprintf('%s : âge de %d mois au %s (né le %s), hors de la tranche %d–%d mois mais enrôlé', $child->label(), $months, self::fmt($interview), self::fmt($dob), $low, $high),
                Map::var('date_naissance_enfant'),
                self::fmt($dob),
                "{$low} à {$high} mois",
            );
        }
    }
}
