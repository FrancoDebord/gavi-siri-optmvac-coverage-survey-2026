<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Child;
use App\Models\QualityRule;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** Même enfant (grappe, ménage, initiales du caregiver, sexe, date de naissance) saisi plusieurs fois. */
class DuplicateChildRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, int> child_id => nombre d'occurrences */
    private array $counts = [];

    public function prepare(QualityRule $rule): void
    {
        $this->counts = [];
        $children = Child::query()
            ->join('households', 'households.id', '=', 'children.household_id')
            ->leftJoin('caregivers', 'caregivers.id', '=', 'children.caregiver_id')
            ->whereNotNull('children.date_of_birth')
            ->get(['children.id', 'children.sex', 'children.date_of_birth', 'households.cluster_id', 'households.household_code', 'caregivers.initials']);

        $children->groupBy(fn ($c) => implode('|', [
            $c->cluster_id, mb_strtolower((string) $c->household_code), mb_strtolower((string) $c->initials), $c->sex, $c->date_of_birth?->toDateString(),
        ]))->filter(fn ($g) => $g->count() > 1)->each(function ($group) {
            foreach ($group as $child) {
                $this->counts[$child->id] = $group->count();
            }
        });
    }

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($context->scopes('child') as $child) {
            if (isset($this->counts[$child->childId])) {
                yield QueryCandidate::for(
                    $child,
                    $child->label().' : enfant enregistré '.$this->counts[$child->childId].' fois (même ménage, caregiver, sexe et date de naissance)',
                    null,
                    self::fmt($context->childDob($child)),
                    'Un seul enregistrement par enfant',
                );
            }
        }
    }
}
