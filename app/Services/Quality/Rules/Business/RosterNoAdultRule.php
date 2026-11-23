<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-10 : aucun membre de 15 ans ou plus (age_group ≥ 7) dans le roster. La query liste les membres avec la tranche
 * d'âge cochée ; la fiche propose de corriger la tranche d'âge de chacun, ou de confirmer (ménage sans adulte).
 */
class RosterNoAdultRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $members = self::members($context);
        if (self::countWhere($members, 'age_group', fn () => true) === 0) {
            return; // âges non renseignés : rien à comparer
        }
        if (self::countWhere($members, 'age_group', fn ($v) => $v >= 7) === 0) {
            $roster = implode(' ; ', array_map(fn (Scope $m) => self::describe($m), $members));

            yield QueryCandidate::for($context->root, sprintf('Aucun membre de 15 ans ou plus parmi les %d membres. Tranches d\'âge cochées : %s.', count($members), $roster),
                'age_group', $roster, 'Au moins un membre de 15 ans ou plus');
        }
    }

    /** « n°2 AK (F) : 10-14 » : numéro, initiales, sexe et tranche d'âge cochée. */
    public static function describe(Scope $member): string
    {
        $choice = fn (string $list, mixed $code) => $code === null || $code === '' ? null
            : trim(strip_tags((string) (Dictionary::current()->choiceLabel($list, (string) $code) ?? $code)));
        $initials = trim((string) $member->own('person_initials'));
        $sex = $choice('sex', $member->own('person_sex'));
        $sex = $sex ? mb_strtoupper(mb_substr($sex, 0, 1)) : null; // Masculin / Féminin → M / F

        return sprintf('n°%d%s%s : %s', $member->position, $initials !== '' ? ' '.$initials : '', $sex ? " ({$sex})" : '',
            $choice('age_group', $member->own('age_group')) ?? 'non renseignée');
    }
}
