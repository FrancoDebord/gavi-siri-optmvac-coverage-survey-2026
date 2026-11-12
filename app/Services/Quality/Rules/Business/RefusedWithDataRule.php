<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class RefusedWithDataRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        $caregivers = $context->scopes('caregiver');

        if ($household->survey_status !== null && ! $household->consented && $caregivers !== []) {
            yield QueryCandidate::for(
                $context->root,
                sprintf('Ménage non enquêté (statut %s) mais %d caregiver(s) saisi(s)', $this->statusLabel($context, $household->survey_status), count($caregivers)),
                Map::var('statut_menage'),
                $household->survey_status,
                Map::value('consentement', '0'),
            );
        }

        foreach ($caregivers as $caregiver) {
            if ($context->household->caregivers->firstWhere('id', $caregiver->caregiverId)?->consented) {
                continue;
            }
            $children = array_filter($context->scopes('child'), fn ($c) => $c->caregiverId === $caregiver->caregiverId);
            if ($children !== []) {
                yield QueryCandidate::for(
                    $caregiver,
                    sprintf('%s : participation refusée mais %d enfant(s) saisi(s)', $caregiver->label(), count($children)),
                    Map::var('consentement_caregiver'),
                    $caregiver->own(Map::var('consentement_caregiver') ?? ''),
                    Map::value('consentement_caregiver', '1'),
                );
            }
        }
    }

    private function statusLabel(SubmissionContext $context, string $status): string
    {
        $list = $context->dictionary->variable(Map::var('statut_menage') ?? '')['list_name'] ?? null;

        return $list ? ($context->dictionary->choiceLabel($list, $status) ?? $status) : $status;
    }
}
