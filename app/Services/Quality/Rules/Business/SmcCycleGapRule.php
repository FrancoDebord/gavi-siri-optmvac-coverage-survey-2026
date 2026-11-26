<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-38 : deux cycles CPS à moins de 3 semaines (21 jours). Chaque date est comparée à celle du cycle précédent qui a
 * une date (un ordre inversé relève de MAJ-37). La query liste toutes les dates saisies et nomme les cycles trop
 * rapprochés ; toutes les dates saisies peuvent être en cause : la fiche propose de corriger chacune (RelatedFields).
 * La query reste portée par date_1 (même identité qu'avant la règle dédiée).
 */
class SmcCycleGapRule extends SmcCycleOrderRule
{
    public const MIN_DAYS = 21;

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $dates = self::dates(fn (string $variable) => $child->get($variable));
            $close = self::closePairs($dates);
            if ($close === []) {
                continue;
            }
            $all = self::listing($dates, fn (string $variable) => $child->get($variable));
            $cause = implode(' ; ', array_map(fn ($pair) => sprintf('C%d (%s) %d jours après C%d (%s)', $pair[1], self::fmt($dates[$pair[1]]),
                $dates[$pair[0]]->diffInDays($dates[$pair[1]]), $pair[0], self::fmt($dates[$pair[0]])), $close));

            yield QueryCandidate::for($child, sprintf('%s : cycles CPS à moins de 3 semaines – %s. Dates saisies : %s.', $child->label(), $cause, $all),
                'date_1', $all, 'Au moins '.self::MIN_DAYS.' jours entre deux cycles');
        }
    }

    /** @return list<array{0: int, 1: int}> [cycle précédent, cycle] séparés de 1 à 20 jours */
    public static function closePairs(array $dates): array
    {
        $pairs = [];
        $previous = null;
        foreach ($dates as $k => $date) {
            if ($previous !== null) {
                $days = (int) $dates[$previous]->diffInDays($date, false);
                if ($days > 0 && $days < self::MIN_DAYS) {
                    $pairs[] = [$previous, $k];
                }
            }
            $previous = $k;
        }

        return $pairs;
    }
}
