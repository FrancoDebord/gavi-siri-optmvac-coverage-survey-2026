<?php

namespace App\Exports;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Models\Team;
use App\Models\Village;
use App\Services\Dashboard\QueryStats;
use App\Services\Dictionary\VariableMap;
use App\Support\Filters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export des queries selon des critères : une ligne par query avec code, libellé, description,
 * suggestion de résolution, binôme et agent, ménage, localisation, statut de résolution et dates.
 * Excel : feuille « Critères », récapitulatif et une feuille par groupe si un regroupement est demandé.
 */
class QueriesExport implements WithMultipleSheets
{
    public const MAX_ROWS = 100000;

    public const GROUPS = [
        '' => 'Aucun (une seule feuille)',
        'team' => 'Binôme',
        'collector' => 'Agent',
        'health_zone' => 'Zone sanitaire',
        'commune' => 'Commune',
        'village' => 'Village',
        'cluster' => 'Grappe',
        'rule' => 'Règle',
        'resolution' => 'Statut de résolution',
    ];

    public const HEADINGS = [
        'Code query', 'Libellé', 'Description de l\'erreur', 'Suggestion de résolution', 'Variable', 'Question',
        'Valeur observée', 'Valeur attendue', 'Règle', 'Sévérité', 'Statut de résolution', 'Statut détaillé',
        'Binôme', 'Agent', 'ID ménage', 'ID soumission', 'Membre concerné', 'Zone sanitaire', 'Commune', 'Village', 'Grappe',
        'Date de collecte', 'Date de génération', 'Date de résolution', 'Dernier commentaire',
    ];

    /** Colonnes des groupes (clé de regroupement dans une ligne exportée). */
    private const GROUP_COLUMNS = [
        'team' => 12, 'collector' => 13, 'health_zone' => 17, 'commune' => 18, 'village' => 19, 'cluster' => 20, 'rule' => 8, 'resolution' => 10,
    ];

    public function __construct(
        private readonly Filters $filters,
        private readonly array $extra,
        private readonly string $groupBy = '',
    ) {}

    public function sheets(): array
    {
        $rows = $this->rows();
        $sheets = [new ArrayExport($this->criteria($rows->count()), ['Critère', 'Valeur'], 'Critères')];

        if ($this->groupBy === '' || ! isset(self::GROUP_COLUMNS[$this->groupBy])) {
            $sheets[] = new ArrayExport($rows->all(), self::HEADINGS, 'Queries');

            return $sheets;
        }

        $column = self::GROUP_COLUMNS[$this->groupBy];
        $groups = $rows->groupBy(fn ($row) => $row[$column] ?: 'Non renseigné')->sortKeys();

        $sheets[] = new ArrayExport($groups->map(fn (Collection $items, $name) => [
            $name,
            $items->count(),
            $items->where(10, 'Non résolu')->count(),
            $items->where(10, 'Résolu')->count(),
            $items->where(10, 'Irrésoluble')->count(),
            $items->where(9, 'Critique')->count(),
            $items->pluck(14)->filter()->unique()->count(),
        ])->values()->all(), [self::GROUPS[$this->groupBy], 'Queries', 'Non résolues', 'Résolues', 'Irrésolubles', 'Critiques', 'Ménages concernés'], 'Récapitulatif');

        $titles = [];
        foreach ($groups as $name => $items) {
            $title = mb_substr(preg_replace('#[:\\\\/?*\[\]]#', ' ', (string) $name), 0, 28);
            $titles[$title] = ($titles[$title] ?? 0) + 1;
            $sheets[] = new ArrayExport($items->values()->all(), self::HEADINGS, $titles[$title] > 1 ? $title.' '.$titles[$title] : $title);
        }

        return $sheets;
    }

    /** Lignes exportées, dans l'ordre des en-têtes. */
    public function rows(): Collection
    {
        $queries = (new QueryStats($this->filters, $this->extra))->queries()
            ->leftJoin('health_zones', 'health_zones.id', '=', 'data_queries.health_zone_id')
            ->leftJoin('communes', 'communes.id', '=', 'data_queries.commune_id')
            ->leftJoin('villages', 'villages.id', '=', 'data_queries.village_id')
            ->leftJoin('clusters', 'clusters.id', '=', 'data_queries.cluster_id')
            ->leftJoin('collectors', 'collectors.id', '=', 'data_queries.collector_id')
            ->leftJoin('teams', 'teams.id', '=', 'collectors.team_id')
            ->leftJoin('quality_rules', 'quality_rules.id', '=', 'data_queries.quality_rule_id')
            ->orderBy('teams.code')->orderBy('collectors.name')->orderBy('data_queries.collection_date')->orderBy('data_queries.household_code')->orderBy('data_queries.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'data_queries.*', 'health_zones.name as zone', 'communes.name as commune', 'villages.name as village',
                'clusters.name as cluster', 'clusters.code as cluster_code', 'collectors.name as collector', 'teams.code as team_code', 'teams.name as team_name', 'quality_rules.name as rule_name',
            ]);

