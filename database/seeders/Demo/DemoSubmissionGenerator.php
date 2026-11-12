<?php

namespace Database\Seeders\Demo;

use App\Services\Dictionary\Dictionary;
use App\Services\Import\NormalizedSubmission;
use App\Services\Quality\Expression\ExpressionEvaluator;
use App\Services\Quality\Expression\ExpressionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Génère des soumissions fictives en « remplissant » le XLSForm : chaque question
 * n'est renseignée que si ses conditions relevant sont vraies, les contraintes
 * sont respectées et les dates de vaccination suivent le calendrier.
 * Des anomalies connues sont ensuite injectées pour alimenter le moteur de queries.
 */
class DemoSubmissionGenerator
{
    private const RANGES = [
        'age_years' => [17, 45], 'num_slept_last_night' => [4, 12], 'num_bednets' => [1, 4],
        'caregiver_num_children' => [1, 3], 'net_owned_years' => [0, 3], 'net_owned_months' => [0, 11],
        'loc_curr_amount' => [1000, 50000], 'smc_treatment_number' => [0, 4], 'years_of_koranic' => [0, 5],
        'bednet_number' => [1, 3],
    ];

    /** Antigènes proposés au Bénin (vaccines.csv) : la clé ipv du CSV ne correspond pas à ok_ipv1/ok_ipv2. */
    private const BENIN_VACCINES = [
        'bcg' => 'BCG', 'hepb' => 'HepB', 'opv0' => 'VP0', 'opv1' => 'VPO1', 'opv2' => 'VPO2', 'opv3' => 'VPO3',
        'Penta1' => 'Penta1', 'Penta2' => 'Penta2', 'Penta3' => 'Penta3', 'pcv1' => 'PCV1', 'pcv2' => 'PCV2', 'pcv3' => 'PCV3',
        'rota1' => 'Rota1', 'rota2' => 'Rota2', 'rota3' => 'Rota3', 'mal1' => 'VAP1', 'mal2' => 'VAP2', 'mal3' => 'VAP3',
        'mal4' => 'VAP4', 'MenA' => 'MenA', 'meas1' => 'RR1', 'meas2' => 'RR2', 'YF' => 'VAA',
    ];

    public const ANOMALIES = [
        'vaccine_before_birth', 'dose_order', 'short_interval', 'rr_too_early', 'status_mismatch',
        'short_interview', 'off_hours', 'gps_far', 'gps_missing', 'gps_inaccurate', 'duplicate_household',
        'missing_required', 'constraint', 'invalid_choice', 'today_mismatch', 'age_dob', 'late_submission',
        'refused_with_data', 'card_no_dates', 'too_many_bednets',
    ];

    /** @var array<string, list<array>> variables par repeat, dans l'ordre du formulaire */
    private array $byRepeat = [];

    private CarbonImmutable $today;

    public function __construct(private readonly Dictionary $dictionary)
    {
        foreach ($dictionary->variables() as $name => $variable) {
            $this->byRepeat[$variable['repeat_name'] ?? ''][$name] = $variable;
        }
    }

