<?php

namespace App\Services\Quality;

use App\Models\QualityRule;

/**
 * Règle qui a besoin d'une vue d'ensemble des soumissions (doublons, volumes
 * par binôme, valeurs aberrantes, centroïdes GPS). prepare() est appelée une fois
 * avant le parcours des soumissions.
 */
interface PreparesDataset
{
    public function prepare(QualityRule $rule): void;
}
