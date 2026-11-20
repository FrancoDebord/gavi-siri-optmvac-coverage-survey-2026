<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\SubmissionContext;

/** Roster des caregivers : caregivers déclarés = caregivers saisis. */
class RosterCaregiversRule extends RosterCountRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $entered = count($context->scopes('caregiver'));
        if ($candidate = $this->compare($context->root, self::variable('nb_caregivers'), $entered, 'caregiver(s)', 'roster des caregivers')) {
            yield $candidate;
        }
    }
}
