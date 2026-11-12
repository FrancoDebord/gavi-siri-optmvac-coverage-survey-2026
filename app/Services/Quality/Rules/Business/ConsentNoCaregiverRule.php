<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class ConsentNoCaregiverRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        if (! $context->household->consented || $context->household->caregivers->isNotEmpty()) {
            return;
        }

        yield QueryCandidate::for(
            $context->root,
            'Ménage ayant accepté l\'enquête mais module caregiver vide',
            Map::var('repeat_caregivers'),
            '0 caregiver',
            'Au moins un caregiver',
        );
    }
}
