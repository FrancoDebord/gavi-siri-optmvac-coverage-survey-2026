<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Cluster;
use App\Models\QualityRule;
use App\Models\Village;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Village hors de l'arrondissement / commune déclarés ; grappe rattachée à un autre village. */
class GeoHierarchyRule extends BusinessRule implements PreparesDataset
{
    private array $villages = [];

    private array $clusters = [];

    public function prepare(QualityRule $rule): void
    {
        $this->villages = Village::get(['id', 'name', 'arrondissement_id', 'commune_id'])->keyBy('id')->all();
        $this->clusters = Cluster::get(['id', 'code', 'village_id'])->keyBy('id')->all();
    }

    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        $village = $this->villages[$household->village_id] ?? null;
        if (! $village) {
            return;
        }

        if ($household->arrondissement_id && $village->arrondissement_id && $village->arrondissement_id !== $household->arrondissement_id) {
            yield QueryCandidate::for($context->root, "Le village {$village->name} n'appartient pas à l'arrondissement déclaré", Map::var('village'), $context->root->get(Map::var('village') ?? ''), 'Village de l\'arrondissement '.$context->root->get(Map::var('arrondissement') ?? ''));
        } elseif ($household->commune_id && $village->commune_id && $village->commune_id !== $household->commune_id) {
            yield QueryCandidate::for($context->root, "Le village {$village->name} n'appartient pas à la commune déclarée", Map::var('village'), $context->root->get(Map::var('village') ?? ''), 'Village de la commune '.$context->root->get(Map::var('commune') ?? ''));
        }

        $cluster = $this->clusters[$household->cluster_id] ?? null;
        if ($cluster && $cluster->village_id && $cluster->village_id !== $household->village_id) {
            yield QueryCandidate::for($context->root, "La grappe {$cluster->code} est rattachée à un autre village que celui déclaré ({$village->name})", Map::var('grappe') ?? Map::var('village'), $cluster->code, 'Grappe du village '.$village->name);
        }
    }
}
