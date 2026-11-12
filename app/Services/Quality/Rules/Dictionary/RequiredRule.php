<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

class RequiredRule extends DictionaryRule
{
    protected function applies(array $variable, SubmissionContext $context): bool
    {
        return $variable['required'] && $context->dictionary->isInput($variable);
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (! self::isEmpty($value) || $context->isRelevant($scope, $name) !== true) {
            return null;
        }

        return QueryCandidate::for($scope, $scope->label().' : champ obligatoire non renseigné – '.self::label($variable), $name, null, 'Valeur requise');
    }
}
