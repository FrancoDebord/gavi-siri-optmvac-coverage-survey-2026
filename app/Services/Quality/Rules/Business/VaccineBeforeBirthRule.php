<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class VaccineBeforeBirthRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $dob = $context->childDob($child);
            if (! $dob) {
                continue;
            }
            foreach ($context->vaccineDates($child) as $code => $date) {
                if ($date->lt($dob)) {
                    yield QueryCandidate::for(
                        $child,
                        sprintf('%s : %s daté du %s, avant la naissance (%s)', $child->label(), self::vaccineLabel($code), self::fmt($date), self::fmt($dob)),
                        Map::vaccineDate($code),
                        self::fmt($date),
                        '≥ '.self::fmt($dob),
                    );
                }
            }
        }
    }
}
