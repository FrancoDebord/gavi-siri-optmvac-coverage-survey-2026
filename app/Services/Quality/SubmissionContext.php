<?php

namespace App\Services\Quality;

use App\Models\Household;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\Expression\ExpressionEvaluator;
use App\Services\Quality\Expression\ExpressionException;
use Carbon\CarbonImmutable;

/**
 * Soumission en cours de contrôle : arbre de portées, accès au dictionnaire,
 * évaluation mémorisée des conditions « relevant ».
 */
class SubmissionContext
{
    public readonly Scope $root;

    /** @var list<Scope> */
    private array $scopes = [];

    public function __construct(
        public readonly Household $household,
        public readonly Dictionary $dictionary,
        public readonly CarbonImmutable $today,
    ) {
        $data = $household->data ?? [];
        $otherRepeats = $data['__repeats'] ?? [];
        unset($data['__repeats']);

        $this->root = new Scope(Scope::HOUSEHOLD, null, $data);
        $this->scopes[] = $this->root;

        $caregiverRepeat = Map::var('repeat_caregivers');
        $childRepeat = Map::var('repeat_enfants');

        foreach ($household->caregivers as $caregiver) {
            $cgScope = new Scope(Scope::CAREGIVER, $caregiverRepeat, $caregiver->data ?? [], $this->root,
                "{$caregiverRepeat}[{$caregiver->position}]", $caregiver->id, null, $caregiver->position);
            $this->scopes[] = $cgScope;

            foreach ($household->children->where('caregiver_id', $caregiver->id) as $child) {
                $this->scopes[] = new Scope(Scope::CHILD, $childRepeat, $child->data ?? [], $cgScope,
                    "{$cgScope->path}/{$childRepeat}[{$child->position}]", $caregiver->id, $child->id, $child->position);
            }
        }
        foreach ($household->children->whereNull('caregiver_id') as $child) {
            $this->scopes[] = new Scope(Scope::CHILD, $childRepeat, $child->data ?? [], $this->root,
                "{$childRepeat}[{$child->position}]", null, $child->id, $child->position);
        }

        $this->addRepeatScopes($otherRepeats, $this->root);
    }

    private function addRepeatScopes(array $repeats, Scope $parent): void
    {
        foreach ($repeats as $name => $instances) {
            foreach ($instances as $i => $instance) {
                $scope = new Scope(Scope::REPEAT, $name, $instance['data'] ?? [], $parent,
                    ltrim($parent->path.'/'.$name.'['.($i + 1).']', '/'), position: $i + 1);
                $this->scopes[] = $scope;
                $this->addRepeatScopes($instance['repeats'] ?? [], $scope);
            }
        }
    }

    /** @return list<Scope> */
    public function scopes(?string $level = null): array
    {
        return $level === null ? $this->scopes : array_values(array_filter($this->scopes, fn (Scope $s) => $s->level === $level));
    }

    /** Portées dans lesquelles vivent les variables d'un repeat donné (null = racine). */
    public function scopesForRepeat(?string $repeat): array
    {
        return array_values(array_filter($this->scopes, fn (Scope $s) => $s->repeat === $repeat));
    }

    public function evaluator(Scope $scope, mixed $self = null): ExpressionEvaluator
    {
        return new ExpressionEvaluator(fn (string $name) => $scope->get($name), $self, $this->today);
    }

    /**
     * La variable est-elle affichée (relevant de ses groupes et d'elle-même vrais) ?
     * null si une condition ne peut pas être évaluée (fonction non prise en charge).
     */
    public function isRelevant(Scope $scope, string $variable): ?bool
    {
        $key = 'rel:'.$variable;
        if (array_key_exists($key, $scope->memo)) {
            return $scope->memo[$key];
        }

        $result = true;
        foreach ($this->dictionary->relevantChain($variable) as $expression) {
            $condition = $this->condition($scope, $expression);
            if ($condition === null) {
                $result = null;

                break;
            }
            if (! $condition) {
                $result = false;

                break;
            }
        }

        return $scope->memo[$key] = $result;
    }

    /** Évalue une condition (mémorisée par portée) ; null si non évaluable. */
    public function condition(Scope $scope, string $expression): ?bool
    {
        $key = 'cond:'.$expression;
        if (array_key_exists($key, $scope->memo)) {
            return $scope->memo[$key];
        }
        try {
            $result = $this->evaluator($scope)->evaluateBool($expression);
        } catch (ExpressionException) {
            $result = null;
        }

        return $scope->memo[$key] = $result;
    }

    public function interviewDate(): ?CarbonImmutable
    {
        return $this->household->interview_date ? CarbonImmutable::parse($this->household->interview_date)->startOfDay() : null;
    }

    /** Date de naissance de l'enfant (documentée, sinon déclarée). */
    public function childDob(Scope $child): ?CarbonImmutable
    {
        return SubmissionProcessor::date($child->own(Map::var('date_naissance_enfant') ?? ''))
            ?? SubmissionProcessor::date($child->own(Map::var('date_naissance_declaree') ?? ''));
    }

    /** @return array{0: int, 1: int} tranche d'âge éligible (mois) */
    public function ageRange(QualityRuleParams $params): array
    {
        $low = $this->root->get(Map::var('age_min_mois') ?? '');
        $high = $this->root->get(Map::var('age_max_mois') ?? '');

        return [
            is_numeric($low) ? (int) $low : (int) $params->get('age_min_months', 12),
            is_numeric($high) ? (int) $high : (int) $params->get('age_max_months', 35),
        ];
    }

    /**
     * Dates de vaccination saisies pour un enfant.
     *
     * @return array<string, CarbonImmutable> code antigène => date
     */
    public function vaccineDates(Scope $child): array
    {
        $dates = [];
        foreach (array_keys(config('gavi.vaccines')) as $code) {
            $variable = Map::vaccineDate($code);
            if ($variable && ($date = SubmissionProcessor::date($child->own($variable)))) {
                $dates[$code] = $date;
            }
        }

        return $dates;
    }
}
