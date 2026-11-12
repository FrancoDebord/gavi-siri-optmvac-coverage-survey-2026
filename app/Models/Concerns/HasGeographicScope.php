<?php

namespace App\Models\Concerns;

use App\Models\Collector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restreint une requête au périmètre géographique de l'utilisateur (superviseurs)
 * et applique les filtres globaux des dashboards.
 */
trait HasGeographicScope
{
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        // Compte binôme : uniquement les données collectées par ses deux agents.
        if ($user?->hasRole(User::ROLE_TEAM)) {
            return $query->whereIn($this->qualifyColumn('collector_id'), Collector::where('team_id', $user->team_id ?? 0)->select('id'));
        }
        $scope = $user?->geographicScope();
        if ($scope) {
            $query->where($this->qualifyColumn($scope['column']), $scope['id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters  health_zone_id, commune_id, village_id, cluster_id, collector_id, from, to, data_source_id
     */
    public function scopeFiltered(Builder $query, array $filters, string $dateColumn): Builder
    {
        foreach (['health_zone_id', 'commune_id', 'village_id', 'cluster_id', 'collector_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($this->qualifyColumn($column), $filters[$column]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate($this->qualifyColumn($dateColumn), '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate($this->qualifyColumn($dateColumn), '<=', $filters['to']);
        }

        return $query;
    }
}
