<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Models\QualityRule;
use App\Services\Quality\QualityRuleInterface;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * Base des règles générées depuis le dictionnaire : chaque variable est contrôlée
 * dans chacune des instances de la portée où elle vit (ménage, caregiver, enfant, repeat).
 */
abstract class DictionaryRule implements QualityRuleInterface
{
    public function evaluate(SubmissionContext $context, QualityRule $rule): iterable
    {
        $byRepeat = [];
        foreach ($context->dictionary->variables() as $name => $variable) {
            if ($this->applies($variable, $context)) {
                $byRepeat[$variable['repeat_name'] ?? ''][$name] = $variable;
            }
        }

        foreach ($byRepeat as $repeat => $variables) {
            foreach ($context->scopesForRepeat($repeat === '' ? null : $repeat) as $scope) {
                foreach ($variables as $name => $variable) {
                    $candidate = $this->check($context, $scope, $name, $variable, $scope->own($name));
                    if ($candidate) {
                        yield $candidate;
                    }
                }
            }
        }
    }

    abstract protected function applies(array $variable, SubmissionContext $context): bool;

    abstract protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate;

    protected static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected static function label(array $variable): string
    {
        return $variable['label'] ? strip_tags((string) $variable['label']) : $variable['name'];
    }
}
