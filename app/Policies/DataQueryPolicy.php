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

    /** Répondre (correction proposée, valeur confirmée) : l'assigné, ou l'équipe de traitement de la zone. */
    public function respond(User $user, DataQuery $query): bool
    {
        return ($query->assigned_to !== null && (int) $query->assigned_to === (int) $user->id && $this->view($user, $query))
            || $this->update($user, $query);
    }

    /** Commenter : quiconque peut répondre, affecter ou valider. */
    public function comment(User $user, DataQuery $query): bool
    {
        return $this->respond($user, $query) || $this->assign($user, $query) || $this->validate($user, $query);
    }

    public function assign(User $user, DataQuery $query): bool
    {
        return $user->can('assign queries') && $this->inScope($user, $query);
    }

    /** Valider, rejeter, rouvrir, déclarer irrésoluble : data manager (revue indépendante). */
    public function validate(User $user, DataQuery $query): bool
    {
        return $user->can('validate queries') && $this->inScope($user, $query);
    }

    private function inScope(User $user, DataQuery $query): bool
    {
        $scope = $user->geographicScope();

        return ! $scope || (int) $query->{$scope['column']} === (int) $scope['id'];
    }
}
