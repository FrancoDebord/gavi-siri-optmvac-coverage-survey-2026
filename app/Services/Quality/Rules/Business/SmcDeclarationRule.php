<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-41 : le caregiver déclare que l'enfant n'a pas reçu la CPS (child_got_smc = Non), mais la carte CPS montre des
 * cycles traités (treated_k = Oui). L'erreur peut porter sur la déclaration ou sur les cycles de la carte : la fiche
 * propose de corriger l'une, les autres, les deux, ou rien (RelatedFields, DataQueryController::NO_ERROR).
 * La query reste portée par child_got_smc (même identité qu'avant la règle dédiée).
 */
class SmcDeclarationRule extends BusinessRule
{
    public const CYCLES = 5;

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            if (! self::no($child->get('child_got_smc'))) {
                continue;
            }
            $cycles = self::cardCycles($child->get(...));
            if ($cycles === []) {
                continue;
            }
            $list = implode(', ', array_map(function (int $k) use ($child) {
                $date = SubmissionProcessor::date($child->get("date_{$k}"));

                return "C{$k}".($date ? ' ('.self::fmt($date).')' : '');
            }, $cycles));

            yield QueryCandidate::for($child,
                sprintf('%s : le caregiver déclare que l\'enfant n\'a pas reçu la CPS (q. 173.5 « Non »), mais la carte CPS montre %s : %s.',
                    $child->label(), count($cycles) > 1 ? 'des cycles traités' : 'un cycle traité', $list),
                'child_got_smc',
                'CPS déclarée : Non ; carte CPS : '.$list.' traité'.(count($cycles) > 1 ? 's' : ''),
                'Déclaration et carte concordantes',
            );
        }
    }

    /**
     * Cycles cochés « traité » sur la carte CPS.
     *
     * @return list<int>
     */
    public static function cardCycles(callable $get): array
    {
        return array_values(array_filter(range(1, self::CYCLES), fn ($k) => (string) $get("treated_{$k}") === '1'));
    }
}
