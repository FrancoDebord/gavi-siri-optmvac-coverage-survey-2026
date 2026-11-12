<?php

namespace App\Policies;

use App\Models\DataQuery;
use App\Models\User;

class DataQueryPolicy
{
    public function view(User $user, DataQuery $query): bool
    {
        // Compte binôme : lecture des queries de ses agents (sans traitement).
        if ($user->hasRole(User::ROLE_TEAM)) {
            return $user->team_id !== null && (int) $query->collector?->team_id === (int) $user->team_id;
        }

        return $user->can('view dashboards') && $this->inScope($user, $query);
    }

    public function update(User $user, DataQuery $query): bool
    {
        return $user->can('process queries') && $this->inScope($user, $query);
    }

    private function inScope(User $user, DataQuery $query): bool
    {
        $scope = $user->geographicScope();

        return ! $scope || (int) $query->{$scope['column']} === (int) $scope['id'];
    }
}
