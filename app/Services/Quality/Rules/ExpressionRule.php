<?php

namespace App\Services\Quality\Rules;

use App\Models\QualityRule;
use App\Services\Quality\Expression\ExpressionException;
use App\Services\Quality\QualityRuleInterface;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Règle personnalisée écrite en expression XLSForm. L'expression décrit la
 * condition d'ERREUR : une query est ouverte quand elle est vraie, dans chaque
 * instance de la portée choisie (ménage, caregiver ou enfant).
 * Exemple : ${num_bednets} > ${num_slept_last_night} * 2
 */
class ExpressionRule implements QualityRuleInterface
{
    public function evaluate(SubmissionContext $context, QualityRule $rule): iterable
    {
        if (! $rule->expression) {
            return;
        }
        $variables = array_values(array_filter(array_map('trim', explode(',', (string) $rule->variables))));
        $variable = $variables[0] ?? null;

        foreach ($context->scopes($rule->scope ?: 'household') as $scope) {
            try {
                if (! $context->evaluator($scope, $variable ? $scope->get($variable) : null)->evaluateBool($rule->expression)) {
                    continue;
                }
            } catch (ExpressionException) {
                continue;
            }

            $observed = $variables === [] ? null : implode(' ; ', array_map(fn ($v) => $v.' = '.(is_array($scope->get($v)) ? json_encode($scope->get($v)) : ($scope->get($v) ?? '∅')), $variables));

            yield QueryCandidate::for($scope, ($scope->level === 'household' ? '' : $scope->label().' : ').($rule->message ?: $rule->name), $variable, $observed);
        }
    }
}
