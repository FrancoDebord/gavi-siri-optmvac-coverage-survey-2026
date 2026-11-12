<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Une dose datée avant la dose précédente de la même série. */
class VaccineDoseOrderRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $dates = $context->vaccineDates($child);
            foreach (config('gavi.dose_series') as $series) {
                foreach (self::consecutivePairs($series, $dates) as [$previous, $next]) {
                    if ($dates[$next]->lt($dates[$previous])) {
                        yield QueryCandidate::for(
                            $child,
                            sprintf('%s : %s (%s) avant %s (%s)', $child->label(), self::vaccineLabel($next), self::fmt($dates[$next]), self::vaccineLabel($previous), self::fmt($dates[$previous])),
                            Map::vaccineDate($next),
                            self::fmt($dates[$next]),
                            'Après '.self::fmt($dates[$previous]),
                        );
                    }
                }
            }
        }
    }

    /**
     * Paires de doses successives renseignées (une dose manquante est ignorée).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function consecutivePairs(array $series, array $dates): array
    {
        $present = array_values(array_filter($series, fn ($code) => isset($dates[$code])));
        $pairs = [];
        for ($i = 1; $i < count($present); $i++) {
            $pairs[] = [$present[$i - 1], $present[$i]];
        }

        return $pairs;
    }
}
