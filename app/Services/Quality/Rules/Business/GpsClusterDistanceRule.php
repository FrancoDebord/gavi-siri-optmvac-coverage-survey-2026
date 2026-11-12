<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Cluster;
use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QualityRuleParams;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Distance au centroïde de la grappe : coordonnées importées avec la liste des grappes,
 * sinon médiane des points GPS de la grappe (au moins min_points points).
 */
class GpsClusterDistanceRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, array{0: float, 1: float, 2: string}> */
    private array $centroids = [];

    public function prepare(QualityRule $rule): void
    {
        $params = QualityRuleParams::of($rule);
        $this->centroids = [];

        foreach (Cluster::whereNotNull('latitude')->whereNotNull('longitude')->get(['id', 'latitude', 'longitude']) as $cluster) {
            $this->centroids[$cluster->id] = [$cluster->latitude, $cluster->longitude, 'coordonnées de la grappe'];
        }

        Household::whereNotNull('latitude')->whereNotNull('cluster_id')
            ->get(['cluster_id', 'latitude', 'longitude'])
            ->groupBy('cluster_id')
            ->each(function ($points, $clusterId) use ($params) {
                if (! isset($this->centroids[$clusterId]) && $points->count() >= $params->int('min_points', 3)) {
                    $this->centroids[$clusterId] = [self::median($points->pluck('latitude')->all()), self::median($points->pluck('longitude')->all()), 'médiane des points'];
                }
            });
    }

    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        $centroid = $this->centroids[$household->cluster_id] ?? null;
        if (! $centroid || $household->latitude === null) {
            return;
        }
        $max = $this->params->float('max_distance_km', 5);
        $distance = self::distanceKm($household->latitude, $household->longitude, $centroid[0], $centroid[1]);
        if ($distance > $max) {
            yield QueryCandidate::for($context->root, sprintf('Ménage à %.1f km du centre de la grappe (%s), seuil %.1f km', $distance, $centroid[2], $max), Map::var('gps'), sprintf('%.1f km', $distance), "≤ {$max} km");
        }
    }
}
