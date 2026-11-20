<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/** Roster des enfants : enfants de moins de 5 ans déclarés par chaque caregiver = enfants saisis pour lui. */
class RosterChildrenRule extends RosterCountRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $children = $context->scopes('child');
        foreach ($context->scopes('caregiver') as $caregiver) {
            $entered = count(array_filter($children, fn (Scope $child) => $child->parent === $caregiver));
            if ($candidate = $this->compare($caregiver, self::variable('nb_enfants_caregiver'), $entered, 'enfant(s) de moins de 5 ans', 'roster des enfants')) {
                yield $candidate;
            }
        }
    }
}
