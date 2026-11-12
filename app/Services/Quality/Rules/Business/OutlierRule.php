<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\Dictionary;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QualityRuleParams;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Valeurs aberrantes des variables numériques, par grappe ou par agent :
 * méthode IQR (hors [Q1 − k·IQR ; Q3 + k·IQR]) ou z-score (|z| > seuil).
 * Variables du ménage ou du repeat caregivers.
 */
class OutlierRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, list<array{path: string, variable: string, value: float, low: float, high: float}>> */
    private array $outliers = [];

    public function prepare(QualityRule $rule): void
    {
        $params = QualityRuleParams::of($rule);
        $variables = $params->list('variables') ?: array_filter(array_map('trim', explode(',', (string) $rule->variables)));
        $groupColumn = $params->get('group_by') === 'cluster' ? 'cluster_id' : 'collector_id';
        $dictionary = Dictionary::current();
        $this->outliers = [];

        // variable => groupe => liste de [household_id, chemin, valeur]
        $values = [];
        Household::with('caregivers:id,household_id,position,data')
            ->select(['id', $groupColumn, 'data'])
            ->chunkById(500, function ($households) use (&$values, $variables, $groupColumn, $dictionary) {
                foreach ($households as $household) {
                    $group = $household->{$groupColumn} ?? 0;
                    foreach ($variables as $variable) {
                        $repeat = $dictionary->variable($variable)['repeat_name'] ?? null;
                        if ($repeat === null) {
                            $value = $household->data[$variable] ?? null;
                            if (is_numeric($value)) {
                                $values[$variable][$group][] = [$household->id, '', (float) $value];
                            }

                            continue;
                        }
                        foreach ($household->caregivers as $caregiver) {
                            $value = $caregiver->data[$variable] ?? null;
                            if (is_numeric($value)) {
                                $values[$variable][$group][] = [$household->id, $repeat.'['.$caregiver->position.']', (float) $value];
                            }
                        }
                    }
                }
            });

        $minSize = $params->int('min_group_size', 8);
        foreach ($values as $variable => $groups) {
            foreach ($groups as $points) {
                if (count($points) < $minSize) {
                    continue;
                }
                [$low, $high] = $params->get('method') === 'zscore'
                    ? self::zBounds(array_column($points, 2), $params->float('z_threshold', 3))
                    : self::iqrBounds(array_column($points, 2), $params->float('iqr_k', 3));
                if ($high <= $low) {
                    continue; // aucune dispersion (ex. presque toutes les valeurs à 1) : pas de seuil exploitable
                }
                foreach ($points as [$householdId, $path, $value]) {
                    if ($value < $low || $value > $high) {
                        $this->outliers[$householdId][] = compact('path', 'variable', 'value', 'low', 'high');
                    }
                }
            }
        }
    }

    protected function check(SubmissionContext $context): iterable
    {
        foreach ($this->outliers[$context->household->id] ?? [] as $outlier) {
            $scope = $context->root;
            foreach ($context->scopes() as $candidateScope) {
                if ($candidateScope->path === $outlier['path']) {
                    $scope = $candidateScope;
                }
            }
            $groupLabel = $this->params->get('group_by') === 'cluster' ? 'des valeurs de la grappe' : 'des valeurs de l\'agent';

            yield QueryCandidate::for(
                $scope,
                sprintf('%s : valeur aberrante pour %s (%s) au regard %s', $scope->label(), $outlier['variable'], self::num($outlier['value']), $groupLabel),
                $outlier['variable'],
                self::num($outlier['value']),
                sprintf('%s à %s', self::num(max(0, $outlier['low'])), self::num($outlier['high'])),
            );
        }
    }

    /** @return array{0: float, 1: float} */
    public static function iqrBounds(array $values, float $k): array
    {
        sort($values);
        $q1 = self::quantile($values, 0.25);
        $q3 = self::quantile($values, 0.75);
        $iqr = $q3 - $q1;

        return [$q1 - $k * $iqr, $q3 + $k * $iqr];
    }

    /** @return array{0: float, 1: float} */
    public static function zBounds(array $values, float $threshold): array
    {
        $mean = array_sum($values) / count($values);
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / max(1, count($values) - 1));

        return $sd == 0 ? [$mean, $mean] : [$mean - $threshold * $sd, $mean + $threshold * $sd];
    }

    private static function quantile(array $sorted, float $q): float
    {
        $position = (count($sorted) - 1) * $q;
        $base = (int) floor($position);
        $rest = $position - $base;

        return isset($sorted[$base + 1]) ? $sorted[$base] + $rest * ($sorted[$base + 1] - $sorted[$base]) : $sorted[$base];
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }
}
