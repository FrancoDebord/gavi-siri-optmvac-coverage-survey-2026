<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Incohérences statut / date :
 * - date de vaccin saisie alors que le vaccin est déclaré « non reçu » ;
 * - dates saisies alors que l'enfant n'a « jamais reçu de vaccin ».
 */
class VaccineStatusMismatchRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $everVaccinated = Map::var('recu_vaccin');

        foreach ($context->scopes('child') as $child) {
            $dates = $context->vaccineDates($child);

            foreach ($dates as $code => $date) {
                $statusVariable = config("gavi.vaccines.{$code}.var");
                if (self::no($child->own($statusVariable))) {
                    yield QueryCandidate::for(
                        $child,
                        sprintf('%s : %s déclaré non reçu mais une date est saisie (%s)', $child->label(), self::vaccineLabel($code), self::fmt($date)),
                        $statusVariable,
                        'Non',
                        'Oui (date présente)',
                    );
                }
            }

            if ($dates !== [] && $everVaccinated && self::no($child->own($everVaccinated))) {
                yield QueryCandidate::for(
                    $child,
                    sprintf('%s : déclaré jamais vacciné mais %d date(s) de vaccination saisie(s)', $child->label(), count($dates)),
                    $everVaccinated,
                    'Non',
                    'Oui',
                );
            }
        }
    }
}
