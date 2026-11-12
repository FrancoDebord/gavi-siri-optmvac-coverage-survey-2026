<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Carnet consulté mais aucune date de vaccination saisie et aucun motif de date manquante. */
class CardSeenNoDatesRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $cardVariable = Map::var('carnet_vu');
        if (! $cardVariable) {
            return;
        }
        foreach ($context->scopes('child') as $child) {
            if ((string) $child->own($cardVariable) !== Map::value('carnet_vu', '1') || $context->vaccineDates($child) !== []) {
                continue;
            }
            $missingReasons = array_filter(config('gavi.vaccines'), fn ($v) => (string) $child->own($v['var'].'Invalid') !== '');
            if ($missingReasons !== []) {
                continue;
            }

            yield QueryCandidate::for($child, $child->label().' : carnet consulté mais aucune date de vaccination saisie', $cardVariable, 'Oui', 'Au moins une date ou un motif');
        }
    }
}
