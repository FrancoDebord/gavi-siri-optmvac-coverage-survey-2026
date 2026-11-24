<?php

namespace App\Services\Dashboard;

use App\Support\DashboardCache;
use App\Support\Filters;
use Illuminate\Support\Facades\DB;

/**
 * Couverture du vaccin antipaludique (VAP 1 à 4) des enfants enrôlés (tranche d'âge éligible), base nettoyée.
 *
 * - Dose reçue : cochée « reçue » sur la carte (mal1 … mal4) ou, à défaut, déclarée au rappel (rtss1_rec … rtss4_rec).
 *   La source retenue est la carte quand elle indique la dose, sinon le rappel.
 * - Dénominateur : tous les enfants enrôlés pour VAP 1 à 3 (12–35 mois : tous ont passé l'âge de la 3e dose, 9 mois) ;
 *   pour VAP 4, les enfants qui ont atteint l'âge de la 4e dose dans leur zone (gavi.vaccine_min_age_by_zone, sinon
 *   gavi.vaccines.mal4.min_age_days : 18 mois à Tchaourou, 15 mois à Dassa-Glazoué).
 * - Estimations brutes (non pondérées par le plan de sondage).
 */
class VapCoverageStats
{
    public const DOSES = [1, 2, 3, 4];

    public function __construct(private readonly Filters $filters) {}

    public function all(): array
    {
        return DashboardCache::remember('vap-coverage', $this->filters->cacheContext(), function () {
            $children = $this->children();

            return [
                'kpis' => $this->kpis($children),
                'by_dose' => $this->byDose($children),
                'by_zone' => $this->grouped($children, 'zone'),
                'by_commune' => $this->grouped($children, 'commune'),
                'by_village' => $this->grouped($children, 'village'),
                'by_age' => $this->grouped($children, 'age_group'),
                'by_sex' => $this->grouped($children, 'sex'),
                'generated_at' => now()->format('d/m/Y H:i:s'),
            ];
        });
    }

    /**
     * Enfants enrôlés avec, pour chaque dose, la source de la dose (« carte », « rappel » ou null) et l'éligibilité à VAP 4.
     *
     * @return list<array{zone: string, commune: string, village: string, age_group: string, sex: string, card_seen: bool, vap4_due: bool, doses: array<int, ?string>}>
     */
    private function children(): array
    {
        $rows = $this->filters->applyThroughHouseholds(DB::table('children')->where('children.eligible', true), 'children.household_id')
            ->join('households', 'households.id', '=', 'children.household_id')
            ->leftJoin('health_zones', 'health_zones.id', '=', 'households.health_zone_id')
            ->leftJoin('communes', 'communes.id', '=', 'households.commune_id')
            ->leftJoin('villages', 'villages.id', '=', 'households.village_id')
            ->get(['children.age_months', 'children.sex', 'children.card_seen', 'children.data', 'health_zones.code as zone_code',
                'health_zones.name as zone', 'communes.name as commune', 'villages.name as village']);

        $children = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $age = $row->age_months ?? (is_numeric($data['age_months_est'] ?? null) ? (int) $data['age_months_est'] : null);
            $doses = [];
            foreach (self::DOSES as $k) {
                $doses[$k] = match (true) {
                    (string) ($data["mal{$k}"] ?? '') === '1' => 'carte',
                    (string) ($data["rtss{$k}_rec"] ?? '') === '1' => 'rappel',
                    default => null,
                };
            }
            $sex = (string) ($row->sex ?? $data['child_sex'] ?? '');
            $children[] = [
                'zone' => $row->zone ?? 'Zone non renseignée',
                'commune' => $row->commune ?? 'Commune non renseignée',
                'village' => $row->village ?? 'Village non renseigné',
                'age_group' => match (true) {
                    $age === null => 'Âge non renseigné',
                    $age < 24 => '12–23 mois',
                    default => '24–35 mois',
                },
                'sex' => match ($sex) {
                    '0' => 'Garçons', '1' => 'Filles', default => 'Sexe non renseigné'
                },
                'card_seen' => (bool) $row->card_seen,
                'vap4_due' => $age !== null && $age >= self::vap4MinMonths($row->zone_code),
                'doses' => $doses,
            ];
        }

