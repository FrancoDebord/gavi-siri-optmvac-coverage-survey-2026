<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\Household;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/** CRI-20 : même point GPS (5 décimales) pour deux ménages différents. */
class DuplicateGpsRule extends BusinessRule implements PreparesDataset
{
    /** @var array<string, array<string, true>> point => identifiants ménage */
    private array $points = [];

    public function prepare(QualityRule $rule): void
    {
        $this->points = [];
        Household::whereNotNull('latitude')->whereNotNull('longitude')->whereNotNull('household_code')
            ->get(['household_code', 'latitude', 'longitude'])
            ->each(function ($h) {
                $this->points[self::key($h->latitude, $h->longitude)][trim((string) $h->household_code)] = true;
            });
    }

    private static function key(mixed $lat, mixed $lon): string
    {
        return sprintf('%.5f,%.5f', (float) $lat, (float) $lon);
    }

    protected function check(SubmissionContext $context): iterable
    {
        $household = $context->household;
        if ($household->latitude === null || $household->longitude === null || ! $household->household_code) {
            return;
        }
        $key = self::key($household->latitude, $household->longitude);
        $others = array_values(array_diff(array_keys($this->points[$key] ?? []), [trim((string) $household->household_code)]));
        if ($others !== []) {
            yield QueryCandidate::for($context->root, 'Même point GPS que le(s) ménage(s) '.implode(', ', array_slice($others, 0, 3)).(count($others) > 3 ? '…' : '').'.',
                Map::var('gps'), $key, 'Point propre au ménage');
        }
    }
}
