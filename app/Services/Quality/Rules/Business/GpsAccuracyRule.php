<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class GpsAccuracyRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $max = $this->params->float('max_accuracy_m', 50);
        $accuracy = $context->household->gps_accuracy;
        if ($accuracy !== null && $accuracy > $max) {
            yield QueryCandidate::for($context->root, sprintf('Précision GPS de %.0f m (seuil %.0f m)', $accuracy, $max), Map::var('gps'), round($accuracy).' m', "≤ {$max} m");
        }
    }
}
