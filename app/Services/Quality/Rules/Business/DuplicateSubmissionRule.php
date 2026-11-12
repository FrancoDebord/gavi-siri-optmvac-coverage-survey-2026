<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Soumissions distinctes ayant la même heure de début pour un même binôme, ou un contenu identique. */
class DuplicateSubmissionRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, list<string>> */
    private array $duplicates = [];

    public function prepare(QualityRule $rule): void
    {
        $this->duplicates = [];
        $households = Household::get(['id', 'submission_uuid', 'collector_id', 'started_at', 'content_hash']);

        $sameStart = $households->whereNotNull('started_at')->groupBy(fn ($h) => $h->collector_id.'|'.$h->started_at->toIso8601String());
        $sameContent = $households->whereNotNull('content_hash')->groupBy('content_hash');

        foreach ([$sameStart, $sameContent] as $groups) {
            foreach ($groups->filter(fn ($g) => $g->count() > 1) as $group) {
                foreach ($group as $household) {
                    foreach ($group->where('id', '!=', $household->id) as $other) {
                        $this->duplicates[$household->id][$other->submission_uuid] = $other->submission_uuid;
                    }
                }
            }
        }
    }

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($this->duplicates[$context->household->id] ?? [] as $uuid) {
            $candidate = QueryCandidate::for($context->root, 'Soumission probablement en double (même début d\'interview ou contenu identique)', 'start', $context->household->started_at?->format('d/m/Y H:i'), 'Doublon de '.$uuid);
            $candidate->discriminator = $uuid;

            yield $candidate;
        }
    }
}
