<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;
use Throwable;

/**
 * MAJ-32 : au rappel, le caregiver déclare une dose que l'enfant est trop jeune pour avoir reçue. La query nomme
 * chaque dose en cause (vaccin, n° de dose, question), l'âge minimal et l'âge de l'enfant.
 */
class RecallDoseAgeRule extends BusinessRule
{
    /** Zones (l1) où la 4e dose de VAP est contrôlée : âge minimal en mois. */
    private const MAL4_MIN_AGE = ['1' => [18, 'Tchaourou'], '2' => [15, 'Dassa-Glazoué']];

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $doses = self::dosesInCause($child);
            if ($doses === []) {
                continue;
            }
            $age = (int) $child->get('age_months_est');
            $detail = implode(' ; ', array_map(fn ($d) => sprintf('%s (%s« Oui ») : âge minimal %d mois%s', $d['dose'], $d['question'], $d['min'], $d['zone'] ? ' à '.$d['zone'] : ''), $doses));

            yield QueryCandidate::for($child,
                sprintf('%s, %d mois : trop jeune pour %s au rappel – %s.', $child->label(), $age, count($doses) > 1 ? 'les doses déclarées' : 'la dose déclarée', $detail),
                'age_months_est',
                sprintf('Âge : %d mois ; ', $age).implode(' ; ', array_map(fn ($d) => $d['dose'].' : Oui', $doses)),
                implode(' ; ', array_map(fn ($d) => $d['dose'].' : '.$d['min'].' mois ou plus', $doses)),
            );
        }
    }

    /**
     * Doses déclarées au rappel avant l'âge minimal.
     *
     * @return list<array{variable: string, dose: string, question: string, min: int, zone: ?string}>
     */
    public static function dosesInCause(Scope $child): array
    {
        $age = $child->get('age_months_est');
        if (! is_numeric($age)) {
            return [];
        }
        $checks = [
            ['measmth9', 'Rougeole (RR 1, dose de 9 mois)', 9, null],
            ['measmth15', 'Rougeole (RR 2, dose de 15 mois)', 15, null],
            ['penta3_rec', 'Penta 3 (3e dose du pentavalent)', 3, null],
            ['opv3_rec', 'VPO 3 (3e dose de polio oral)', 3, null],
            ['pcv3_rec', 'PCV 3 (3e dose du pneumocoque)', 3, null],
            ['rtss1_rec', 'VAP 1 (1re dose du vaccin antipaludique)', 5, null],
        ];
        if ($zone = self::MAL4_MIN_AGE[(string) $child->get('l1')] ?? null) {
            $checks[] = ['rtss4_rec', 'VAP 4 (4e dose du vaccin antipaludique)', $zone[0], $zone[1]];
        }

        $doses = [];
        foreach ($checks as [$variable, $dose, $min, $zone]) {
            if (self::yes($child->get($variable)) && (float) $age < $min) {
                $doses[] = ['variable' => $variable, 'dose' => $dose, 'question' => self::question($variable), 'min' => $min, 'zone' => $zone];
            }
        }

        return $doses;
    }

    /** « q. 170, » : numéro de la question dans le formulaire, s'il figure dans son libellé. */
    private static function question(string $variable): string
    {
        try {
            $label = (string) (Dictionary::current()->variable($variable)['label'] ?? '');
        } catch (Throwable) {
            return '';
        }

        return preg_match('/^\s*(\d+(?:\.\d+)?)\./', strip_tags($label), $m) ? 'q. '.$m[1].', ' : '';
    }
}