    /**
     * @param  array{zone: string, commune: string, arrondissement: string, village: string, collector: string, center: array{0: float, 1: float}, smc_cycles: int}  $cluster
     * @return array{submission: NormalizedSubmission, anomaly: string|null}
     */
    public function make(array $cluster, string $householdCode, CarbonImmutable $date, ?string $anomaly = null): array
    {
        $this->today = $date;
        $statusRoll = mt_rand(1, 100);
        $status = match (true) {
            $statusRoll <= 80 => '0', $statusRoll <= 86 => '1', $statusRoll <= 92 => '3', $statusRoll <= 97 => '4', default => '2',
        };
        if ($anomaly === 'refused_with_data') {
            $status = '0'; // les caregivers sont générés, le statut est changé après coup
        }

        $start = $date->setTime(mt_rand(8, 16), mt_rand(0, 59), mt_rand(0, 59));
        $duration = $status === '0' ? mt_rand(18, 65) : mt_rand(3, 9);
        $end = $start->addMinutes($duration)->addSeconds(mt_rand(0, 59));
        $submitted = $end->addMinutes(mt_rand(5, 900))->min(CarbonImmutable::now());

        $root = [
            'today' => $date->toDateString(),
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
            'country' => 'bj', 'label_l1' => 'Zone Sanitaire', 'label_l2' => 'Commune', 'label_l3' => 'Arrondissement',
            'label_l4' => '', 'has_l4' => 'no',
            'l1' => $cluster['zone'], 'l2' => $cluster['commune'], 'l3' => $cluster['arrondissement'],
            'village_parent' => $cluster['arrondissement'], 'village' => $cluster['village'],
            'smc_cycles' => (string) $cluster['smc_cycles'],
            'fieldworker' => $cluster['collector'],
            'household' => $householdCode,
            'current_location' => sprintf('%.6f %.6f %d %.1f', $cluster['center'][0] + $this->jitter(0.006), $cluster['center'][1] + $this->jitter(0.006), mt_rand(150, 350), mt_rand(30, 150) / 10),
            'visit_num' => '1',
            'household_survey_status' => $status,
            'c_low' => '12', 'low_unit' => 'm', 'c_high' => '35', 'high_unit' => 'm',
            'bednets_documented' => 'no', 'madrassa_school' => 'no', 'recall_no_card' => 'yes', 'bn_age_range' => 'no_nets',
        ];
        foreach (['bcg', 'hepb', 'opv0', 'opv1', 'opv2', 'opv3', 'ipv1', 'ipv2', 'Penta1', 'Penta2', 'Penta3', 'pcv1', 'pcv2', 'pcv3', 'mal1', 'mal2', 'mal3', 'mal4', 'rota1', 'rota2', 'rota3', 'meas1', 'meas2', 'MenA', 'YF'] as $code) {
            $root['ok_'.$code] = self::BENIN_VACCINES[$code] ?? '';
        }

        $repeats = [];
        if ($status === '0') {
            $caregiverCount = mt_rand(1, 100) <= 75 ? 1 : 2;
            $root['num_slept_last_night'] = (string) mt_rand(4, 11);
            $root['hh_caregivers_num'] = (string) $caregiverCount;
            $root = $this->fill('', $root, fn () => null);
            $resolveRoot = fn (string $n) => $root[$n] ?? null;

            for ($p = 1, $persons = mt_rand(3, 7); $p <= $persons; $p++) {
                $repeats['smc_cov_roster_2023'][] = ['key' => null, 'data' => $this->fill('smc_cov_roster_2023', ['rost_num' => (string) $p], $resolveRoot), 'repeats' => []];
            }
            for ($c = 1; $c <= $caregiverCount; $c++) {
                $repeats['cg'][] = $this->caregiver($c, $resolveRoot, $date);
            }
            $repeats['SES'][] = ['key' => null, 'data' => $this->fill('SES', [], $resolveRoot), 'repeats' => []];
        } else {
            $root = $this->fill('', $root, fn () => null);
        }

        $submission = new NormalizedSubmission(
            uuid: 'uuid:'.Str::uuid(),
            data: $root,
            repeats: $repeats,
            raw: [],
            submittedAt: $submitted->utc()->toIso8601String(),
            formVersion: '3',
            reviewState: null,
            submitter: 'demo',
        );

        if ($anomaly) {
            $this->inject($submission, $anomaly);
        }
        $submission->raw = ['demo' => true, 'anomalie' => $anomaly, 'data' => $submission->data, 'repeats' => $submission->repeats];

        return ['submission' => $submission, 'anomaly' => $anomaly];
    }

    private function caregiver(int $position, \Closure $resolveRoot, CarbonImmutable $date): array
    {
        $agreed = mt_rand(1, 100) <= 93 ? '1' : '0';
        $childCount = mt_rand(1, 100) <= 60 ? 1 : (mt_rand(1, 100) <= 70 ? 2 : 3);
        $data = $this->fill('cg', [
            'familynumber' => (string) $position,
            'participation_agreed' => $agreed,
            'caregiver_sex' => mt_rand(1, 100) <= 92 ? '1' : '0',
            'caregiver_num_children' => (string) $childCount,
            'firstname' => $this->initials(),
        ], $resolveRoot);

        $children = [];
        if ($agreed === '1') {
            $resolveCg = fn (string $n) => array_key_exists($n, $data) ? $data[$n] : $resolveRoot($n);
            for ($i = 1; $i <= $childCount; $i++) {
                $children[] = ['key' => null, 'data' => $this->child($i, $resolveCg, $date), 'repeats' => []];
            }
        }

        return ['key' => null, 'data' => $data, 'repeats' => $children === [] ? [] : ['smc_cov_hh_cg_child_2023' => $children]];
    }

