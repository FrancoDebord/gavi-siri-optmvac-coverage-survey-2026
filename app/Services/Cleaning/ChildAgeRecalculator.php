<?php

namespace App\Services\Cleaning;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use InvalidArgumentException;

/**
 * Âge de l'enfant recalculé comme dans le formulaire, quand une question qui le détermine est corrigée (date de
 * naissance documentée ou déclarée, âge déclaré en années et mois) : sans cela, l'âge calculé (age_months_est) garde
 * l'ancienne valeur et les contrôles sur l'âge (MAJ_18, éligibilité…) ne voient pas la correction.
 *
 *   calc_dob_months      = mois entre la date de naissance documentée et le jour de l'enquête
 *   calc_reported_months = idem avec la date de naissance déclarée par le caregiver
 *   child_age_reported   = années × 12 + mois (âge déclaré)
 *   age_months_est       = date documentée si « document » = oui, sinon date déclarée si connue, sinon âge déclaré
 * Un mois = 30,4375 jours, partie entière (int(…) du formulaire).
 */
final class ChildAgeRecalculator
{
    private const DAYS_PER_MONTH = 30.4375;

    /** Questions qui déterminent l'âge de l'enfant. */
    public static function inputs(): array
    {
        return array_values(array_filter([
            Map::var('date_naissance_enfant'), Map::var('date_naissance_declaree'), Map::var('age_annees'), Map::var('age_mois'),
            'child_dob_documented', 'child_dob_caregiver_known',
        ]));
    }

    /**
     * Valeurs calculées de l'enfant ($path) avec les nouvelles valeurs $overrides (variable => valeur).
     *
     * @param  array<string, ?string>  $overrides
     * @return array<string, ?string> variable calculée => valeur (texte, ou null si non calculable)
     */
    public static function derived(NormalizedSubmission $clean, ?string $path, array $overrides): array
    {
        $get = function (?string $variable) use ($clean, $path, $overrides) {
            if (! $variable) {
                return null;
            }
            if (array_key_exists($variable, $overrides)) {
                return $overrides[$variable];
            }
            try {
                return SubmissionPaths::get($clean, $variable, $path);
            } catch (InvalidArgumentException) {
                return null;
            }
        };
        $today = SubmissionProcessor::date($clean->data[Map::var('today') ?? 'today'] ?? null);
        $months = function (?string $date) use ($today): ?int {
            $dob = SubmissionProcessor::date($date);

            return $dob && $today ? (int) floor($dob->startOfDay()->diffInDays($today->startOfDay(), false) / self::DAYS_PER_MONTH) : null;
        };

        $dobMonths = $months($get(Map::var('date_naissance_enfant')));
        $reportedMonths = $months($get(Map::var('date_naissance_declaree')));
        $years = $get(Map::var('age_annees'));
        $monthsDeclared = $get(Map::var('age_mois'));
        $declared = (is_numeric($years) ? (int) $years : 0) * 12 + (is_numeric($monthsDeclared) ? (int) $monthsDeclared : 0);
        $estimated = match (true) {
            (string) $get('child_dob_documented') === '1' => $dobMonths,
            (string) $get('child_dob_caregiver_known') === '1' => $reportedMonths,
            default => $declared,
        };
        $text = fn (?int $value) => $value === null ? null : (string) $value;

        return array_filter([
            'calc_dob_months' => $text($dobMonths),
            'calc_reported_months' => $text($reportedMonths),
            'child_age_reported' => (string) $declared,
            Map::var('age_mois_estime') ?? 'age_months_est' => $text($estimated),
        ], fn ($value, $variable) => $variable !== '', ARRAY_FILTER_USE_BOTH);
    }
}
