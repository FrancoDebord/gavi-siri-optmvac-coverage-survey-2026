<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;
use Carbon\CarbonImmutable;

/**
 * MAJ-37 : dates des cycles CPS (date_1 … date_5) dans le désordre. Chaque date est comparée à celle du cycle
 * précédent qui a une date. La query liste toutes les dates saisies et nomme les cycles en cause ; la fiche
 * propose la correction de chacune des dates en cause (RelatedFields).
 */
class SmcCycleOrderRule extends BusinessRule
{
    public const CYCLES = 5;

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $dates = self::dates(fn (string $variable) => $child->get($variable));
            $inversions = self::inversions($dates);
            if ($inversions === []) {
                continue;
            }
            $all = self::listing($dates, fn (string $variable) => $child->get($variable));
            $cause = implode(' ; ', array_map(fn ($pair) => sprintf('C%d (%s) %s C%d (%s)', $pair[1], self::fmt($dates[$pair[1]]),
                $dates[$pair[1]]->equalTo($dates[$pair[0]]) ? 'le même jour que' : 'avant', $pair[0], self::fmt($dates[$pair[0]])), $inversions));

            yield QueryCandidate::for($child, $child->label().' : dates des cycles CPS dans le désordre – '.$cause.'. Dates saisies : '.$all.'.',
                'date_1', $all, 'C1 < C2 < C3 < C4 < C5');
        }
    }

    /** @return array<int, CarbonImmutable> n° de cycle => date saisie */
    public static function dates(callable $get): array
    {
        $dates = [];
        for ($k = 1; $k <= self::CYCLES; $k++) {
            if ($date = SubmissionProcessor::date($get("date_{$k}"))) {
                $dates[$k] = $date;
            }
        }

        return $dates;
    }

    /** @return list<array{0: int, 1: int}> [cycle précédent, cycle] dont la date n'est pas postérieure */
    public static function inversions(array $dates): array
    {
        $pairs = [];
        $previous = null;
        foreach ($dates as $k => $date) {
            if ($previous !== null && $date->lessThanOrEqualTo($dates[$previous])) {
                $pairs[] = [$previous, $k];
            }
            $previous = $k;
        }

        return $pairs;
    }

    /** Cycles en cause (n° triés), pour proposer la correction de leurs dates. */
    public static function cyclesInCause(array $dates): array
    {
        $cycles = array_values(array_unique(array_merge([], ...self::inversions($dates))));
        sort($cycles);

        return $cycles;
    }

    protected static function listing(array $dates, callable $get): string
    {
        $parts = [];
        for ($k = 1; $k <= self::CYCLES; $k++) {
            $parts[] = "C{$k} : ".(isset($dates[$k]) ? self::fmt($dates[$k]) : (self::yes($get("treated_{$k}")) ? 'traité, sans date' : '—'));
        }

        return implode(' · ', $parts);
    }
}
