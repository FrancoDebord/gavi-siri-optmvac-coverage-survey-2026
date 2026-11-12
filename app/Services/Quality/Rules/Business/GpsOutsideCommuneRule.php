<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QualityRuleParams;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Approximation « hors commune » sans limites administratives : point situé au-delà
 * d'un rayon autour de la médiane des points de la commune.
 */
class GpsOutsideCommuneRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, array{0: float, 1: float}> */
    private array $centroids = [];

    public function prepare(QualityRule $rule): void
    {
        $minPoints = QualityRuleParams::of($rule)->int('min_points', 5);
        $this->centroids = Household::whereNotNull('latitude')->whereNotNull('commune_id')
            ->get(['commune_id', 'latitude', 'longitude'])
            ->groupBy('commune_id')
            ->filter(fn ($points) => $points->count() >= $minPoints)
            ->map(fn ($points) => [self::median($points->pluck('latitude')->all()), self::median($points->pluck('longitude')->all())])
            ->all();
    }

    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        $centroid = $this->centroids[$household->commune_id] ?? null;
        if (! $centroid || $household->latitude === null) {
            return;
        }
        $radius = $this->params->float('radius_km', 50);
        $distance = self::distanceKm($household->latitude, $household->longitude, $centroid[0], $centroid[1]);
        if ($distance > $radius) {
            yield QueryCandidate::for($context->root, sprintf('Point GPS à %.1f km du centre de la commune (rayon %.0f km) : probablement hors commune', $distance, $radius), Map::var('gps'), sprintf('%.1f km', $distance), "≤ {$radius} km");
        }
    }
}
