<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-26 : dose cochée « reçue » sur la carte alors que la dose précédente de la même série est cochée « non reçue »
 * (VPO, Penta, PCV, Rota, VAP, VPI, RR). La règle contrôle sept séries : la query nomme la série et les doses en cause
 * (avec le n° de question), liste les réponses de cette série, et porte sur la dose reçue sans la précédente ; la fiche
 * propose de corriger l'une ou l'autre (RelatedFields).
 */
class DoseSeriesOrderRule extends BusinessRule
{
    /** Séries contrôlées : questions « Vaccin … reçu ? » de la carte, dans l'ordre des doses. */
    public const SERIES = [
        ['opv1', 'opv2', 'opv3'], ['Penta1', 'Penta2', 'Penta3'], ['pcv1', 'pcv2', 'pcv3'], ['rota1', 'rota2', 'rota3'],
        ['mal1', 'mal2', 'mal3', 'mal4'], ['ipv1', 'ipv2'], ['meas1', 'meas2'],
    ];

    /** Début du message, après l'enfant concerné. */
    protected const SUMMARY = 'dose reçue sans la dose précédente sur la carte';

    /** Une dose en cause : nom, n° de question, réponse (« cochée » sur la carte). */
    protected const ANSWERED = 'cochée';

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            $get = fn (string $variable) => $child->get($variable);
            $gaps = static::gaps($get);
            if ($gaps === []) {
                continue;
            }
            $cause = implode(' ; ', array_map(fn (array $gap) => sprintf('%s (%s) %s « Oui », mais %s (%s) %s « Non »',
                static::doseName($gap['dose']), self::questionNumber($gap['dose']), static::ANSWERED,
                static::doseName($gap['previous']), self::questionNumber($gap['previous']), static::ANSWERED), $gaps));
            $observed = implode(' ; ', array_map(fn (string $v) => $v.' = '.(($value = $get($v)) === null || $value === '' ? '∅' : $value), static::seriesInCause($gaps)));

            yield QueryCandidate::for($child, $child->label().' : '.static::SUMMARY.' – '.$cause.'.',
                $gaps[0]['dose'], $observed, 'Dose précédente reçue, ou dose suivante non reçue');
        }
    }

    /**
     * Doses reçues sans la précédente (« Oui » après « Non »).
     *
     * @return list<array{dose: string, previous: string}>
     */
    public static function gaps(callable $get): array
    {
        $gaps = [];
        foreach (static::SERIES as $series) {
            for ($i = 1; $i < count($series); $i++) {
                if (self::yes($get($series[$i])) && self::no($get($series[$i - 1]))) {
                    $gaps[] = ['dose' => $series[$i], 'previous' => $series[$i - 1]];
                }
            }
        }

        return $gaps;
    }

    /** Questions des séries en cause (toutes leurs doses), pour lister les réponses. */
    public static function seriesInCause(array $gaps): array
    {
        $doses = array_merge(...array_map(fn (array $g) => [$g['dose'], $g['previous']], $gaps));

        return array_values(array_merge(...array_filter(static::SERIES, fn (array $series) => array_intersect($series, $doses) !== [])));
    }

    /** Ex. « RR 2 » (libellé du calendrier vaccinal). */
    public static function doseName(string $variable): string
    {
        return self::vaccineLabel(strtolower($variable));
    }

    /** Ex. « q. 152 » d'après le libellé du formulaire (« 152. Vaccin RR 2 reçu ? »). */
    private static function questionNumber(string $variable): string
    {
        $label = (string) (Dictionary::current()->variable($variable)['label'] ?? '');

        return preg_match('/^\s*(\d+)\s*\./', strip_tags($label), $m) ? 'q. '.$m[1] : $variable;
    }
}
