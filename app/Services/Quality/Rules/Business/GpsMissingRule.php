<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class GpsMissingRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        if ($context->household->latitude === null || $context->household->longitude === null) {
            yield QueryCandidate::for($context->root, 'Coordonnées GPS du ménage manquantes', Map::var('gps'), $context->root->get(Map::var('gps') ?? ''), 'Point GPS');
        }
    }
}