    private function child(int $position, \Closure $resolveParent, CarbonImmutable $interview): array
    {
        // 65 % des enfants dans la tranche 12–35 mois, les autres entre 0 et 59 mois.
        $ageMonths = mt_rand(1, 100) <= 65 ? mt_rand(12, 35) : mt_rand(0, 59);
        $dob = $interview->subMonths($ageMonths)->subDays(mt_rand(0, 27));
        $documented = mt_rand(1, 100) <= 80;
        $exact = (int) floor($dob->diffInMonths($interview));

        $presets = [
            'child_num' => (string) $position,
            'child_sex' => (string) mt_rand(0, 1),
            'child_sleep_last_night' => mt_rand(1, 100) <= 95 ? '1' : '0',
            'child_dob_documented' => $documented ? '1' : '0',
            'child_years' => (string) intdiv($exact, 12),
            'child_months' => (string) ($exact % 12),
        ];
        if ($documented) {
            $presets['child_dob'] = $dob->toDateString();
        } else {
            $presets['child_dob_caregiver_known'] = mt_rand(0, 1) ? '1' : '0';
            if ($presets['child_dob_caregiver_known'] === '1') {
                $presets['child_dob_reported'] = $dob->toDateString();
            }
        }

        $eligible = $exact >= 12 && $exact <= 35;
        if ($eligible) {
            $presets += ['receive_vaccine' => mt_rand(1, 100) <= 92 ? '1' : '0', 'ever_card' => mt_rand(1, 100) <= 78 ? '1' : '0'];
            $presets += ['see_card' => mt_rand(1, 100) <= 85 ? '1' : '0', 'card_version' => '1', 'orignal_card' => '1'];
            $presets += $this->vaccinations($dob, $interview);
        }

        return $this->fill('smc_cov_hh_cg_child_2023', $presets, $resolveParent);
    }

    /** Dates conformes au calendrier : dose suivante ≥ 28 jours après la précédente. */
    private function vaccinations(CarbonImmutable $dob, CarbonImmutable $interview): array
    {
        $values = [];
        $schedule = [
            ['bcg', 0], ['hepb', 0], ['opv0', 0],
            ['opv1', 42, 'Penta1'], ['Penta1', 42], ['pcv1', 42], ['rota1', 42],
            ['opv2', 70], ['Penta2', 70], ['pcv2', 70], ['rota2', 70],
            ['opv3', 98], ['Penta3', 98], ['pcv3', 98], ['rota3', 98],
            ['mal1', 150], ['mal2', 180], ['mal3', 210], ['meas1', 270], ['YF', 270], ['MenA', 270], ['mal4', 540], ['meas2', 450],
        ];
        $delay = mt_rand(0, 10);
        $previous = [];
        foreach ($schedule as [$code, $minAge]) {
            $series = preg_replace('/\d$/', '', $code);
            $date = $dob->addDays($minAge + $delay + mt_rand(0, 4));
            if (isset($previous[$series]) && $date->lt($previous[$series]->addDays(28))) {
                $date = $previous[$series]->addDays(28 + mt_rand(0, 6));
            }
            $received = $date->lte($interview) && mt_rand(1, 100) <= 90;
            $values[$code] = $received ? '1' : '0';
            if ($received) {
                $previous[$series] = $date;
                $known = mt_rand(1, 100) <= 93;
                $values[$code.'dat_known'] = $known ? '1' : '0';
                if ($known) {
                    $values[$code.'date'] = $date->toDateString();
                }
            }
            $delay += mt_rand(0, 3);
        }

        return $values;
    }

