<?php

namespace App\Services\Quality;

use App\Models\DataQuery;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\Rules\Business\MalariaThreeDosesReviewRule;
use App\Services\Quality\Rules\Business\RecallDoseAgeRule;
use App\Services\Quality\Rules\Business\SmcCycleOrderRule;
use App\Services\Export\DatasetExporter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Queries qui comparent deux valeurs (VPO 3 avant VPO 2, vaccin avant la naissance…) : l'erreur peut venir de
 * l'une ou de l'autre. Retrouve, pour le même membre, les autres questions en cause, que l'agent pourra corriger
 * à la place (ou en plus) de la question de la query.
 */
final class RelatedFields
{
    /**
     * @return list<array{variable: string, label: string, path: ?string}> questions saisissables en cause, hors celle de la query,
     *                                                                     avec leur membre (celui de la query, sauf règle sur plusieurs membres)
     */
    public static function for(DataQuery $query, NormalizedSubmission $clean): array
    {
        if (! $query->variable) {
            return [];
        }
        if ($query->rule_code === 'MAJ_09') {
            return self::rosterUnderFive($clean);
        }
        $variables = match ($query->rule_code) {
            'VAC_DOSE_ORDER', 'VAC_DOSE_INTERVAL' => [self::previousDose($query, $clean)],
            'VAC_BEFORE_DOB', 'VAC_MIN_AGE' => [self::dobVariable($query, $clean)],
            'ELIG_AGE_DOB' => [Map::var('age_annees'), self::dobVariable($query, $clean)],
            // Toutes les dates de cycles CPS en cause (pas seulement celle de la query).
            'MAJ_37' => array_map(fn ($k) => "date_{$k}", SmcCycleOrderRule::cyclesInCause(SmcCycleOrderRule::dates(fn ($v) => self::value($clean, $v, $query->instance_path)))),
            // Âge calculé : corriger la date de naissance ou la réponse au rappel.
            'MAJ_32' => [self::dobVariable($query, $clean), ...array_column(RecallDoseAgeRule::dosesInCause(self::child($query, $clean,
                ['age_months_est', 'measmth9', 'measmth15', 'penta3_rec', 'opv3_rec', 'pcv3_rec', 'rtss1_rec', 'rtss4_rec'])), 'variable')],
            // Réponses cochées pour les 3 premières doses de VAP (carte puis rappel), et la date de naissance si l'âge est en cause.
            'MAJ_34' => ['mal1', 'mal2', 'mal3', 'rtss1_rec', 'rtss2_rec', 'rtss3_rec', in_array('age_months_est', MalariaThreeDosesReviewRule::inCause(self::child($query, $clean,
                ['three_MV_doses', 'see_card', 'receive_vaccine', 'mal1', 'mal2', 'mal3', 'mal4', 'rtss3_rec', 'age_months_est'])), true) ? self::dobVariable($query, $clean) : null],
            default => [],
        };
        $dictionary = Dictionary::current();
        $fields = [];
        foreach (array_unique(array_filter($variables)) as $variable) {
            $definition = $dictionary->variable($variable);
            if ($variable === $query->variable || ! $definition || ! $dictionary->isInput($definition)) {
                continue;
            }
            $fields[] = ['variable' => $variable, 'label' => self::label($variable), 'path' => $query->instance_path];
        }

        return $fields;
    }

