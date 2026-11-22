<?php

namespace App\Services\Quality;

use App\Models\AppSetting;
use App\Models\DataCorrection;
use App\Models\DataLock;
use App\Models\DataQuery;
use App\Models\QueryEvent;
use App\Models\User;
use App\Services\Cleaning\CleanDataService;
use App\Support\DashboardCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Cycle de vie d'une query (BPC/GCP) :
 * Ouverte → Assignée → Réponse du binôme (correction proposée / valeur confirmée)
 *   → Vérification du superviseur de la zone (validée par le superviseur, ou rejet : retour au binôme)
 *   → Validation finale de l'administrateur / data manager (à l'unité ou en bloc) → Fermée.
 * L'administrateur et le data manager peuvent aussi faire la vérification, valider directement une réponse, rejeter,
 * déclarer la query irrésoluble ou la rouvrir.
 * Double validation (sévérités paramétrables, critiques par défaut) : le validateur n'est pas l'auteur de la réponse
 * et signe en ressaisissant son mot de passe. Chaque action est tracée (auteur, date, motif, signature).
 *
 * Correction proposée : la réponse porte les nouvelles valeurs (registre des corrections). À la validation, elles sont
 * appliquées à la base nettoyée et le moteur relance les règles : la query est résolue si l'anomalie a disparu,
 * sinon elle revient à l'assigné. Les données ODK ne sont jamais modifiées.
 */
class QueryWorkflow
{
    /** @var list<DataQuery> queries dont les corrections viennent d'être validées, à vérifier par le moteur */
    private array $toVerify = [];

    private bool $deferring = false;

    public function __construct(private readonly CleanDataService $cleaning) {}

    /**
     * L'utilisateur peut-il faire passer la query à ce statut ? Réponse : le binôme ou l'équipe de traitement ;
     * vérification du superviseur et rejet d'une réponse : superviseur de la zone (ou administrateur / data manager) ;
     * validation finale, rejet après vérification, irrésoluble, réouverture : administrateur / data manager.
     */
    public static function allowed(User $user, DataQuery $query, string $status): bool
    {
        $gate = Gate::forUser($user);

        return match (true) {
            in_array($status, DataQuery::RESPONSES, true) => $gate->allows('respond', $query),
            $status === DataQuery::STATUS_SUPERVISED => $gate->allows('supervise', $query),
            $status === DataQuery::STATUS_OPEN && in_array($query->status, DataQuery::RESPONSES, true) => $gate->allows('supervise', $query),
            default => $gate->allows('validate', $query),
        };
    }

    /**
     * Vérifications groupées (validation en bloc) : une seule reconstruction et un seul passage du moteur.
     */
    public function deferVerification(callable $callback, ?User $user): mixed
    {
        $this->deferring = true;
        try {
            return $callback();
        } finally {
            $this->deferring = false;
            $this->verify($user);
        }
    }

    private function verify(?User $user): void
    {
        $queries = $this->toVerify;
        $this->toVerify = [];
        $this->cleaning->applyAndVerify($queries, $user);
    }

