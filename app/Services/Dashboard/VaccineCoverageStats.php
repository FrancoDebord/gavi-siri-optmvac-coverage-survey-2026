<?php

namespace App\Services\Dashboard;

/**
 * Couverture des antigènes du calendrier du Bénin (hors VAP, qui a sa page) :
 * - dose reçue : cochée « reçue » sur la carte, sinon déclarée au rappel du caregiver (question de rappel de la dose) ;
 * - dénominateur de chaque dose : enfants enrôlés qui ont atteint l'âge de la dose (gavi.vaccines.*.min_age_days,
 *   calendrier de la zone s'il diffère) ;
 * - complètement vacciné (antigènes de base) : BCG, VPO 3, Penta 3 et RR 1 ; pour l'âge : toutes les doses dues ;
 * - zéro dose : sans Penta 1 (définition Gavi) ; abandon Penta 1 → Penta 3 et Penta 1 → RR 1.
 */
class VaccineCoverageStats extends ChildCoverageStats
{
    /** Doses suivies : code (config gavi.vaccines) => [antigène, question de rappel ou null]. */
    public const DOSES = [
        'bcg' => ['BCG', 'bcg_rec'],
        'hepb' => ['HepB', 'hepb_rec'],
        'opv0' => ['VPO', 'opv0_rec'],
        'opv1' => ['VPO', 'opv1_rec'],
        'opv2' => ['VPO', 'opv2_rec'],
        'opv3' => ['VPO', 'opv3_rec'],
        'ipv1' => ['VPI', null],
        'penta1' => ['Penta', 'penta1_rec'],
        'penta2' => ['Penta', 'penta2_rec'],
        'penta3' => ['Penta', 'penta3_rec'],
        'pcv1' => ['PCV', 'pcv1_rec'],
        'pcv2' => ['PCV', 'pcv2_rec'],
        'pcv3' => ['PCV', 'pcv3_rec'],
        'rota1' => ['Rota', 'rota1_rec'],
        'rota2' => ['Rota', 'rota2_rec'],
        'rota3' => ['Rota', 'rota3_rec'],
        'meas1' => ['RR', 'measmth9'],
        'meas2' => ['RR', 'measmth15'],
        'mena' => ['MenA', null],
        'yf' => ['VAA', 'yfever_rec'],
    ];

    /** Antigènes de base de « complètement vacciné ». */
    public const BASIC = ['bcg', 'opv3', 'penta3', 'meas1'];

    protected function cacheKey(): string
    {
        return 'vaccine-coverage';
    }

    /** Libellé, antigène et âge de chaque dose (pour la page). */
    public static function doseList(): array
    {
        return array_map(fn ($code) => [
            'code' => $code,
            'label' => config("gavi.vaccines.{$code}.label", $code),
            'antigen' => self::DOSES[$code][0],
            'age_months' => self::minMonths($code, null),
            'recall' => self::DOSES[$code][1] !== null,
        ], array_keys(self::DOSES));
    }

    /** Âge minimal (mois) de la dose : calendrier de la zone, sinon calendrier standard. */
    public static function minMonths(string $code, ?string $zoneCode): int
    {
        $days = ($zoneCode !== null ? config("gavi.vaccine_min_age_by_zone.{$zoneCode}.{$code}") : null) ?? config("gavi.vaccines.{$code}.min_age_days", 0);

        return (int) round($days / 30);
    }

    /** Doses renseignées dans les données (carte ou rappel) : une dose jamais posée (ex. VPI au Bénin) est écartée. */
    private array $collected = [];

    protected function children(): array
    {
        $cardVars = array_map(fn ($code) => config("gavi.vaccines.{$code}.var"), array_combine(array_keys(self::DOSES), array_keys(self::DOSES)));
        $minMonths = [];
        $collected = [];
        $children = array_map(function (array $child) use ($cardVars, &$minMonths, &$collected) {
            $zone = (string) $child['zone_code'];
            $minMonths[$zone] ??= array_map(fn ($code) => self::minMonths($code, $child['zone_code']), array_combine(array_keys(self::DOSES), array_keys(self::DOSES)));
            $child['doses'] = $child['due'] = [];
            foreach (self::DOSES as $code => [, $recall]) {
                $onCard = $cardVars[$code] ? ($child['data'][$cardVars[$code]] ?? null) : null;
                $recalled = $recall ? ($child['data'][$recall] ?? null) : null;
                if (($onCard !== null && $onCard !== '') || ($recalled !== null && $recalled !== '')) {
                    $collected[$code] = true;
                }
                $child['doses'][$code] = match (true) {
                    self::yes($onCard) => 'carte',
                    self::yes($recalled) => 'rappel',
                    default => null,
                };
                $child['due'][$code] = $child['age'] !== null && $child['age'] >= $minMonths[$zone][$code];
            }
            unset($child['data'], $child['household']);

            return $child;
        }, parent::children());
        $this->collected = array_values(array_intersect(array_keys(self::DOSES), array_keys($collected)));

        return $children;
    }

    protected function extra(array $children): array
    {
        return ['collected' => $this->collected];
    }

    protected function metrics(array $children): array
    {
        $doses = [];
        foreach ($this->collected as $code) {
            $due = $card = $recall = 0;
            foreach ($children as $c) {
                if ($c['due'][$code]) {
                    $due++;
                    $card += $c['doses'][$code] === 'carte' ? 1 : 0;
                    $recall += $c['doses'][$code] === 'rappel' ? 1 : 0;
                }
            }
            $doses[$code] = ['denominator' => $due, 'received' => $card + $recall, 'card' => $card, 'recall' => $recall,
                'pct' => self::rate($card + $recall, $due), 'pct_card' => self::rate($card, $due)];
        }
        $n = count($children);
        $basic = array_values(array_intersect(self::BASIC, $this->collected));
        $fullyBasic = $fullyAge = $zero = $penta1 = $noPenta3 = $noRr1 = $cardSeen = 0;
        foreach ($children as $c) {
            $has = fn (string $code) => $c['doses'][$code] !== null;
            $fullyBasic += array_filter($basic, fn ($code) => ! $has($code)) === [] ? 1 : 0;
            $fullyAge += array_filter($this->collected, fn ($code) => $c['due'][$code] && ! $has($code)) === [] ? 1 : 0;
            $cardSeen += $c['card_seen'] ? 1 : 0;
            if (! $has('penta1')) {
                $zero++;

                continue;
            }
            $penta1++;
            $noPenta3 += $has('penta3') ? 0 : 1;
            $noRr1 += $has('meas1') ? 0 : 1;
        }

        return [
            'doses' => $doses,
            'fully_basic' => self::rate($fullyBasic, $n),
            'fully_age' => self::rate($fullyAge, $n),
            'zero_dose' => self::rate($zero, $n),
            'dropout_penta' => self::rate($noPenta3, $penta1),
            'dropout_penta_rr' => self::rate($noRr1, $penta1),
            'card_seen' => self::rate($cardSeen, $n),
        ];
    }
}
