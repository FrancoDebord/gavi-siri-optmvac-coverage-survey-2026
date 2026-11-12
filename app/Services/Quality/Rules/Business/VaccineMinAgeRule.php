<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Dose administrée avant l'âge minimal du calendrier vaccinal (config gavi.vaccines.*.min_age_days),
 * ou du calendrier propre à la zone sanitaire du ménage (config gavi.vaccine_min_age_by_zone).
 */
class VaccineMinAgeRule extends BusinessRule implements PreparesDataset
{
    /** @var array<int, string> id zone => code zone */
    private array $zoneCodes = [];

    public function prepare(QualityRule $rule): void
    {
        $this->zoneCodes = HealthZone::pluck('code', 'id')->all();
    }

    protected function check(SubmissionContext $context): iterable
    {
        $tolerance = $this->params->int('tolerance_days', 0);
        $zoneCode = $this->zoneCodes[$context->household->health_zone_id] ?? null;
        $zoneSchedule = $zoneCode !== null ? config("gavi.vaccine_min_age_by_zone.{$zoneCode}", []) : [];

        foreach ($context->scopes('child') as $child) {
            $dob = $context->childDob($child);
            if (! $dob) {
                continue;
            }
            foreach ($context->vaccineDates($child) as $code => $date) {
                $minimum = (int) ($zoneSchedule[$code] ?? config("gavi.vaccines.{$code}.min_age_days", 0));
                $age = (int) $dob->diffInDays($date, false);
                if ($minimum <= 0 || $age < 0 || $age >= $minimum - $tolerance) {
                    continue; // une date avant la naissance relève de VAC_BEFORE_DOB
                }

                yield QueryCandidate::for(
                    $child,
                    sprintf('%s : %s reçu à %d jour(s) de vie (%s), âge minimal %d jours%s', $child->label(), self::vaccineLabel($code), $age, self::fmt($date), $minimum,
                        isset($zoneSchedule[$code]) ? ' (calendrier de la zone sanitaire)' : ''),
                    Map::vaccineDate($code),
                    "{$age} jours",
                    "≥ {$minimum} jours",
                );
            }
        }
    }
}
