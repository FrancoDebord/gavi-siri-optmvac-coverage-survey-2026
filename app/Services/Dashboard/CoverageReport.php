<?php

namespace App\Services\Dashboard;

use App\Support\Filters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports PDF des pages de couverture (VAP, antigènes, CPS) : mêmes indicateurs et tableaux que la page, filtres en
 * clair, et graphiques dessinés en barres (le PDF n'exécute pas les graphiques de la page). Gabarit pdf.coverage.
 */
final class CoverageReport
{
    private const LEVELS = ['by_zone' => 'Zone sanitaire', 'by_commune' => 'Commune', 'by_age' => 'Tranche d\'âge', 'by_sex' => 'Sexe', 'by_village' => 'Village'];

    public static function vap(array $stats, Filters $filters): Response
    {
        $k = $stats['kpis'];
        $doses = VapCoverageStats::DOSES;

        return self::download([
            'title' => 'Couverture du vaccin antipaludique (VAP 1 à 4)',
            'definitions' => 'Dose reçue : cochée sur la carte, sinon déclarée au rappel du caregiver. VAP 1 à 3 : tous les enfants enrôlés (12–35 mois) ; '
                .'VAP 4 : enfants ayant atteint l\'âge de la 4e dose de leur zone (Tchaourou '.VapCoverageStats::vap4MinMonths('1').' mois, Dassa-Glazoué '.VapCoverageStats::vap4MinMonths('2').' mois). '
                .'Abandon : enfants ayant reçu VAP 1 sans la dose suivante, rapportés à ceux qui ont reçu VAP 1.',
            'kpis' => [
                ['Enfants enrôlés', self::int($k['children'])],
                ...array_map(fn ($d) => ["VAP {$d}", self::pct($k['doses'][$d]['pct'])], $doses),
                ['Aucune dose de VAP', self::pct($k['zero_dose'])],
                ['Abandon VAP 1 → 3', self::pct($k['dropout_1_3'])],
                ['Abandon VAP 1 → 4', self::pct($k['dropout_1_4'])],
            ],
            'bars' => ['title' => 'Couverture par dose (carte et rappel)', 'rows' => array_map(fn ($row) => [$row['dose'], $row['pct_card'], $row['pct'] !== null ? $row['pct'] - $row['pct_card'] : null, $row['pct']], $stats['by_dose'])],
            'detail' => self::detail('Dose', array_map(fn ($row) => [$row['dose'], null] + $row, $stats['by_dose'])),
            'tables' => self::levels($stats, array_map(fn ($d) => ["VAP {$d}", fn ($g) => $g['doses'][$d]], $doses)),
        ], $filters, 'couverture-vap');
    }

    public static function antigens(array $stats, Filters $filters): Response
    {
        $t = $stats['total'];
        $list = collect(VaccineCoverageStats::doseList())->keyBy('code');
        $shown = array_values(array_filter($stats['collected'], fn ($code) => $list->has($code)));
        $key = array_values(array_intersect(['bcg', 'penta1', 'penta3', 'opv3', 'pcv3', 'rota3', 'meas1', 'meas2', 'yf'], $shown));

        return self::download([
            'title' => 'Couverture vaccinale par antigène',
            'definitions' => 'Dose reçue : cochée sur la carte, sinon déclarée au rappel du caregiver. Dénominateur de chaque dose : enfants enrôlés ayant atteint l\'âge de la dose. '
                .'Complètement vacciné : BCG, VPO 3, Penta 3 et RR 1 ; pour l\'âge : toutes les doses dues. Zéro dose : sans Penta 1. '
                .'Doses jamais renseignées dans les données (question non posée) non présentées.',
            'kpis' => [
                ['Enfants enrôlés', self::int($t['children'])],
                ['Carte vue', self::pct($t['card_seen'])],
                ['Complètement vaccinés (base)', self::pct($t['fully_basic'])],
                ['Complètement vaccinés pour l\'âge', self::pct($t['fully_age'])],
                ['Zéro dose (sans Penta 1)', self::pct($t['zero_dose'])],
                ['Abandon Penta 1 → 3', self::pct($t['dropout_penta'])],
                ['Abandon Penta 1 → RR 1', self::pct($t['dropout_penta_rr'])],
            ],
            'bars' => ['title' => 'Couverture par dose (carte et rappel)', 'rows' => array_map(fn ($code) => [$list[$code]['label'], $t['doses'][$code]['pct_card'],
                $t['doses'][$code]['pct'] !== null ? $t['doses'][$code]['pct'] - $t['doses'][$code]['pct_card'] : null, $t['doses'][$code]['pct']], $shown)],
            'detail' => self::detail('Dose', array_map(fn ($code) => [$list[$code]['label'], $list[$code]['age_months'].' mois'] + $t['doses'][$code], $shown)),
            'tables' => self::levels($stats, [
                ...array_map(fn ($code) => [$list[$code]['label'], fn ($g) => $g['doses'][$code]], $key),
                ['Complets (base)', fn ($g) => ['pct' => $g['fully_basic']]],
                ['Zéro dose', fn ($g) => ['pct' => $g['zero_dose'], 'plain' => true]],
            ], 'total'),
        ], $filters, 'couverture-antigenes');
    }

    public static function smc(array $stats, Filters $filters): Response
    {
        $t = $stats['total'];
        $cycles = array_values(array_filter(range(1, SmcCoverageStats::MAX_CYCLES), fn ($k) => $t['cycles'][$k]['denominator'] > 0));

        return self::download([
            'title' => 'Couverture de la chimioprévention du paludisme saisonnier (CPS)',
            'definitions' => 'Cycle reçu : d\'après la carte CPS présentée, sinon le rappel du caregiver. Cycles prévus par commune (fichier de paramètres) : '
                .'4 à Tchaourou, 5 à Dassa et Glazoué ; un cycle non prévu n\'est pas compté.',
            'kpis' => [
                ['Enfants enrôlés', self::int($t['children'])],
                ['CPS déclarée (6 derniers mois)', self::pct($t['declared'])],
                ['Carte CPS reçue', self::pct($t['card_given'])],
                ['Carte CPS présentée', self::pct($t['card_present'])],
                ['Au moins un cycle', self::pct($t['at_least_one'])],
                ['Tous les cycles prévus', self::pct($t['all_cycles'])],
            ],
            'bars' => ['title' => 'Couverture par cycle (carte et rappel)', 'rows' => array_map(fn ($k) => ["Cycle {$k}", $t['cycles'][$k]['pct_card'],
                $t['cycles'][$k]['pct'] !== null ? $t['cycles'][$k]['pct'] - $t['cycles'][$k]['pct_card'] : null, $t['cycles'][$k]['pct']], $cycles)],
            'distribution' => ['title' => 'Nombre de cycles reçus', 'rows' => array_map(fn ($n, $count) => [$n.' cycle'.($n > 1 ? 's' : ''), $count,
                $t['children'] ? round($count * 100 / $t['children'], 1) : null], array_keys($t['distribution']), $t['distribution'])],
            'detail' => self::detail('Cycle', array_map(fn ($k) => ["Cycle {$k}", null] + $t['cycles'][$k], $cycles)),
            'tables' => self::levels($stats, [
                ...array_map(fn ($k) => ["C{$k}", fn ($g) => $g['cycles'][$k]], $cycles),
                ['≥ 1 cycle', fn ($g) => ['pct' => $g['at_least_one']]],
                ['Tous', fn ($g) => ['pct' => $g['all_cycles']]],
                ['Déclarée', fn ($g) => ['pct' => $g['declared'], 'plain' => true]],
            ], 'total'),
        ], $filters, 'couverture-cps');
    }

    /** Tableau détaillé par dose ou cycle : [libellé, âge] + dénominateur, carte, rappel, reçues, couverture, carte seule. */
    private static function detail(string $first, array $rows): array
    {
        $withAge = collect($rows)->contains(fn ($r) => $r[1] !== null);

        return [
            'headers' => array_values(array_filter([$first, $withAge ? 'Âge' : null, 'Dénominateur', 'Carte', 'Rappel seul', 'Reçues', 'Couverture', 'Carte seule'])),
            'rows' => array_map(fn ($r) => array_values(array_filter([$r[0], $withAge ? $r[1] : false, self::int($r['denominator']), self::int($r['card']), self::int($r['recall']),
                self::int($r['received']), ['pct' => $r['pct']], self::pct($r['pct_card'])], fn ($v) => $v !== false)), $rows),
        ];
    }

    /**
     * Tableaux par niveau : une colonne par indicateur ([libellé, fn(groupe) => ['pct', 'received'?, 'denominator'?, 'plain'?]]).
     * $total : clé de l'ensemble (pages antigènes et CPS) ajouté en tête du tableau par zone.
     */
    private static function levels(array $stats, array $columns, ?string $total = null): array
    {
        $tables = [];
        foreach (self::LEVELS as $key => $level) {
            $groups = $stats[$key] ?? [];
            if ($total && $key === 'by_zone') {
                array_unshift($groups, ['label' => 'Ensemble', 'children' => $stats[$total]['children']] + $stats[$total]);
            }
            $tables[] = [
                'title' => 'Par '.mb_strtolower($level),
                'headers' => [$level, 'Enfants', ...array_column($columns, 0)],
                'rows' => array_map(fn ($g) => [$g['label'], self::int($g['children']), ...array_map(fn ($c) => $c[1]($g), $columns)], $groups),
            ];
        }

        return $tables;
    }

    private static function download(array $report, Filters $filters, string $file): Response
    {
        return Pdf::loadView('pdf.coverage', $report + ['filters' => self::filtersText($filters)])
            ->setPaper('a4', 'landscape')
            ->download($file.'-'.now()->format('Ymd-Hi').'.pdf');
    }

    /** Filtres en clair : « Zone : Tchaourou · Période : du 01/11/2026 au 15/11/2026 ». */
    public static function filtersText(Filters $filters): string
    {
        $v = $filters->values;
        $name = fn (string $table, string $column, ?int $id) => $id ? (DB::table($table)->where('id', $id)->value($column) ?? "#{$id}") : null;
        $date = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : null;
        $parts = array_filter([
            'Zone' => $name('health_zones', 'name', $v['health_zone_id'] ?? null),
            'Commune' => $name('communes', 'name', $v['commune_id'] ?? null),
            'Village' => $name('villages', 'name', $v['village_id'] ?? null),
            'Grappe' => $name('clusters', 'code', $v['cluster_id'] ?? null),
            'Binôme' => $name('teams', 'code', $v['team_id'] ?? null),
            'Agent' => $name('collectors', 'name', $v['collector_id'] ?? null),
            'Période' => isset($v['from']) || isset($v['to']) ? trim(($date($v['from'] ?? null) ? 'du '.$date($v['from']) : '').' '.($date($v['to'] ?? null) ? 'au '.$date($v['to']) : '')) : null,
            'Source' => $v['source'] ?? null,
        ]);

        return $parts ? implode(' · ', array_map(fn ($k, $val) => "{$k} : {$val}", array_keys($parts), $parts)) : 'aucun (toutes les données)';
    }

    private static function int(?int $n): string
    {
        return $n === null ? '—' : number_format($n, 0, ',', ' ');
    }

    public static function pct(?float $p): string
    {
        return $p === null ? '—' : number_format($p, 1, ',', ' ').' %';
    }
}