    /**
     * MAJ-09 (enfants déclarés > membres de moins de 5 ans) : nombre d'enfants déclaré par chaque caregiver, puis
     * tranche d'âge de chaque membre de 5 ans ou plus (un enfant mal classé). L'agent corrige celle qui est fausse.
     *
     * @return list<array{variable: string, label: string, path: string}>
     */
    private static function rosterUnderFive(NormalizedSubmission $clean): array
    {
        $caregivers = $members = [];
        $roster = Map::var('repeat_membres');
        foreach ((new DatasetExporter)->flatten($clean) as $rows) {
            foreach ($rows as $row) {
                $path = $row['path'] ?? null;
                if (! $path) {
                    continue;
                }
                $data = $row['data'] ?? [];
                $position = preg_match('/\[(\d+)\]$/', $path, $m) ? $m[1] : '?';
                if (is_numeric($data['caregiver_num_children'] ?? null) && (int) $data['caregiver_num_children'] > 0) {
                    $caregivers[] = ['variable' => 'caregiver_num_children', 'label' => "Caregiver n°{$position} – enfants de moins de 5 ans déclarés", 'path' => $path];
                }
                if ($roster && str_starts_with($path, $roster.'[') && ! str_contains($path, '/') && is_numeric($data['age_group'] ?? null) && (int) $data['age_group'] !== 0) {
                    $members[] = ['variable' => 'age_group', 'label' => "Membre n°{$position} – tranche d'âge", 'path' => $path];
                }
            }
        }

        return [...$caregivers, ...$members];
    }

    /** Libellé court d'une variable connue du mapping (ex. « VPO 2 », « Date de naissance »), sinon son nom. */
    public static function label(string $variable): string
    {
        foreach (array_keys(config('gavi.vaccines')) as $code) {
            if (Map::vaccineDate($code) === $variable) {
                return config("gavi.vaccines.{$code}.label", $code);
            }
        }
        foreach (Map::defaults() as $key => $row) {
            if (Map::var($key) === $variable) {
                return $row['label'];
            }
        }
        if (preg_match('/^date_(\d)$/', $variable, $m)) {
            return 'Date du cycle CPS '.$m[1];
        }
        // Sinon le libellé de la question, sans son numéro (« L'enfant a-t-il reçu le quatrième vaccin… »).
        $label = trim(preg_replace(['/\s+/', '/^\d+(\.\d+)?\.\s*/'], [' ', ''], strip_tags((string) (Dictionary::current()->variable($variable)['label'] ?? ''))));

        return $label !== '' ? Str::limit($label, 90) : $variable;
    }

    /** Dose précédente renseignée de la même série (ex. VPO 2 pour une query sur VPO 3). */
    private static function previousDose(DataQuery $query, NormalizedSubmission $clean): ?string
    {
        $code = collect(array_keys(config('gavi.vaccines')))->first(fn ($c) => Map::vaccineDate($c) === $query->variable);
        if (! $code) {
            return null;
        }
        foreach (config('gavi.dose_series') as $series) {
            $position = array_search($code, $series, true);
            if ($position === false) {
                continue;
            }
            // La dose précédente la plus proche qui a une date (une dose manquante est ignorée, comme dans la règle).
            for ($i = $position - 1; $i >= 0; $i--) {
                $variable = Map::vaccineDate($series[$i]);
                if ($variable && SubmissionProcessor::date(self::value($clean, $variable, $query->instance_path))) {
                    return $variable;
                }
            }
        }

        return null;
    }

    /** Date de naissance utilisée par les règles : documentée, sinon déclarée. */
    private static function dobVariable(DataQuery $query, NormalizedSubmission $clean): ?string
    {
        $documented = Map::var('date_naissance_enfant');
        if ($documented && SubmissionProcessor::date(self::value($clean, $documented, $query->instance_path))) {
            return $documented;
        }
        $declared = Map::var('date_naissance_declaree');

        return $declared && SubmissionProcessor::date(self::value($clean, $declared, $query->instance_path)) ? $declared : $documented;
    }

    /** Enfant de la query reconstitué avec les valeurs (corrigées) utiles à la règle, et la zone (l1) du ménage. */
    private static function child(DataQuery $query, NormalizedSubmission $clean, array $variables): Scope
    {
        $household = new Scope(Scope::HOUSEHOLD, null, ['l1' => $clean->data['l1'] ?? null]);

        return new Scope(Scope::CHILD, null, array_combine($variables, array_map(fn ($v) => self::value($clean, $v, $query->instance_path), $variables)), $household);
    }

    private static function value(NormalizedSubmission $clean, string $variable, ?string $path): mixed
    {
        try {
            return SubmissionPaths::get($clean, $variable, $path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
