<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-29 : VAP 4 moins de 150 jours après VAP 3 (ou avant VAP 3). L'erreur peut porter sur l'une ou l'autre date :
 * la query donne les deux dates et l'écart, la fiche propose de corriger VAP 3 ou VAP 4 (RelatedFields).
 * La query reste portée par la date de VAP 3 (même identité qu'avant la règle dédiée).
 */
class VapIntervalRule extends BusinessRule
{
    public const MIN_DAYS = 150;

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $vap3 = SubmissionProcessor::date($child->get('mal3date'));
            $vap4 = SubmissionProcessor::date($child->get('mal4date'));
            if (! $vap3 || ! $vap4) {
                continue;
            }
            $days = (int) $vap3->diffInDays($vap4, false);
            if ($days >= self::MIN_DAYS) {
                continue;
            }
            $gap = match (true) {
                $days < 0 => sprintf('VAP 4 (%s) est datée avant VAP 3 (%s)', self::fmt($vap4), self::fmt($vap3)),
                $days === 0 => sprintf('VAP 3 et VAP 4 ont la même date (%s)', self::fmt($vap3)),
                default => sprintf('VAP 4 (%s) n\'est que %d jours après VAP 3 (%s)', self::fmt($vap4), $days, self::fmt($vap3)),
            };

            yield QueryCandidate::for($child,
                sprintf('%s : %s ; il faut au moins %d jours entre les deux doses. L\'une des deux dates est fausse (jour et mois inversés, année, dose).',
                    $child->label(), $gap, self::MIN_DAYS),
                'mal3date',
                sprintf('VAP 3 : %s ; VAP 4 : %s ; écart : %d jours', self::fmt($vap3), self::fmt($vap4), $days),
                sprintf('VAP 4 au moins %d jours après VAP 3', self::MIN_DAYS),
            );
        }
    }
}
