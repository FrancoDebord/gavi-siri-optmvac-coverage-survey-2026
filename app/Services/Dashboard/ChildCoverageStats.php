<?php

namespace App\Services\Dashboard;

use App\Support\DashboardCache;
use App\Support\Filters;
use Illuminate\Support\Facades\DB;

/**
 * Base des pages de couverture (antigènes, VAP, CPS) : enfants enrôlés (tranche d'âge éligible) de la base nettoyée,
 * filtres du tableau de bord, regroupement par zone, commune, village, tranche d'âge et sexe. Estimations brutes
 * (non pondérées par le plan de sondage).
 */
abstract class ChildCoverageStats
{
    /** Regroupements proposés : clé de la réponse => propriété de l'enfant. */
    public const GROUPS = ['by_zone' => 'zone', 'by_commune' => 'commune', 'by_village' => 'village', 'by_age' => 'age_group', 'by_sex' => 'sex'];

    /** Enfants déjà lus (export en plusieurs feuilles). */
    private ?array $loaded = null;

    public function __construct(protected readonly Filters $filters) {}

    /** Clé du cache (une par page). */
    abstract protected function cacheKey(): string;

    /**
     * Indicateurs d'un groupe d'enfants (tout l'échantillon filtré, ou une zone, une commune…).
     *
     * @param  list<array>  $children
     */
    abstract protected function metrics(array $children): array;

    /** Indicateurs propres à la page, en plus de ceux de metrics() sur tout l'échantillon. */
    protected function extra(array $children): array
    {
        return [];
    }

    public function all(): array
    {
        return DashboardCache::remember($this->cacheKey(), $this->filters->cacheContext(), function () {
            $children = $this->children();
            $result = ['total' => ['children' => count($children)] + $this->metrics($children)] + $this->extra($children);
            foreach (self::GROUPS as $key => $property) {
                $result[$key] = $this->grouped($children, $property);
            }

            return $result + ['generated_at' => now()->format('d/m/Y H:i:s')];
        });
    }

    /**
     * Enfants enrôlés : zone (et son code l1), commune, village, âge en mois, tranche d'âge, sexe, carte vue,
     * réponses de l'enfant (data) et du ménage (household).
     *
     * @return list<array{zone: string, zone_code: ?string, commune: string, village: string, age: ?int, age_group: string, sex: string, card_seen: bool, data: array, household: array}>
     */
    protected function children(): array
    {
        $rows = $this->filters->applyThroughHouseholds(DB::table('children')->where('children.eligible', true), 'children.household_id')
            ->join('households', 'households.id', '=', 'children.household_id')
            ->leftJoin('health_zones', 'health_zones.id', '=', 'households.health_zone_id')
            ->leftJoin('communes', 'communes.id', '=', 'households.commune_id')
            ->leftJoin('villages', 'villages.id', '=', 'households.village_id')
            ->get(['children.age_months', 'children.sex', 'children.card_seen', 'children.data', 'households.data as household_data',
                'health_zones.code as zone_code', 'health_zones.name as zone', 'communes.name as commune', 'villages.name as village']);

        $children = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $age = $row->age_months ?? (is_numeric($data['age_months_est'] ?? null) ? (int) $data['age_months_est'] : null);
            $sex = (string) ($row->sex ?? $data['child_sex'] ?? '');
            $children[] = [
                'zone' => $row->zone ?? 'Zone non renseignée',
                'zone_code' => $row->zone_code,
                'commune' => $row->commune ?? 'Commune non renseignée',
                'village' => $row->village ?? 'Village non renseigné',
                'age' => $age,
                'age_group' => match (true) {
                    $age === null => 'Âge non renseigné',
                    $age < 24 => '12–23 mois',
                    default => '24–35 mois',
                },
                'sex' => match ($sex) {
                    '0' => 'Garçons', '1' => 'Filles', default => 'Sexe non renseigné'
                },
                'card_seen' => (bool) $row->card_seen,
                'data' => $data,
                'household' => json_decode((string) $row->household_data, true) ?: [],
            ];
        }

        return $children;
    }

    /** Indicateurs par groupe, groupes triés par libellé. */
    protected function grouped(array $children, string $property): array
    {
        $groups = [];
        foreach ($children as $child) {
            $groups[$child[$property]][] = $child;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn ($label, $members) => ['label' => $label, 'children' => count($members)] + $this->metrics($members), array_keys($groups), $groups);
    }

    /**
     * Indicateurs par combinaison de propriétés (ex. ['zone', 'commune'] : chaque commune sous sa zone) ; [] = ensemble.
     * Pour l'export Excel (non mis en cache).
     *
     * @param  list<string>  $properties
     * @return list<array{labels: list<string>, children: int}>
     */
    public function groupedBy(array $properties): array
    {
        $groups = [];
        // Enfants lus une seule fois pour toutes les feuilles de l'export.
        $this->loaded ??= $this->children();
        foreach ($this->loaded as $child) {
            $labels = array_map(fn ($p) => $child[$p], $properties);
            $groups[implode("\u{1F}", $labels)] ??= ['labels' => $labels, 'members' => []];
            $groups[implode("\u{1F}", $labels)]['members'][] = $child;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_map(fn ($g) => ['labels' => $g['labels'], 'children' => count($g['members'])] + $this->metrics($g['members']), $groups));
    }

    protected static function rate(int $count, int $denominator): ?float
    {
        return $denominator ? round($count * 100 / $denominator, 1) : null;
    }

    /** Réponse « oui » (code 1) du formulaire. */
    protected static function yes(mixed $value): bool
    {
        return (string) $value === '1';
    }
}
