<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** MAJ-08 : caregivers déclarés ≠ membres notés « caregiver » dans le roster. */
class RosterCaregiverFlagRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $declared = $context->root->own('hh_caregivers_num');
        $members = self::members($context);
        if (! is_numeric($declared) || $members === []) {
            return;
        }
        $flagged = self::countWhere($members, 'person_caregiver', fn ($v) => $v === 1);
        if ($flagged !== (int) $declared) {
            yield QueryCandidate::for($context->root, sprintf('%d caregiver(s) déclaré(s), %d membre(s) noté(s) caregiver dans la liste.', (int) $declared, $flagged),
                'hh_caregivers_num', (string) $declared, "{$flagged} (membres notés caregiver)");
        }
    }
}