    /**
     * @param  list<array<string, mixed>>  $corrections  nouvelles valeurs (réponse « correction proposée »)
     */
    public function transition(DataQuery $query, string $status, ?string $comment, ?User $user, ?string $password = null, array $corrections = []): DataQuery
    {
        $this->lastAutoApply = null;
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
        if ($user && ! self::allowed($user, $query, $status)) {
            throw new InvalidArgumentException(match (true) {
                $isResponse => 'Vous ne pouvez pas répondre à cette query (elle n\'est pas assignée à votre compte).',
                $status === DataQuery::STATUS_SUPERVISED => 'La vérification revient au superviseur de la zone (jamais l\'auteur de la réponse).',
                $status === DataQuery::STATUS_OPEN && in_array($query->status, DataQuery::RESPONSES, true) => 'Seul le superviseur de la zone, l\'administrateur ou le data manager peut rejeter cette réponse.',
                default => 'La validation finale, l\'irrésolubilité et la réouverture reviennent à l\'administrateur ou au data manager.',
            });
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

        // Corrections proposées en attente : réponse « correction », vérifiée ou non par le superviseur.
        $withCorrections = in_array($query->status, [DataQuery::STATUS_CORRECTED, DataQuery::STATUS_SUPERVISED], true)
            && DataCorrection::where('data_query_id', $query->id)->where('status', DataCorrection::STATUS_PROPOSED)->exists();
        // Base gelée : aucune modification des données (proposition ou application de correction).
        if (($status === DataQuery::STATUS_CORRECTED || ($status === DataQuery::STATUS_VALIDATED && $withCorrections)) && ($lock = DataLock::current())) {
            throw new InvalidArgumentException("Base gelée (version {$lock->label()} depuis le {$lock->created_at->format('d/m/Y')}) : aucune correction possible avant le dégel par l'administrateur.");
        }
        // Validation d'une correction : appliquée à la base nettoyée, la query est conclue par le moteur.
        if ($status === DataQuery::STATUS_VALIDATED && $withCorrections) {
            DB::transaction(function () use ($query, $comment, $user, $signed) {
                $count = $this->cleaning->decide($query, true, $user, $signed, $comment);
                $query->update(['validated_by' => $user?->id]);
                QueryEvent::create([
                    'data_query_id' => $query->id, 'user_id' => $user?->id, 'type' => 'correction', 'signed' => $signed,
                    'comment' => trim("{$count} correction(s) validée(s) et appliquée(s) à la base nettoyée ; vérification par le moteur. ".($comment ?? '')),
                ]);
            });
            $this->toVerify[] = $query;
            if (! $this->deferring) {
                $this->verify($user);
            }

            return $query->refresh();
        }

        DB::transaction(function () use ($query, $status, $comment, $user, $isResponse, $signed, $corrections, $withCorrections) {
            $from = $query->status;
            if ($status === DataQuery::STATUS_CORRECTED) {
                $this->cleaning->propose($query, $corrections, $user);
            } elseif ($withCorrections && $status !== DataQuery::STATUS_SUPERVISED) {
                // Rejet, réouverture ou irrésoluble : les corrections proposées sont rejetées (conservées au registre).
                // Vérification du superviseur : elles restent proposées jusqu'à la validation finale.
                $this->cleaning->decide($query, false, $user, $signed, $comment);
                $this->cleaning->releaseDependents($query, $user, 'La correction proposée dans la query d\'origine a été refusée');
            }
            // Query qui suivait la correction d'une autre : le lien tombe si elle est traitée autrement.
            if ($query->resolved_by_query_id && ! in_array($status, [DataQuery::STATUS_SUPERVISED, DataQuery::STATUS_VALIDATED], true)) {
                $query->resolved_by_query_id = null;
            }
            // Rejet ou réouverture : la query revient à son assigné s'il y en a un.
            $status = $status === DataQuery::STATUS_OPEN && $query->assigned_to ? DataQuery::STATUS_SENT : $status;
            $closing = in_array($status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE], true);

            $query->status = $status;
            $query->auto_closed = false;
            if ($isResponse) {
                $query->responded_by = $user?->id;
                $query->responded_at = now();
                $query->supervised_by = $query->supervised_at = null;
            } elseif ($status === DataQuery::STATUS_SUPERVISED) {
                // Vérification du superviseur : la réponse attend la validation finale ; rien n'est encore appliqué.
                $query->supervised_by = $user?->id;
                $query->supervised_at = now();
            } elseif (! $closing) {
                $query->responded_by = $query->responded_at = null;
                $query->supervised_by = $query->supervised_at = null;
            }
            $query->validated_by = $closing ? $user?->id : null;
            if ($status === DataQuery::STATUS_SUPERVISED) {
                $query->validated_by = null;
            }
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

        // Correction proposée : appliquée tout de suite si elle lève l'erreur sans en créer (paramètre, revue a posteriori).
        if ($status === DataQuery::STATUS_CORRECTED && AppSetting::get(AppSetting::AUTO_APPLY)
            && ! collect($corrections)->contains(fn ($row) => ($row['variable'] ?? null) === DataCorrection::EXCLUSION)) {
            $result = $this->cleaning->tryAutoApply($query, $user);
            QueryEvent::create([
                'data_query_id' => $query->id, 'user_id' => null, 'type' => $result['applied'] ? 'correction' : 'system',
                'comment' => $result['applied']
                    ? 'Correction prise en compte immédiatement : l\'erreur est levée et aucune nouvelle query n\'apparaît. Le data manager peut la refuser.'
                    : 'Correction enregistrée, en attente du data manager : '.$result['reason'].'.',
            ]);
            $this->lastAutoApply = $result;
        }

        return $query;
    }

    /** Résultat de la dernière application immédiate (pour le message à l'utilisateur). */
    public ?array $lastAutoApply = null;

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

    /**
     * Confie la validation de la query à un superviseur ou à un validateur (null = la reprend) ; tracé.
     */
    public function assignReview(DataQuery $query, ?User $reviewer, User $user, ?string $comment = null): DataQuery
    {
        if ($reviewer && ! $reviewer->hasAnyRole([User::ROLE_SUPERVISOR, User::ROLE_VALIDATOR, User::ROLE_DATA_MANAGER, User::ROLE_ADMIN])) {
            throw new InvalidArgumentException("{$reviewer->name} n'a pas de rôle de validation (superviseur, validateur, data manager).");
        }
        if (in_array($query->status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_UNRESOLVABLE], true)) {
            throw new InvalidArgumentException('Query fermée : rien à valider.');
        }
        DB::transaction(function () use ($query, $reviewer, $user, $comment) {
            $query->update(['reviewer_id' => $reviewer?->id, 'review_assigned_by' => $user->id, 'review_assigned_at' => now()]);
            QueryEvent::create([
                'data_query_id' => $query->id, 'user_id' => $user->id, 'type' => 'assignment',
                'comment' => ($reviewer ? 'Validation confiée à '.$reviewer->name : 'Validation reprise par le data manager').($comment ? ' — '.trim($comment) : ''),
            ]);
        });

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
            DataQuery::STATUS_OPEN => 'Retour au binôme. Motif obligatoire.',
            DataQuery::STATUS_JUSTIFIED => 'La valeur est juste. Indiquez la source vérifiée (carnet, photo, revisite).',
            DataQuery::STATUS_CORRECTED => 'Saisissez la bonne valeur, le motif et la source.',
            DataQuery::STATUS_SUPERVISED => 'Réponse vérifiée : elle part en validation finale.',
            DataQuery::STATUS_VALIDATED => 'Validation finale : les corrections sont appliquées et la query est fermée.',
            DataQuery::STATUS_UNRESOLVABLE => 'Ni corrigeable ni confirmable. Motif obligatoire ; la query est fermée.',
            default => '',
        };
    }
}