    /**
     * Parcourt les variables d'une portée dans l'ordre du formulaire.
     *
     * @param  \Closure(string): mixed  $resolveParent
     */
    private function fill(string $repeat, array $presets, \Closure $resolveParent): array
    {
        $data = $presets;
        $resolver = function (string $name) use (&$data, $resolveParent) {
            return array_key_exists($name, $data) ? $data[$name] : $resolveParent($name);
        };

        foreach ($this->byRepeat[$repeat] ?? [] as $name => $variable) {
            if (! $this->relevant($name, $resolver)) {
                unset($data[$name]);

                continue;
            }
            if (array_key_exists($name, $presets)) {
                continue;
            }
            if ($variable['base_type'] === 'calculate') {
                if ($variable['calculation']) {
                    try {
                        $value = (new ExpressionEvaluator($resolver, null, $this->today))->evaluate($variable['calculation']);
                        $data[$name] = ExpressionEvaluator::toString($value) === 'NaN' ? '' : ExpressionEvaluator::toString($value);
                    } catch (ExpressionException) {
                        // pulldata(), position()… : valeur inconnue hors terminal
                    }
                }

                continue;
            }
            if (! $this->dictionary->isInput($variable)) {
                continue;
            }
            $data[$name] = $this->value($variable, $resolver);
        }

        return $data;
    }

    private function relevant(string $name, \Closure $resolver): bool
    {
        foreach ($this->dictionary->relevantChain($name) as $expression) {
            try {
                if (! (new ExpressionEvaluator($resolver, null, $this->today))->evaluateBool($expression)) {
                    return false;
                }
            } catch (ExpressionException) {
                return false;
            }
        }

        return true;
    }

    private function value(array $variable, \Closure $resolver): ?string
    {
        $type = $variable['base_type'];
        $name = $variable['name'];

        if (str_starts_with($type, 'select_')) {
            $choices = array_values(array_filter(
                array_map('strval', array_keys($this->dictionary->choices((string) $variable['list_name']) ?? [])),
                fn ($choice) => ! preg_match('/\s/', $choice),
            ));
            if ($choices === []) {
                return null;
            }
            if (in_array('1', $choices, true) && in_array('0', $choices, true) && in_array($variable['list_name'], ['yn', 'yn_dk', 'yes_no_dk', 'yes_no_dkna'], true)) {
                return mt_rand(1, 100) <= 75 ? '1' : '0';
            }

            return (string) $choices[array_rand($choices)];
        }

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $value = match ($type) {
                'integer' => (string) mt_rand(...(self::RANGES[$name] ?? [0, 6])),
                'decimal' => (string) (mt_rand(0, 1000) / 10),
                'date' => $this->today->subDays(mt_rand(1, 700))->toDateString(),
                'text' => $this->initials(),
                default => null,
            };
            if ($value === null || ! $variable['constraint']) {
                return $value;
            }
            try {
                if ((new ExpressionEvaluator($resolver, $value, $this->today))->evaluateBool($variable['constraint'])) {
                    return $value;
                }
            } catch (ExpressionException) {
                return $value;
            }
        }

