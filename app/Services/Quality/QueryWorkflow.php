<?php

namespace App\Services\Quality;

use App\Models\DataQuery;
use App\Models\QueryEvent;
use App\Models\User;
use App\Support\DashboardCache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cycle de vie d'une query :
 * Ouverte → Envoyée au binôme → Justifiée / Corrigée → Validée (fermée), avec historique.
 */
class QueryWorkflow
{
    public function transition(DataQuery $query, string $status, ?string $comment, ?User $user): DataQuery
    {
        if ($status === $query->status) {
            if ($comment) {
                $this->comment($query, $comment, $user);
            }

            return $query;
        }
        if (! $query->canTransitionTo($status)) {
            throw new InvalidArgumentException(sprintf(
                'Transition impossible : « %s » → « %s ».',
                $query->statusLabel(),
                DataQuery::STATUSES[$status] ?? $status,
            ));
        }
        if ($status === DataQuery::STATUS_JUSTIFIED && ! trim((string) $comment)) {
            throw new InvalidArgumentException('Une justification (commentaire) est obligatoire pour ce statut.');
        }
        if ($status === DataQuery::STATUS_UNRESOLVABLE && ! trim((string) $comment)) {
            throw new InvalidArgumentException('Indiquez pourquoi la query est irrésoluble (ménage injoignable, carnet perdu…).');
        }

        DB::transaction(function () use ($query, $status, $comment, $user) {
            $from = $query->status;
            $query->status = $status;
            $query->auto_closed = false;
            // Date de résolution : premier passage en statut résolu ou irrésoluble ; effacée si la query est rouverte.
            $query->resolved_at = DataQuery::resolutionOf($status) === DataQuery::RESOLUTION_UNRESOLVED
                ? null
                : ($query->resolved_at ?? now());
            $query->closed_at = in_array($status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE], true) ? now() : null;
            $query->save();

            QueryEvent::create([
                'data_query_id' => $query->id,
                'user_id' => $user?->id,
                'type' => 'status',
                'from_status' => $from,
                'to_status' => $status,
                'comment' => $comment ? trim($comment) : null,
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
            DataQuery::STATUS_OPEN => 'Rouvrir la query.',
            DataQuery::STATUS_SENT => 'La query a été transmise au binôme pour vérification sur le terrain.',
            DataQuery::STATUS_JUSTIFIED => 'La valeur est correcte : saisissez la justification du binôme.',
            DataQuery::STATUS_CORRECTED => 'La donnée a été corrigée (sur la tablette ou dans ODK/Kobo).',
            DataQuery::STATUS_VALIDATED => 'Le data manager valide la résolution : la query est fermée.',
            DataQuery::STATUS_UNRESOLVABLE => 'La donnée ne peut être ni corrigée ni confirmée : indiquez le motif. La query est fermée.',
            default => '',
        };
    }
}
