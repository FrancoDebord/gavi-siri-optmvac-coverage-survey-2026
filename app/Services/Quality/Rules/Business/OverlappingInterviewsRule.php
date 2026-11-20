<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** MAJ-47 : deux entretiens du même agent dont les plages [début, fin] se recouvrent. */
class OverlappingInterviewsRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, list<array{id: int, code: ?string, start: int, end: int}>> agent => entretiens */
    private array $byCollector = [];

    public function prepare(QualityRule $rule): void
    {
        $this->byCollector = [];
        Household::whereNotNull('collector_id')->whereNotNull('started_at')->whereNotNull('ended_at')
            ->get(['id', 'household_code', 'collector_id', 'started_at', 'ended_at'])
            ->each(function ($h) {
                if ($h->ended_at > $h->started_at) {
                    $this->byCollector[$h->collector_id][] = ['id' => $h->id, 'code' => $h->household_code, 'start' => $h->started_at->getTimestamp(), 'end' => $h->ended_at->getTimestamp()];
                }
            });
    }

    protected function check(SubmissionContext $context): iterable
    {
        $h = $context->household;
        if (! $h->collector_id || ! $h->started_at || ! $h->ended_at || $h->ended_at <= $h->started_at) {
            return;
        }
        [$start, $end] = [$h->started_at->getTimestamp(), $h->ended_at->getTimestamp()];
        $overlaps = array_filter($this->byCollector[$h->collector_id] ?? [], fn ($o) => $o['id'] !== $h->id && $o['start'] < $end && $start < $o['end']);
        if ($overlaps !== []) {
            $codes = array_slice(array_map(fn ($o) => $o['code'] ?? '?', array_values($overlaps)), 0, 3);
            yield QueryCandidate::for($context->root, sprintf('Entretien du %s, %s–%s, en même temps que le(s) ménage(s) %s.',
                $h->started_at->format('d/m/Y'), $h->started_at->format('H:i'), $h->ended_at->format('H:i'), implode(', ', $codes)),
                'start', $h->started_at->format('d/m/Y H:i').' – '.$h->ended_at->format('H:i'), 'Aucun autre entretien du même agent');
        }
    }
}
