<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;
use App\Services\Quality\TeamVillageCheck;

/**
 * Village coché hors des villages du binôme, ou agent sélectionné d'un autre binôme que celui du compte ODK
 * (TeamVillageCheck). La query porte sur la question à corriger (village ou agent), propose le village de
 * l'identifiant ménage quand il est déduit, et est confiée au binôme qui a réellement collecté.
 */
class TeamVillageRule extends BusinessRule implements PreparesDataset
{
    private TeamVillageCheck $check;

    public function prepare(QualityRule $rule): void
    {
        $this->check = TeamVillageCheck::prepared();
    }

    protected function check(SubmissionContext $context): iterable
    {
        $diagnosis = $this->check->diagnose($context->household);
        if (! $diagnosis) {
            return;
        }

        $agentOnly = $diagnosis['case'] === TeamVillageCheck::AGENT;
        $variable = $agentOnly ? Map::var('binome') : Map::var('village');
        $candidate = QueryCandidate::for($context->root, $diagnosis['case_label'].' : '.$diagnosis['message'], $variable,
            $context->root->get($variable ?? ''), $agentOnly ? 'Agent du binôme '.TeamVillageCheck::teamLabel($diagnosis['account_team']) : 'Un de : '.$diagnosis['villages']);
        $candidate->suggestion = $diagnosis['suggestion'];
        $candidate->teamId = $diagnosis['team_id'];
        $candidate->discriminator = $diagnosis['case']; // un autre diagnostic = une autre query
        if ($diagnosis['propose_village']) {
            $candidate->suggestedValue = (string) $diagnosis['propose_village']->code;
            $candidate->suggestedNote = "D'après l'identifiant ménage {$context->household->household_code}";
        }

        yield $candidate;
    }
}