        return $children;
    }

    /** Âge (mois) de la 4e dose dans la zone : calendrier de la zone, sinon calendrier standard. */
    public static function vap4MinMonths(?string $zoneCode): int
    {
        $days = config("gavi.vaccine_min_age_by_zone.{$zoneCode}.mal4") ?? config('gavi.vaccines.mal4.min_age_days', 540);

        return (int) round($days / 30);
    }

    /** Couverture d'une dose dans un groupe d'enfants : dénominateur, reçues (carte, rappel) et pourcentages. */
    private static function dose(array $children, int $k): array
    {
        $denominator = $k === 4 ? array_values(array_filter($children, fn ($c) => $c['vap4_due'])) : $children;
        $n = count($denominator);
        $card = count(array_filter($denominator, fn ($c) => $c['doses'][$k] === 'carte'));
        $recall = count(array_filter($denominator, fn ($c) => $c['doses'][$k] === 'rappel'));
        $rate = fn (int $x) => $n ? round($x * 100 / $n, 1) : null;

        return [
            'denominator' => $n,
            'received' => $card + $recall,
            'card' => $card,
            'recall' => $recall,
            'pct' => $rate($card + $recall),
            'pct_card' => $rate($card),
            'pct_recall' => $rate($recall),
        ];
    }

    private function kpis(array $children): array
    {
        $doses = array_combine(self::DOSES, array_map(fn ($k) => self::dose($children, $k), self::DOSES));
        $v1 = $doses[1]['received'];
        // Abandon VAP 1 → VAP 3 / VAP 4 : enfants qui ont commencé la série sans la terminer.
        $dropout = function (int $k) use ($children) {
            $base = $k === 4 ? array_filter($children, fn ($c) => $c['vap4_due']) : $children;
            $started = count(array_filter($base, fn ($c) => $c['doses'][1] !== null));
            $completed = count(array_filter($base, fn ($c) => $c['doses'][1] !== null && $c['doses'][$k] !== null));

            return $started ? round(($started - $completed) * 100 / $started, 1) : null;
        };

        return [
            'children' => count($children),
            'card_seen' => count($children) ? round(count(array_filter($children, fn ($c) => $c['card_seen'])) * 100 / count($children), 1) : null,
            'vap4_due' => $doses[4]['denominator'],
            'doses' => $doses,
            'dropout_1_3' => $dropout(3),
            'dropout_1_4' => $dropout(4),
            'zero_dose' => count($children) ? round(count(array_filter($children, fn ($c) => $c['doses'][1] === null)) * 100 / count($children), 1) : null,
            'started' => $v1,
        ];
    }

    /** @return list<array{dose: string, denominator: int, pct: ?float, pct_card: ?float, pct_recall: ?float}> */
    private function byDose(array $children): array
    {
        return array_map(fn ($k) => ['dose' => "VAP {$k}"] + self::dose($children, $k), self::DOSES);
    }

    /** Couverture de chaque dose par groupe (zone, commune, village, âge, sexe), groupes triés par libellé. */
    private function grouped(array $children, string $key): array
    {
        $groups = [];
        foreach ($children as $child) {
            $groups[$child[$key]][] = $child;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn ($label, $members) => [
            'label' => $label,
            // Zone sanitaire de la commune (tableau « Niveau commune » à coller dans PowerPoint).
            ...($key === 'commune' ? ['zone' => $members[0]['zone']] : []),
            'children' => count($members),
            'doses' => array_combine(self::DOSES, array_map(fn ($k) => self::dose($members, $k), self::DOSES)),
        ], array_keys($groups), $groups);
    }

    /** Enfants déjà lus (export en plusieurs feuilles). */
    private ?array $loaded = null;

    /**
     * Couverture par combinaison de propriétés (ex. ['zone', 'commune']) ; [] = ensemble. Pour l'export Excel.
     *
     * @param  list<string>  $properties
     * @return list<array{labels: list<string>, children: int, doses: array}>
     */
    public function groupedBy(array $properties): array
    {
        $this->loaded ??= $this->children();
        $groups = [];
        foreach ($this->loaded as $child) {
            $labels = array_map(fn ($p) => $child[$p], $properties);
            $groups[implode("\u{1F}", $labels)] ??= ['labels' => $labels, 'members' => []];
            $groups[implode("\u{1F}", $labels)]['members'][] = $child;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_map(fn ($g) => ['labels' => $g['labels'], 'children' => count($g['members']),
            'doses' => array_combine(self::DOSES, array_map(fn ($k) => self::dose($g['members'], $k), self::DOSES))], $groups));
    }
}
