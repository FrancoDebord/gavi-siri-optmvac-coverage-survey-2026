<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QualityRuleParams;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Trop d'interviews pour un agent sur une journée. Une seule query par agent et par jour,
 * rattachée à la dernière soumission de la journée.
 */
class CollectorDailyVolumeRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, array{count: int, date: string}> household_id => volume du jour */
    private array $flagged = [];

    public function prepare(QualityRule $rule): void
    {
        $max = QualityRuleParams::of($rule)->int('max_per_day', 20);
        $this->flagged = [];

        Household::whereNotNull('collector_id')->whereNotNull('interview_date')
            ->get(['id', 'collector_id', 'interview_date', 'started_at'])
            ->groupBy(fn ($h) => $h->collector_id.'|'.$h->interview_date->toDateString())
            ->filter(fn ($group) => $group->count() > $max)
            ->each(function ($group) {
                $last = $group->sortBy(fn ($h) => [$h->started_at?->timestamp ?? 0, $h->id])->last();
                $this->flagged[$last->id] = ['count' => $group->count(), 'date' => $last->interview_date->format('d/m/Y')];
            });
    }

    protected function check(SubmissionContext $context): iterable
    {
        $flag = $this->flagged[$context->household->id] ?? null;
        if ($flag) {
            $max = $this->params->int('max_per_day', 20);
            $candidate = QueryCandidate::for($context->root, sprintf('%d interviews réalisées par l\'agent le %s (maximum %d)', $flag['count'], $flag['date'], $max), Map::var('binome'), $flag['count'], "≤ {$max} par jour");
            $candidate->discriminator = $flag['date'];

            yield $candidate;
        }
    }
}
