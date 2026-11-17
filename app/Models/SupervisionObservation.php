<?php

namespace App\Models;

/**
 * Observation de supervision : fait relevé sur les paradonnées (heure, durée, volume journalier, délai d'envoi,
 * précision GPS…). À examiner par la supervision, sans valeur à corriger : hors du cycle de résolution des queries.
 */
class SupervisionObservation extends DataQuery
{
    public const KIND = 'observation';

    protected $table = 'data_queries';
}
