<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class InterviewDurationRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        if ($household->duration_minutes === null || ! $household->consented) {
            return;
        }
        $min = $this->params->int('min_minutes', 10);
        $max = $this->params->int('max_minutes', 120);
        $duration = $household->duration_minutes;

        if ($duration < $min || $duration > $max) {
            yield QueryCandidate::for(
                $context->root,
                sprintf('Durée d\'interview %s : %d min (attendu %d–%d min)', $duration < $min ? 'trop courte' : 'trop longue', $duration, $min, $max),
                'end',
                "{$duration} min",
                "{$min}–{$max} min",
            );
        }
    }
}
