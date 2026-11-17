<?php

namespace App\Services\Quality;

use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Models\QueryEvent;
use App\Models\User;
use App\Support\DashboardCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Cycle de vie d'une query (BPC/GCP) :
 * Ouverte → Assignée → Réponse de l'assigné (correction proposée / valeur confirmée) → Validation du data manager → Fermée.
 * Le data manager peut rejeter une réponse (retour à l'assigné), déclarer la query irrésoluble ou la rouvrir.
 * Double validation (sévérités paramétrables, critiques par défaut) : le validateur n'est pas l'auteur de la réponse
 * et signe en ressaisissant son mot de passe. Chaque action est tracée (auteur, date, motif, signature).
 */
class QueryWorkflow
{
    public function transition(DataQuery $query, string $status, ?string $comment, ?User $user, ?string $password = null): DataQuery
    {
        $comment = trim((string) $comment) ?: null;
        if ($status === $query->status) {
            if ($comment) {
                $this->comment($query, $comment, $user);
            }

            return $query;
        }
        if (! $query->canTransitionTo($status)) {
            throw new InvalidArgumentException(sprintf('Transition impossible : « %s » → « %s ».', $query->statusLabel(), DataQuery::STATUSES[$status] ?? $status));
        }

        $isResponse = in_array($status, DataQuery::RESPONSES, true);
        if ($user) {
            $ability = $isResponse ? 'respond' : 'validate';
            if (Gate::forUser($user)->denies($ability, $query)) {
                throw new InvalidArgumentException($isResponse
                    ? 'Vous ne pouvez pas répondre à cette query (elle n\'est pas assignée à votre compte).'
                    : 'Seul le data manager peut valider, rejeter, rouvrir ou déclarer irrésoluble une query.');
            }
        }
        if (! $comment && in_array($status, [DataQuery::STATUS_JUSTIFIED, DataQuery::STATUS_UNRESOLVABLE, DataQuery::STATUS_OPEN], true)) {
            throw new InvalidArgumentException(match ($status) {
                DataQuery::STATUS_JUSTIFIED => 'Indiquez la justification : pourquoi la valeur est exacte, et d\'après quelle source (carnet, photo, revisite…).',
                DataQuery::STATUS_UNRESOLVABLE => 'Indiquez pourquoi la query est irrésoluble (ménage injoignable, carnet perdu…).',
                default => 'Indiquez le motif du rejet ou de la réouverture.',
            });
        }

        $signed = false;
        if ($user && in_array($status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE], true) && AppSetting::requiresDoubleValidation($query->severity)) {
            if ($status === DataQuery::STATUS_VALIDATED && (int) $query->responded_by === (int) $user->id) {
                throw new InvalidArgumentException('Double validation : la réponse doit être validée par une autre personne que son auteur.');
            }
            if (! $password || ! Hash::check($password, $user->password)) {
                throw new InvalidArgumentException('Signature électronique : saisissez votre mot de passe pour valider une query '.$query->severity.'.');
            }
            $signed = true;
        }

        DB::transaction(function () use ($query, $status, $comment, $user, $isResponse, $signed) {
            $from = $query->status;
            // Rejet ou réouverture : la query revient à son assigné s'il y en a un.
            $status = $status === DataQuery::STATUS_OPEN && $query->assigned_to ? DataQuery::STATUS_SENT : $status;
            $closing = in_array($status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE], true);

            $query->status = $status;
            $query->auto_closed = false;
            if ($isResponse) {
                $query->responded_by = $user?->id;
                $query->responded_at = now();
            } elseif (! $closing) {
                $query->responded_by = $query->responded_at = null;
            }
            $query->validated_by = $closing ? $user?->id : null;
            $query->resolved_at = $closing ? now() : null;
            $query->closed_at = $closing ? now() : null;
            $query->save();

            QueryEvent::create([
                'data_query_id' => $query->id,
                'user_id' => $user?->id,
                'type' => 'status',
                'from_status' => $from,
                'to_status' => $status,
                'comment' => $comment,
                'signed' => $signed,
            ]);
        });
        DashboardCache::bump();

        return $query;
    }

    /**
     * Affecte (ou désaffecte) la query : l'assigné doit pouvoir la consulter. Ouverte ↔ Assignée selon le cas ;
     * échéance donnée, sinon échéance par défaut des paramètres.
     */
    public function assign(DataQuery $query, ?User $assignee, ?string $dueOn, User $user, ?string $comment = null): DataQuery
    {
        if ($assignee && Gate::forUser($assignee)->denies('view', $query)) {
            throw new InvalidArgumentException("{$assignee->name} n'a pas accès à cette query (zone ou binôme différent).");
        }
        if ($query->status === DataQuery::STATUS_VALIDATED || $query->status === DataQuery::STATUS_UNRESOLVABLE) {
            throw new InvalidArgumentException('Query fermée : rouvrez-la avant de la réaffecter.');
        }
        $days = (int) AppSetting::get(AppSetting::DUE_DAYS);
        $due = $assignee ? ($dueOn ?: ($days > 0 ? CarbonImmutable::today()->addDays($days)->toDateString() : null)) : null;

        DB::transaction(function () use ($query, $assignee, $due, $user, $comment) {
            $from = $query->status;
            $query->assigned_to = $assignee?->id;
            $query->assigned_by = $user->id;
            $query->assigned_at = now();
            $query->due_on = $due;
            if ($assignee && $query->status === DataQuery::STATUS_OPEN) {
                $query->status = DataQuery::STATUS_SENT;
            } elseif (! $assignee && $query->status === DataQuery::STATUS_SENT) {
                $query->status = DataQuery::STATUS_OPEN;
            }
            $query->save();

            $text = $assignee
                ? 'Assignée à '.$assignee->name.($due ? ' · échéance le '.CarbonImmutable::parse($due)->format('d/m/Y') : '')
                : 'Affectation retirée';
            QueryEvent::create([
                'data_query_id' => $query->id,
                'user_id' => $user->id,
                'type' => 'assignment',
                'from_status' => $from !== $query->status ? $from : null,
                'to_status' => $from !== $query->status ? $query->status : null,
                'comment' => $text.($comment ? ' — '.trim($comment) : ''),
            ]);
        });
        DashboardCache::bump();

        return $query;
    }

    public function comment(DataQuery $query, string $comment, ?User $user): QueryEvent
    {
        return QueryEvent::create([
            'data_query_id' => $query->id,
            'user_id' => $user?->id,
            'type' => 'comment',
            'comment' => trim($comment),
        ]);
    }

    /** Aide affichée dans l'interface pour chaque statut cible. */
    public static function help(string $status): string
    {
        return match ($status) {
            DataQuery::STATUS_OPEN => 'Rejeter la réponse ou rouvrir : la query revient à l\'assigné (motif obligatoire).',
            DataQuery::STATUS_JUSTIFIED => 'La valeur est exacte : indiquez la justification et la source vérifiée. Le data manager validera.',
            DataQuery::STATUS_CORRECTED => 'La valeur doit être corrigée : décrivez la correction et sa source. Le data manager validera.',
            DataQuery::STATUS_VALIDATED => 'Le data manager accepte la réponse : la query est fermée.',
            DataQuery::STATUS_UNRESOLVABLE => 'La donnée ne peut être ni corrigée ni confirmée : motif obligatoire. La query est fermée.',
            default => '',
        };
    }
}
