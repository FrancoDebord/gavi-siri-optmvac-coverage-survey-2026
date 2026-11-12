<?php

namespace App\Policies;

use App\Models\FieldIncident;
use App\Models\User;

class FieldIncidentPolicy
{
    public function view(User $user, FieldIncident $incident): bool
    {
        return $user->can('view dashboards') && $this->inScope($user, $incident);
    }

    public function create(User $user): bool
    {
        return $user->can('report incidents');
    }

    public function update(User $user, FieldIncident $incident): bool
    {
        return $user->can('report incidents') && $this->inScope($user, $incident);
    }

    private function inScope(User $user, FieldIncident $incident): bool
    {
        $scope = $user->geographicScope();

        return ! $scope || (int) $incident->{$scope['column']} === (int) $scope['id'];
    }
}
