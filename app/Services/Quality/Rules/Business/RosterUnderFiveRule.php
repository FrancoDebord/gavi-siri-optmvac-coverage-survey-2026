<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-09 : enfants de moins de 5 ans déclarés par les caregivers > membres de moins de 5 ans dans le roster.
 * La query détaille les valeurs lues : nombre déclaré par chaque caregiver et membres de moins de 5 ans listés.
 */
class RosterUnderFiveRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $members = self::members($context);
        $byCaregiver = [];
        foreach ($context->scopes('caregiver') as $caregiver) {
            if (is_numeric($caregiver->own('caregiver_num_children')) && (int) $caregiver->own('caregiver_num_children') > 0) {
                $byCaregiver[$caregiver->position] = (int) $caregiver->own('caregiver_num_children');
            }
        }
        $declared = array_sum($byCaregiver);
        if ($members === [] || $declared === 0) {
            return;
        }
        $young = array_values(array_filter($members, fn (Scope $m) => is_numeric($m->own('age_group')) && (int) $m->own('age_group') === 0));
        $underFive = count($young);
        if ($declared <= $underFive) {
            return;
        }

        $detail = implode(', ', array_map(fn ($position, $n) => "caregiver n°{$position} : {$n}", array_keys($byCaregiver), $byCaregiver));
        $listed = $underFive === 0 ? 'aucun membre de moins de 5 ans' : sprintf('%d membre(s) de moins de 5 ans (n°%s)', $underFive, implode(', n°', array_map(fn (Scope $m) => $m->position, $young)));

        yield QueryCandidate::for(
            $context->root,
            sprintf('%d enfant(s) déclaré(s) par les caregivers (%s), mais liste des membres : %s sur %d.', $declared, $detail, $listed, count($members)),
            'caregiver_num_children',
            sprintf('Déclarés : %d (%s) · Liste des membres : %s', $declared, $detail, $listed),
            "Enfants déclarés ≤ {$underFive} (membres de moins de 5 ans listés)",
        );
    }
}
