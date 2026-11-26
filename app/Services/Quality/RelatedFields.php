<?php

namespace App\Services\Quality;

use App\Models\DataQuery;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\Rules\Business\DoseSeriesOrderRule;
use App\Services\Quality\Rules\Business\MalariaThreeDosesReviewRule;
use App\Services\Quality\Rules\Business\RecallDoseAgeRule;
use App\Services\Quality\Rules\Business\RecallDoseSeriesOrderRule;
use App\Services\Quality\Rules\Business\RosterYoungCaregiverRule;
use App\Services\Quality\Rules\Business\SmcCycleOrderRule;
use App\Services\Quality\Rules\Business\SmcDeclarationRule;
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
        if ($query->rule_code === 'MAJ_10') {
            return self::rosterAges($clean);
        }
        if ($query->rule_code === 'MAJ_11') {
            return self::youngCaregiver($query, $clean);
        }
        if ($query->rule_code === 'MAJ_18') {
            return self::childAgeSource($query, $clean);
        }
        $variables = match ($query->rule_code) {
            'VAC_DOSE_ORDER', 'VAC_DOSE_INTERVAL' => [self::previousDose($query, $clean)],
            'VAC_BEFORE_DOB', 'VAC_MIN_AGE' => [self::dobVariable($query, $clean)],
            // VAP 4 trop proche de VAP 3 : l'une ou l'autre date peut être fausse.
            'MAJ_29' => ['mal3date', 'mal4date'],
            'ELIG_AGE_DOB' => [Map::var('age_annees'), self::dobVariable($query, $clean)],
            // Dose reçue sans la précédente : la dose précédente (et les autres doses en cause s'il y en a plusieurs).
            'MAJ_26' => array_merge(...array_map(fn (array $gap) => [$gap['previous'], $gap['dose']],
                DoseSeriesOrderRule::gaps(fn ($v) => self::value($clean, $v, $query->instance_path)))),
            'MAJ_31' => array_merge(...array_map(fn (array $gap) => [$gap['previous'], $gap['dose']],
                RecallDoseSeriesOrderRule::gaps(fn ($v) => self::value($clean, $v, $query->instance_path)))),
            // Toutes les dates de cycles CPS en cause (pas seulement celle de la query).
            // CPS non déclarée mais cycles cochés sur la carte : chaque cycle coché peut être en cause.
            'MAJ_41' => array_map(fn ($k) => "treated_{$k}", SmcDeclarationRule::cardCycles(fn ($v) => self::value($clean, $v, $query->instance_path))),
            // Cycles trop rapprochés : toutes les dates saisies peuvent être en cause.
            'MAJ_38' => array_map(fn ($k) => "date_{$k}", array_keys(SmcCycleOrderRule::dates(fn ($v) => self::value($clean, $v, $query->instance_path)))),
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

    /**
     * MAJ-11 (membre de moins de 15 ans noté caregiver) : tranche d'âge du membre et âge du caregiver interrogé
     * correspondant, en plus de la réponse « caregiver » (variable de la query).
     *
     * @return list<array{variable: string, label: string, path: string}>
     */
    private static function youngCaregiver(DataQuery $query, NormalizedSubmission $clean): array
    {
        $tables = (new DatasetExporter)->flatten($clean);
        $roster = array_values(array_filter($tables[Map::var('repeat_membres')] ?? [], fn ($r) => ! str_contains((string) $r['path'], '/')));
        $caregivers = array_values(array_filter($tables[Map::var('repeat_caregivers')] ?? [], fn ($r) => ! str_contains((string) $r['path'], '/')));
        $path = (string) $query->instance_path;
        $position = preg_match('/\[(\d+)\]$/', $path, $m) ? $m[1] : '?';
        $fields = [['variable' => 'age_group', 'label' => "Membre n°{$position} – tranche d'âge", 'path' => $path]];
        if ($caregiver = RosterYoungCaregiverRule::matchCaregiver($roster, $caregivers, $path)) {
            $cg = preg_match('/\[(\d+)\]$/', $caregiver['path'], $m) ? $m[1] : '?';
            $fields[] = ['variable' => 'age_years', 'label' => "Caregiver n°{$cg} – âge (années)", 'path' => $caregiver['path']];
        }

        return $fields;
    }

    /**
     * MAJ-10 (aucun membre de 15 ans ou plus) : tranche d'âge cochée de chaque membre, avec ses initiales et son sexe.
     *
     * @return list<array{variable: string, label: string, path: string}>
     */
    private static function rosterAges(NormalizedSubmission $clean): array
    {
        $roster = Map::var('repeat_membres');
        $dictionary = Dictionary::current();
        $fields = [];
        foreach ((new DatasetExporter)->flatten($clean)[$roster] ?? [] as $row) {
            $path = (string) $row['path'];
            if (str_contains($path, '/')) {
                continue;
            }
            $data = $row['data'] ?? [];
            $sex = trim(strip_tags((string) $dictionary->choiceLabel('sex', (string) ($data['person_sex'] ?? ''))));
            $who = trim(($data['person_initials'] ?? '').($sex !== '' ? ' ('.mb_strtoupper(mb_substr($sex, 0, 1)).')' : ''));
            $position = preg_match('/\[(\d+)\]$/', $path, $m) ? $m[1] : '?';
            $fields[] = ['variable' => 'age_group', 'label' => "Membre n°{$position}".($who !== '' ? " {$who}" : '').' – tranche d\'âge', 'path' => $path];
        }

        return $fields;
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
        if (preg_match('/^treated_(\d)$/', $variable, $m)) {
            return 'Cycle CPS '.$m[1].' traité (carte)';
        }
        if ($variable === 'child_got_smc') {
            return 'CPS déclarée par le caregiver';
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

    /**
     * MAJ-18 (enfant de 5 ans ou plus) : la ou les questions d'où vient l'âge calculé, comme dans le formulaire —
     * date de naissance documentée, sinon date déclarée par le caregiver, sinon âge déclaré (années et mois).
     *
     * @return list<array{variable: string, label: string, path: ?string}>
     */
    private static function childAgeSource(DataQuery $query, NormalizedSubmission $clean): array
    {
        $path = $query->instance_path;
        $variables = match (true) {
            (string) self::value($clean, 'child_dob_documented', $path) === '1' => [Map::var('date_naissance_enfant')],
            (string) self::value($clean, 'child_dob_caregiver_known', $path) === '1' => [Map::var('date_naissance_declaree')],
            default => [Map::var('age_annees'), Map::var('age_mois')],
        };
        $dictionary = Dictionary::current();

        return array_values(array_map(fn ($variable) => ['variable' => $variable, 'label' => self::label($variable), 'path' => $path],
            array_filter($variables, fn ($variable) => $variable && ($definition = $dictionary->variable($variable)) && $dictionary->isInput($definition))));
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
