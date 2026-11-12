<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class TodayStartMismatchRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $today = SubmissionProcessor::date($context->root->get(Map::var('today') ?? ''));
        $start = $context->household->started_at?->setTimezone(config('app.timezone'));
        if ($today && $start && $today->toDateString() !== $start->toDateString()) {
            yield QueryCandidate::for($context->root, sprintf('Date « today » (%s) différente de la date de début (%s)', self::fmt($today), $start->format('d/m/Y')), Map::var('today'), self::fmt($today), $start->format('d/m/Y'));
        }
    }
}
