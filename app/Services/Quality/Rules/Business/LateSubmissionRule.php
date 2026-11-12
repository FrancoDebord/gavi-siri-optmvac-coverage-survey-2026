<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class LateSubmissionRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        $reference = $household->ended_at ?? $household->started_at;
        if (! $reference || ! $household->submitted_at) {
            return;
        }
        $max = $this->params->float('max_days', 2);
        $days = $reference->diffInHours($household->submitted_at, false) / 24;

        if ($days > $max) {
            yield QueryCandidate::for(
                $context->root,
                sprintf('Soumission envoyée %.1f jour(s) après la fin de l\'interview (seuil %s jours)', $days, $max),
                'end',
                $household->submitted_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
                "≤ {$max} jours",
            );
        }
    }
}
