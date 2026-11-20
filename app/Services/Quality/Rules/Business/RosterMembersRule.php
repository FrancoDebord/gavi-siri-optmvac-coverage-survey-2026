<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/** Roster des membres : personnes déclarées (ayant dormi dans le ménage) = membres saisis dans le roster. */
class RosterMembersRule extends RosterCountRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $repeat = self::variable('repeat_membres');
        if (! $repeat || ! $context->root->filled(self::variable('nb_membres') ?? '')) {
            return;
        }
        $entered = count(array_filter($context->scopesForRepeat($repeat), fn (Scope $s) => $s->parent === $context->root));
        if ($candidate = $this->compare($context->root, self::variable('nb_membres'), $entered, 'personne(s)', 'roster des membres du ménage')) {
            yield $candidate;
        }
    }
}
