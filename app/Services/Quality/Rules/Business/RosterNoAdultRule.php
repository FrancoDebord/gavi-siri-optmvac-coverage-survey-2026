<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** MAJ-10 : aucun membre de 15 ans ou plus (age_group ≥ 7) dans le roster. */
class RosterNoAdultRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $members = self::members($context);
        if (self::countWhere($members, 'age_group', fn () => true) === 0) {
            return; // âges non renseignés : rien à comparer
        }
        if (self::countWhere($members, 'age_group', fn ($v) => $v >= 7) === 0) {
            yield QueryCandidate::for($context->root, sprintf('Aucun membre de 15 ans ou plus parmi les %d membres.', count($members)),
                'age_group', '0 adulte', 'Au moins un membre de 15 ans ou plus');
        }
    }
}
