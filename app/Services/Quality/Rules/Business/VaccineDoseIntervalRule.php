<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Intervalle trop court entre deux doses successives (l'ordre inversé relève de VAC_DOSE_ORDER). */
class VaccineDoseIntervalRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $minimum = $this->params->int('min_interval_days', 28);

        foreach ($context->scopes('child') as $child) {
            $dates = $context->vaccineDates($child);
            foreach (config('gavi.dose_series') as $series) {
                foreach (VaccineDoseOrderRule::consecutivePairs($series, $dates) as [$previous, $next]) {
                    $days = (int) $dates[$previous]->diffInDays($dates[$next], false);
                    if ($days >= 0 && $days < $minimum) {
                        yield QueryCandidate::for(
                            $child,
                            sprintf('%s : %d jour(s) entre %s (%s) et %s (%s), minimum %d', $child->label(), $days, self::vaccineLabel($previous), self::fmt($dates[$previous]), self::vaccineLabel($next), self::fmt($dates[$next]), $minimum),
                            Map::vaccineDate($next),
                            "{$days} jours",
                            "≥ {$minimum} jours",
                        );
                    }
                }
            }
        }
    }
}
