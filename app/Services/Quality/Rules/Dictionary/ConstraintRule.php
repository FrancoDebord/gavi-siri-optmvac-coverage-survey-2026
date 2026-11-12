<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\Expression\ExpressionException;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

class ConstraintRule extends DictionaryRule
{
    protected function applies(array $variable, SubmissionContext $context): bool
    {
        return ! empty($variable['constraint']) && $context->dictionary->isInput($variable);
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (self::isEmpty($value) || $context->isRelevant($scope, $name) === false) {
            return null;
        }
        try {
            if ($context->evaluator($scope, $value)->evaluateBool($variable['constraint'])) {
                return null;
            }
        } catch (ExpressionException) {
            return null;
        }

        $message = $variable['constraint_message'] ?: 'Contrainte « '.$variable['constraint'].' » non respectée';

        return QueryCandidate::for($scope, $scope->label().' – '.self::label($variable).' : '.$message, $name, $value, $variable['constraint']);
    }
}
