<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class VaccineAfterInterviewRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $interview = $context->interviewDate();
        if (! $interview) {
            return;
        }
        foreach ($context->scopes('child') as $child) {
            foreach ($context->vaccineDates($child) as $code => $date) {
                if ($date->gt($interview)) {
                    yield QueryCandidate::for(
                        $child,
                        sprintf('%s : %s daté du %s, après l\'interview (%s)', $child->label(), self::vaccineLabel($code), self::fmt($date), self::fmt($interview)),
                        Map::vaccineDate($code),
                        self::fmt($date),
                        '≤ '.self::fmt($interview),
                    );
                }
            }
        }
    }
}
