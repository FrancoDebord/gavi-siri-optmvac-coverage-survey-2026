<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        // Compte binôme : seulement les soumissions de ses agents.
        if ($user->hasRole(User::ROLE_TEAM)) {
            return $user->team_id !== null && (int) $household->collector?->team_id === (int) $user->team_id;
        }
        if (! $user->can('view dashboards')) {
            return false;
        }
        $scope = $user->geographicScope();

        return ! $scope || (int) $household->{$scope['column']} === (int) $scope['id'];
    }
}
