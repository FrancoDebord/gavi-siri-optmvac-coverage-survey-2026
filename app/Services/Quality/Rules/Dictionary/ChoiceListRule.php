<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;
use App\Support\MultipleChoice;

class ChoiceListRule extends DictionaryRule
{
    protected function applies(array $variable, SubmissionContext $context): bool
    {
        return str_starts_with($variable['base_type'], 'select_')
            && $variable['list_name']
            && $context->dictionary->choices($variable['list_name']) !== null;
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (self::isEmpty($value)) {
            return null;
        }
        $choices = $context->dictionary->choices($variable['list_name']);
        if (array_key_exists(trim((string) $value), $choices)) {
            return null; // nom de choix contenant des espaces (ex. « opv - bj »)
        }
        // Choix multiple : plusieurs codes cochés (« rr-bj opv-bj »), un code peut s'écrire « opv - bj » dans la liste.
        $values = str_starts_with($variable['base_type'], 'select_multiple')
            ? MultipleChoice::split($value, $choices)
            : [trim((string) $value)];

        $invalid = array_values(array_filter($values, fn ($v) => ! array_key_exists($v, $choices)));
        if ($invalid === []) {
            return null;
        }

        return QueryCandidate::for(
            $scope,
            $scope->label().' – '.self::label($variable).' : valeur(s) '.implode(', ', $invalid).' absente(s) de la liste « '.$variable['list_name'].' »',
            $name,
            $value,
            implode(', ', array_slice(array_keys($choices), 0, 30)).(count($choices) > 30 ? '…' : ''),
        );
    }
}
