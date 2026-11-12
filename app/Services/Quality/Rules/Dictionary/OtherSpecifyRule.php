<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * Champs texte « préciser » non obligatoires, conditionnés par un choix « autre » :
 * détectés automatiquement à partir d'un relevant de la forme
 * ${x}='96' ou selected(${x}, '96') où x est une question à choix.
 */
class OtherSpecifyRule extends DictionaryRule
{
    protected function applies(array $variable, SubmissionContext $context): bool
    {
        if ($variable['base_type'] !== 'text' || $variable['required'] || ! $variable['relevant']) {
            return false;
        }
        $trigger = self::trigger($variable['relevant']);

        return $trigger !== null && str_starts_with($context->dictionary->variable($trigger[0])['base_type'] ?? '', 'select_');
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (! self::isEmpty($value) || $context->isRelevant($scope, $name) !== true) {
            return null;
        }
        [$parent, $option] = self::trigger($variable['relevant']);

        return QueryCandidate::for(
            $scope,
            $scope->label().' – option « '.$option.' » choisie pour '.$parent.' mais la précision est vide : '.self::label($variable),
            $name,
            null,
            'Texte précisant la réponse « autre »',
        );
    }

    /** @return array{0: string, 1: string}|null [variable déclencheuse, valeur] */
    public static function trigger(string $relevant): ?array
    {
        $relevant = trim($relevant);
        if (preg_match('/^\(?\s*\$\{(\w+)\}\s*=\s*[\'"]?([\w-]+)[\'"]?\s*\)?$/', $relevant, $m)) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/^selected\(\s*\$\{(\w+)\}\s*,\s*[\'"]([\w-]+)[\'"]\s*\)$/', $relevant, $m)) {
            return [$m[1], $m[2]];
        }

        return null;
    }
}
