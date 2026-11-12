<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QualityRuleInterface;
use App\Services\Quality\QualityRuleParams;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;
use Carbon\CarbonImmutable;

abstract class BusinessRule implements QualityRuleInterface
{
    protected QualityRuleParams $params;

    public function evaluate(SubmissionContext $context, QualityRule $rule): iterable
    {
        $this->params = QualityRuleParams::of($rule);

        return $this->check($context);
    }

    /** @return iterable<QueryCandidate> */
    abstract protected function check(SubmissionContext $context): iterable;

    protected static function fmt(?CarbonImmutable $date): string
    {
        return $date?->format('d/m/Y') ?? '—';
    }

    protected static function vaccineLabel(string $code): string
    {
        return config("gavi.vaccines.{$code}.label", $code);
    }

    protected static function yes(mixed $value): bool
    {
        return (string) $value === (string) config('gavi.yes_value');
    }

    protected static function no(mixed $value): bool
    {
        return (string) $value === (string) config('gavi.no_value');
    }

    /** L'enfant a-t-il des données de la section vaccination (= enrôlé) ? */
    protected static function enrolled(Scope $child): bool
    {
        $variable = Map::var('recu_vaccin');

        return $variable && $child->own($variable) !== null && $child->own($variable) !== '';
    }

    protected static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    protected static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
