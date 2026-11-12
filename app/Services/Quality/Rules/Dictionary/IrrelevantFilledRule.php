<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * Valeur présente alors que la question n'aurait pas dû être posée (saut non respecté).
 * Si c'est la condition d'un groupe parent qui est fausse, une seule query est ouverte
 * pour le groupe (et non une par variable du groupe).
 */
class IrrelevantFilledRule extends DictionaryRule
{
    protected function applies(array $variable, SubmissionContext $context): bool
    {
        return $context->dictionary->isInput($variable) && $context->dictionary->relevantChain($variable['name']) !== [];
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (self::isEmpty($value) || $context->isRelevant($scope, $name) !== false) {
            return null;
        }

        foreach ($context->dictionary->relevantChainDetailed($name) as $link) {
            if ($context->condition($scope, $link['expression']) !== false) {
                continue;
            }
            if ($link['group'] === null) {
                return QueryCandidate::for(
                    $scope,
                    $scope->label().' – '.self::label($variable).' : renseigné alors que la question ne devait pas être posée',
                    $name,
                    $value,
                    'Vide (condition : '.$link['expression'].')',
                );
            }

            // Condition de groupe fausse : query unique rattachée à la portée qui porte le groupe.
            $owner = $scope;
            while ($owner->parent && $owner->repeat !== $link['repeat']) {
                $owner = $owner->parent;
            }
            $candidate = QueryCandidate::for(
                $owner,
                ($owner->level === Scope::HOUSEHOLD ? '' : $owner->label().' – ').'section « '.$link['group'].' » renseignée alors que sa condition est fausse',
                $link['group'],
                'Exemple : '.$name.' = '.(is_array($value) ? json_encode($value) : $value),
                'Section vide (condition : '.$link['expression'].')',
            );
            $candidate->discriminator = 'group';

            return $candidate;
        }

        return null;
    }
}