        $comments = $this->lastComments($queries->pluck('id'));
        $date = fn ($value, string $format = 'd/m/Y') => $value ? CarbonImmutable::parse($value)->format($format) : '';

        return $queries->map(fn ($q) => [
            DataQuery::codeFor($q->id),
            $q->label ?? $q->rule_name ?? $q->rule_code,
            $q->message,
            $q->suggestion,
            $q->variable,
            $q->variable_label,
            $q->observed_value,
            $q->expected,
            $q->rule_code,
            QualityRule::SEVERITIES[$q->severity] ?? $q->severity,
            DataQuery::RESOLUTIONS[DataQuery::resolutionOf($q->status)]['label'],
            DataQuery::STATUSES[$q->status] ?? $q->status,
            $q->team_code ? Team::labelFor($q->team_code, $q->team_name) : null,
            $q->collector,
            $q->household_code,
            $q->submission_uuid,
            self::memberLabel($q->instance_path),
            $q->zone,
            $q->commune,
            $q->village,
            $q->cluster ? $q->cluster.' ('.$q->cluster_code.')' : null,
            $date($q->collection_date),
            $date($q->detected_at, 'd/m/Y H:i'),
            $date($q->resolved_at, 'd/m/Y H:i'),
            $comments[$q->id] ?? null,
        ]);
    }

    /** « cg[1]/smc_cov_hh_cg_child_2023[2] » → « Caregiver 1 › Enfant 2 ». */
    public static function memberLabel(?string $path): string
    {
        if (! $path) {
            return 'Ménage';
        }
        $names = [VariableMap::var('repeat_caregivers') => 'Caregiver', VariableMap::var('repeat_enfants') => 'Enfant'];

        return collect(explode('/', $path))->map(function ($segment) use ($names) {
            if (! preg_match('/^(.+)\[(\d+)\]$/', $segment, $m)) {
                return $segment;
            }

            return ($names[$m[1]] ?? $m[1]).' '.$m[2];
        })->implode(' › ');
    }

    /** @return array<int, string> dernier commentaire par query */
    private function lastComments(Collection $ids): array
    {
        $comments = [];
        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('query_events')->whereIn('data_query_id', $chunk)->whereNotNull('comment')->where('comment', '!=', '')
                ->orderBy('id')->get(['data_query_id', 'comment'])
                ->each(function ($event) use (&$comments) {
                    $comments[$event->data_query_id] = $event->comment;
                });
        }

        return $comments;
    }

    /** Rappel lisible des critères appliqués (première feuille). */
    public function criteria(int $count): array
    {
        $values = $this->filters->values;
        $name = fn (string $model, ?int $id) => $id ? ($model::find($id)?->name ?? "#{$id}") : null;
        $rows = [
            ['Export généré le', now()->format('d/m/Y H:i')],
            ['Par', $this->filters->user?->name],
            ['Zone sanitaire', $name(HealthZone::class, $values['health_zone_id'] ?? null)],
            ['Commune', $name(Commune::class, $values['commune_id'] ?? null)],
            ['Village', $name(Village::class, $values['village_id'] ?? null)],
            ['Grappe', $name(Cluster::class, $values['cluster_id'] ?? null)],
            ['Binôme', isset($values['team_id']) ? Team::find($values['team_id'])?->label() : null],
            ['Agent', $name(Collector::class, $values['collector_id'] ?? null)],
            ['Date de collecte du', isset($values['from']) ? CarbonImmutable::parse($values['from'])->format('d/m/Y') : null],
            ['Date de collecte au', isset($values['to']) ? CarbonImmutable::parse($values['to'])->format('d/m/Y') : null],
            ['Date de génération du', $this->extra['generated_from'] ? CarbonImmutable::parse($this->extra['generated_from'])->format('d/m/Y') : null],
            ['Date de génération au', $this->extra['generated_to'] ? CarbonImmutable::parse($this->extra['generated_to'])->format('d/m/Y') : null],
            ['Statut de résolution', $this->extra['resolution'] ? DataQuery::RESOLUTIONS[$this->extra['resolution']]['label'] : null],
            ['Statut détaillé', $this->extra['status'] === 'non_resolues' ? 'Non résolues' : (DataQuery::STATUSES[$this->extra['status'] ?? ''] ?? null)],
            ['Sévérité', QualityRule::SEVERITIES[$this->extra['severity'] ?? ''] ?? null],
            ['Règle', $this->extra['rule_code'] ?? null],
            ['Variable', $this->extra['variable'] ?? null],
            ['Code query', $this->extra['code'] ? DataQuery::codeFor($this->extra['code']) : null],
            ['Regroupement', self::GROUPS[$this->groupBy] ?? self::GROUPS['']],
            ['Nombre de queries exportées', $count.($count >= self::MAX_ROWS ? ' (limite atteinte : affinez les critères)' : '')],
        ];

        return array_map(fn ($row) => [$row[0], $row[1] ?? 'Tous'], $rows);
    }
}
