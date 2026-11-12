<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Même ID ménage dans la même grappe : doublon si même numéro de visite,
 * ou si plusieurs visites ont abouti à une interview acceptée.
 */
class DuplicateHouseholdRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, list<array{uuid: string, reason: string}>> */
    private array $duplicates = [];

    public function prepare(QualityRule $rule): void
    {
        $this->duplicates = [];
        $groups = Household::query()
            ->whereNotNull('household_code')->where('household_code', '!=', '')
            ->get(['id', 'submission_uuid', 'household_code', 'cluster_id', 'visit_number', 'consented'])
            ->groupBy(fn ($h) => $h->cluster_id.'|'.mb_strtolower($h->household_code))
            ->filter(fn ($g) => $g->count() > 1);

        foreach ($groups as $group) {
            foreach ($group as $household) {
                $others = $group->where('id', '!=', $household->id);
                $sameVisit = $others->filter(fn ($o) => $o->visit_number !== null && $o->visit_number === $household->visit_number);
                $bothConsented = $household->consented ? $others->where('consented', true) : collect();
                foreach ($sameVisit->merge($bothConsented)->unique('id') as $other) {
                    $this->duplicates[$household->id][] = [
                        'uuid' => $other->submission_uuid,
                        'reason' => $sameVisit->contains('id', $other->id) ? 'même numéro de visite' : 'deux interviews acceptées',
                    ];
                }
            }
        }
    }

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($this->duplicates[$context->household->id] ?? [] as $duplicate) {
            $candidate = QueryCandidate::for(
                $context->root,
                "Ménage {$context->household->household_code} enregistré plusieurs fois dans la grappe ({$duplicate['reason']})",
                Map::var('id_menage'),
                $context->household->household_code,
                'Identifiant unique par grappe',
            );
            $candidate->discriminator = $duplicate['uuid'];
            $candidate->expected = 'Doublon de '.$duplicate['uuid'];

            yield $candidate;
        }
    }
}