        return $type === 'integer' ? '0' : null;
    }

    /** Modifie la soumission pour provoquer une anomalie connue. */
    private function inject(NormalizedSubmission $s, string $anomaly): void
    {
        $child = &$this->firstEnrolledChild($s);
        $none = null;
        if (isset($s->repeats['cg'][0])) {
            $cg = &$s->repeats['cg'][0]['data'];
        } else {
            $cg = &$none;
        }

        switch ($anomaly) {
            case 'vaccine_before_birth':
                if ($child !== null && isset($child['child_dob'])) {
                    $child['bcg'] = '1';
                    $child['bcgdat_known'] = '1';
                    $child['bcgdate'] = CarbonImmutable::parse($child['child_dob'])->subDays(12)->toDateString();
                }
                break;
            case 'dose_order':
                if ($child !== null && isset($child['Penta1date'], $child['Penta2date'])) {
                    [$child['Penta1date'], $child['Penta2date']] = [$child['Penta2date'], $child['Penta1date']];
                }
                break;
            case 'short_interval':
                if ($child !== null && isset($child['pcv1date'], $child['pcv2date'])) {
                    $child['pcv2date'] = CarbonImmutable::parse($child['pcv1date'])->addDays(14)->toDateString();
                }
                break;
            case 'rr_too_early':
                if ($child !== null && isset($child['child_dob'])) {
                    $child['meas1'] = '1';
                    $child['meas1dat_known'] = '1';
                    $child['meas1date'] = CarbonImmutable::parse($child['child_dob'])->addDays(190)->toDateString();
                }
                break;
            case 'status_mismatch':
                if ($child !== null && isset($child['opv1date'])) {
                    $child['opv1'] = '0';
                }
                break;
            case 'card_no_dates':
                if ($child !== null && ($child['see_card'] ?? null) === '1') {
                    foreach (array_keys($child) as $key) {
                        if (preg_match('/date$/', $key) && $key !== 'child_dob') {
                            unset($child[$key]);
                        }
                    }
                }
                break;
            case 'short_interview':
                $s->data['end'] = CarbonImmutable::parse($s->data['start'])->addMinutes(4)->toIso8601String();
                break;
            case 'off_hours':
                $start = CarbonImmutable::parse($s->data['start'])->setTime(5, mt_rand(10, 50));
                $s->data['start'] = $start->toIso8601String();
                $s->data['end'] = $start->addMinutes(35)->toIso8601String();
                break;
            case 'gps_far':
                [$lat, $lon] = explode(' ', $s->data['current_location']);
                $s->data['current_location'] = sprintf('%.6f %.6f 200 8.0', (float) $lat + 0.12, (float) $lon - 0.09);
                break;
            case 'gps_missing':
                unset($s->data['current_location']);
                break;
            case 'gps_inaccurate':
                [$lat, $lon] = explode(' ', $s->data['current_location']);
                $s->data['current_location'] = sprintf('%s %s 210 %d', $lat, $lon, mt_rand(120, 400));
                break;
            case 'missing_required':
                if (isset($s->data['num_bednets'])) {
                    unset($s->data['num_bednets']);
                } elseif ($cg !== null) {
                    unset($cg['caregiver_marital_status']);
                }
                break;
            case 'constraint':
                if (isset($s->data['num_slept_last_night'])) {
                    $s->data['hh_caregivers_num'] = (string) ((int) $s->data['num_slept_last_night'] + 2);
                }
                break;
            case 'invalid_choice':
                $s->data['visit_num'] = '7';
                break;
            case 'today_mismatch':
                $s->data['today'] = CarbonImmutable::parse($s->data['today'])->subDay()->toDateString();
                break;
            case 'age_dob':
                if ($child !== null && isset($child['child_dob'])) {
                    $child['child_years'] = (string) ((int) $child['child_years'] + 2);
                }
                break;
            case 'late_submission':
                $s->submittedAt = CarbonImmutable::parse($s->data['end'])->addDays(mt_rand(4, 9))->min(CarbonImmutable::now())->utc()->toIso8601String();
                break;
            case 'refused_with_data':
                $s->data['household_survey_status'] = '1';
                break;
            case 'too_many_bednets':
                if (isset($s->data['num_slept_last_night'])) {
                    $s->data['num_bednets'] = (string) ((int) $s->data['num_slept_last_night'] + 3);
                }
                break;
        }
    }

    private function &firstEnrolledChild(NormalizedSubmission $s): ?array
    {
        $null = null;
        foreach ($s->repeats['cg'] ?? [] as $i => $cg) {
            foreach ($cg['repeats']['smc_cov_hh_cg_child_2023'] ?? [] as $j => $child) {
                if (isset($child['data']['receive_vaccine'])) {
                    return $s->repeats['cg'][$i]['repeats']['smc_cov_hh_cg_child_2023'][$j]['data'];
                }
            }
        }

        return $null;
    }

    private function initials(): string
    {
        $letters = 'ABCDEFGHIJKLMNOPRSTVYZ';

        return $letters[mt_rand(0, strlen($letters) - 1)].$letters[mt_rand(0, strlen($letters) - 1)];
    }

    private function jitter(float $amplitude): float
    {
        return (mt_rand(-1000, 1000) / 1000) * $amplitude;
    }
}
