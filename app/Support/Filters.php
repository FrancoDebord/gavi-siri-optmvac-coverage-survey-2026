<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * Filtres globaux des dashboards (zone > commune > village > grappe, binôme, période, source)
 * combinés au périmètre géographique de l'utilisateur.
 */
final class Filters
{
    public const GEO = ['health_zone_id', 'commune_id', 'village_id', 'cluster_id', 'collector_id'];

    public function __construct(public readonly array $values, public readonly ?User $user = null) {}

    public static function fromRequest(Request $request): self
    {
        $values = [];
        foreach (self::GEO as $key) {
            $value = $request->input($key);
            if (is_numeric($value)) {
                $values[$key] = (int) $value;
            }
        }
        if (is_numeric($request->input('team_id'))) {
            $values['team_id'] = (int) $request->input('team_id');
        }
        foreach (['from', 'to'] as $key) {
            $value = DateInput::parse($request->input($key));
            if ($value) {
                $values[$key] = $value;
            }
        }
        $source = $request->input('source');
        if (is_string($source) && $source !== '') {
            $values['source'] = $source;
        }

        $user = $request->user();
        // Le périmètre d'un superviseur s'impose aux filtres choisis.
        if ($scope = $user?->geographicScope()) {
            $values[$scope['column']] = $scope['id'];
        }

        return new self($values, $user);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** Applique les filtres à une requête sur une table qui porte les colonnes géographiques. */
    public function apply(Builder|QueryBuilder $query, string $table, string $dateColumn, bool $withSource = true): Builder|QueryBuilder
    {
        foreach (self::GEO as $column) {
            if (isset($this->values[$column])) {
                $query->where("{$table}.{$column}", $this->values[$column]);
            }
        }
        // Binôme : les données de ses agents.
        if (isset($this->values['team_id'])) {
            $query->whereIn("{$table}.collector_id", fn ($q) => $q->select('id')->from('collectors')->where('team_id', $this->values['team_id']));
        }
        if (isset($this->values['from'])) {
            $query->where("{$table}.{$dateColumn}", '>=', $this->values['from']);
        }
        if (isset($this->values['to'])) {
            $query->where("{$table}.{$dateColumn}", '<=', $this->values['to']);
        }
        if ($withSource && isset($this->values['source'])) {
            $source = $this->values['source'];
            if (is_numeric($source)) {
                $query->where("{$table}.data_source_id", (int) $source);
            } elseif ($source === 'import') {
                $query->where("{$table}.source_type", 'like', 'import%');
            } else {
                $query->where("{$table}.source_type", $source);
            }
        }

        return $query;
    }

    /** Variante pour les tables liées aux ménages (caregivers, children, data_queries via household). */
    public function applyThroughHouseholds(Builder|QueryBuilder $query, string $foreignKey): Builder|QueryBuilder
    {
        return $query->whereIn($foreignKey, fn ($sub) => $this->apply($sub->from('households')->select('households.id'), 'households', 'interview_date'));
    }

    public function cacheContext(): array
    {
        return $this->values;
    }
}
