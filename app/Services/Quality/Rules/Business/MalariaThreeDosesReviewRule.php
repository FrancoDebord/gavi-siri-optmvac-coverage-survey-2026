<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-34 : revue « l'enfant a reçu les 3 doses de VAP la 1re année » = Oui, contredite par la carte (VAP 3 non reçu),
 * le rappel du caregiver (3e dose non reçue, sans VAP 3 sur la carte) ou l'âge (moins de 7 mois).
 *
 * Carnet portant le cachet « Enfant complètement vacciné » : l'enfant a reçu tous les vaccins, même si les cases sont
 * vides. Le cachet n'est pas saisi dans le formulaire : un carnet vu sans aucune dose de VAP cochée, pour un enfant
 * déclaré vacciné, est traité comme tel (ni la carte ni le rappel ne contredisent alors la revue).
 */
class MalariaThreeDosesReviewRule extends BusinessRule
{
    private const MIN_AGE = 7;

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $cause = self::inCause($child);
            if ($cause === []) {
                continue;
            }
            $age = $child->get('age_months_est');
            $reasons = [];
            if (in_array('mal3', $cause, true)) {
                $reasons[] = 'la carte indique VAP 3 non reçu ('.self::card($child).')';
            }
            if (in_array('rtss3_rec', $cause, true)) {
                $reasons[] = 'au rappel, le caregiver déclare la 3e dose non reçue ('.self::recall($child).')';
            }
            if (in_array('age_months_est', $cause, true)) {
                $reasons[] = sprintf('l\'enfant a %d mois (3 doses impossibles avant %d mois)', (int) $age, self::MIN_AGE);
            }
            $ageText = is_numeric($age) ? sprintf(', %d mois', (int) $age) : '';

            yield QueryCandidate::for($child,
                $child->label().$ageText.' : revue « 3 doses de VAP la 1re année » = Oui, mais '.implode(' ; ', $reasons).'.',
                'three_MV_doses',
                implode(' ; ', array_filter([
                    'Revue : Oui',
                    $child->get('mal3') !== null && $child->get('mal3') !== '' ? 'Carte : '.self::card($child) : null,
                    $child->get('rtss3_rec') !== null && $child->get('rtss3_rec') !== '' ? 'Rappel : '.self::recall($child) : null,
                    is_numeric($age) ? 'Âge : '.(int) $age.' mois' : null,
                ])),
                'Oui seulement si VAP 3 reçu (carte ou rappel) et enfant de '.self::MIN_AGE.' mois ou plus',
            );
        }
    }

    /**
     * Valeurs qui contredisent la revue : mal3 (carte), rtss3_rec (rappel), age_months_est (âge).
     *
     * @return list<string>
     */
    public static function inCause(Scope $child): array
    {
        if (! self::yes($child->get('three_MV_doses'))) {
            return [];
        }
        $stamped = self::stampedCard($child);
        $cause = [];
        if (! $stamped && self::no($child->get('mal3'))) {
            $cause[] = 'mal3';
        }
        if (! $stamped && ! self::yes($child->get('mal3')) && self::no($child->get('rtss3_rec'))) {
            $cause[] = 'rtss3_rec';
        }
        if (is_numeric($child->get('age_months_est')) && (float) $child->get('age_months_est') < self::MIN_AGE) {
            $cause[] = 'age_months_est';
        }

        return $cause;
    }

    /** Carnet vu, aucune dose de VAP cochée, enfant déclaré vacciné : carnet « Enfant complètement vacciné ». */
    public static function stampedCard(Scope $child): bool
    {
        if (! self::yes($child->get('see_card')) || ! self::yes($child->get('receive_vaccine'))) {
            return false;
        }
        foreach (['mal1', 'mal2', 'mal3', 'mal4'] as $dose) {
            if (self::yes($child->get($dose))) {
                return false;
            }
        }

        return true;
    }

    private static function card(Scope $child): string
    {
        return implode(', ', array_map(function (int $n) use ($child) {
            $date = SubmissionProcessor::date($child->get("mal{$n}date"));

            return "VAP {$n} : ".self::answer($child->get("mal{$n}")).($date ? ' le '.self::fmt($date) : '');
        }, [1, 2, 3]));
    }

    private static function recall(Scope $child): string
    {
        return implode(', ', array_map(fn (int $n) => ['', '1re', '2e', '3e'][$n].' dose : '.self::answer($child->get("rtss{$n}_rec")), [1, 2, 3]));
    }

    private static function answer(mixed $value): string
    {
        return match ((string) $value) {
            '1' => 'Oui',
            '0' => 'Non',
            '2' => 'NSP',
            '' => '—',
            default => (string) $value,
        };
    }
}
