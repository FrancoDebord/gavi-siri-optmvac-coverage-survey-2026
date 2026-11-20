<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/** MAJ-09 : enfants de moins de 5 ans déclarés par les caregivers > membres de moins de 5 ans dans le roster. */
class RosterUnderFiveRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $members = self::members($context);
        $declared = array_sum(array_map(fn (Scope $cg) => is_numeric($cg->own('caregiver_num_children')) ? (int) $cg->own('caregiver_num_children') : 0, $context->scopes('caregiver')));
        if ($members === [] || $declared === 0) {
            return;
        }
        $underFive = self::countWhere($members, 'age_group', fn ($v) => $v === 0);
        if ($declared > $underFive) {
            yield QueryCandidate::for($context->root, sprintf('%d enfant(s) déclaré(s) par les caregivers, %d membre(s) de moins de 5 ans dans la liste.', $declared, $underFive),
                'caregiver_num_children', (string) $declared, "≤ {$underFive} (membres de moins de 5 ans)");
        }
    }
}
