<?php

namespace App\Services\Quality\Rules\Business;

/**
 * MAJ-31 : au rappel du caregiver (enfant sans carte ou dose absente de la carte), dose déclarée reçue alors que la
 * dose précédente de la même série est déclarée non reçue (VPO, Penta, PCV, Rota, VAP). « Ne sais pas » n'est pas
 * relevé. Même présentation que MAJ-26 : série et doses en cause nommées, réponses de la série listées, correction de
 * l'une ou l'autre proposée.
 */
class RecallDoseSeriesOrderRule extends DoseSeriesOrderRule
{
    public const SERIES = [
        ['opv1_rec', 'opv2_rec', 'opv3_rec'], ['penta1_rec', 'penta2_rec', 'penta3_rec'], ['pcv1_rec', 'pcv2_rec', 'pcv3_rec'],
        ['rota1_rec', 'rota2_rec', 'rota3_rec'], ['rtss1_rec', 'rtss2_rec', 'rtss3_rec', 'rtss4_rec'],
    ];

    protected const SUMMARY = 'au rappel, dose déclarée reçue sans la dose précédente';

    protected const ANSWERED = 'répondu';

    /** Ex. « VAP 2 » pour rtss2_rec (vaccin antipaludique, noté rtss au rappel). */
    public static function doseName(string $variable): string
    {
        return parent::doseName(str_replace('rtss', 'mal', preg_replace('/_rec$/', '', $variable)));
    }
}
