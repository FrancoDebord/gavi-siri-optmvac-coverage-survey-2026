<?php

namespace App\Policies;

use App\Models\DataQuery;
use App\Models\User;

class DataQueryPolicy
{
    public function view(User $user, DataQuery $query): bool
    {
        // Validation confiée : la personne désignée voit la query, quel que soit son périmètre.
        if ($query->reviewer_id !== null && (int) $query->reviewer_id === (int) $user->id) {
            return true;
        }
        // Compte binôme : lecture des queries de ses agents (sans traitement).
        if ($user->hasRole(User::ROLE_TEAM)) {
            return $this->ownTeam($user, $query);
        }
        // Superviseur agent d'un binôme : les queries de son binôme, en plus de son périmètre.
        if ($this->ownTeam($user, $query)) {
            return true;
        }

        return $user->can('view dashboards') && $this->inScope($user, $query);
    }

    public function update(User $user, DataQuery $query): bool
    {
        return $user->can('process queries') && $this->inScope($user, $query);
    }

    /**
     * Répondre (correction, valeur confirmée) : l'assigné, le compte du binôme qui a collecté le ménage (sans attendre
     * d'affectation), ou l'équipe de traitement de la zone.
     */
    public function respond(User $user, DataQuery $query): bool
    {
        return ($query->assigned_to !== null && (int) $query->assigned_to === (int) $user->id && $this->view($user, $query))
            || $this->ownTeam($user, $query)
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

    /**
     * Valider, rejeter, rouvrir, déclarer irrésoluble : data manager (revue indépendante), ou la personne à qui la
     * validation de cette query est confiée (superviseur, validateur).
     */
    public function validate(User $user, DataQuery $query): bool
    {
        return ($user->can('validate queries') && $this->inScope($user, $query))
            || ($query->reviewer_id !== null && (int) $query->reviewer_id === (int) $user->id && $user->can('review queries'));
    }

    /** Confier la validation : data manager, administrateur. */
    public function assignReview(User $user, DataQuery $query): bool
    {
        return $user->can('validate queries') && $this->inScope($user, $query);
    }

    /** Query collectée par le binôme de l'utilisateur (compte binôme ou superviseur agent du binôme). */
    private function ownTeam(User $user, DataQuery $query): bool
    {
        if ($user->team_id === null) {
            return false;
        }

        // Query qui désigne un binôme (ex. celui du compte ODK qui a soumis la donnée) : ce binôme seulement.
        if ($query->team_id !== null) {
            return (int) $query->team_id === (int) $user->team_id;
        }

        return $query->collector_id !== null && (int) $query->collector?->team_id === (int) $user->team_id;
    }

    private function inScope(User $user, DataQuery $query): bool
    {
        $scope = $user->geographicScope();

        return ! $scope || (int) $query->{$scope['column']} === (int) $scope['id'];
    }
}
