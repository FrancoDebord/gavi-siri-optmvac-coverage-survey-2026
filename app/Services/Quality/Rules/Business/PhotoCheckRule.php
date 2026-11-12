<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\PhotoCheck;
use App\Models\QualityRule;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;
use Carbon\CarbonImmutable;

/**
 * Saisie différente de la valeur lue sur la photo (table photo_checks).
 * La query se ferme automatiquement dès que la saisie corrigée correspond à la photo ;
 * une vérification sans valeur attendue reste ouverte jusqu'à sa clôture manuelle.
 */
class PhotoCheckRule extends BusinessRule implements PreparesDataset
{
    /** @var array<string, list<PhotoCheck>> */
    private array $checks = [];

    public function prepare(QualityRule $rule): void
    {
        $this->checks = PhotoCheck::orderBy('id')->get()->groupBy('submission_uuid')->map->all()->all();
    }

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($this->checks[$context->household->submission_uuid] ?? [] as $check) {
            $scope = $check->instance_path
                ? collect($context->scopes())->firstWhere('path', $check->instance_path)
                : $context->root;
            if (! $scope) {
                yield new QueryCandidate($check->message.' (instance '.$check->instance_path.' introuvable dans la soumission)', $check->variable, null,
                    self::display($check->expected), $context->root, 'photo'.$check->id, $check->suggestion);

                continue;
            }

            $value = $check->variable ? $scope->own($check->variable) : null;
            if ($check->expected !== null && self::normalize($value) === self::normalize($check->expected)) {
                continue;
            }

            yield new QueryCandidate(($scope === $context->root ? '' : $scope->label().' : ').$check->message,
                $check->variable, self::asDate($value)?->format('d/m/Y') ?? $value, self::display($check->expected), $scope, 'photo'.$check->id, $check->suggestion);
        }
    }

    private static function normalize(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        return self::asDate($value)?->toDateString() ?? trim((string) $value);
    }

    private static function display(?string $expected): ?string
    {
        return match (true) {
            $expected === null => null,
            $expected === '' => 'vide',
            default => self::asDate($expected)?->format('d/m/Y') ?? $expected,
        };
    }

    /** Date ISO (2025-11-03, 2025-11-03T00:00…) ; les autres valeurs (« 1 », « 0 ») ne sont pas des dates. */
    private static function asDate(mixed $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) ? SubmissionProcessor::date($value) : null;
    }
}
