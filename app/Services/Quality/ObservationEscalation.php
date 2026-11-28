<?php

namespace App\Services\Quality;

use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Models\QueryEvent;
use App\Models\SupervisionObservation;
use App\Models\User;
use App\Support\DashboardCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Envoi d'observations de supervision dans les queries (administrateur, data manager) : l'observation devient une query
 * (même ligne, même historique) et suit le cycle de résolution normal. Elle est confiée au compte du binôme concerné
 * quand l'affectation automatique est active ; le binôme peut de toute façon y répondre depuis son espace.
 * Seules les observations encore constatées et non traitées sont envoyées.
 */
class ObservationEscalation
{
    public function __construct(private readonly QueryWorkflow $workflow) {}

    /**
     * @param  iterable<SupervisionObservation>  $observations
     * @return array{sent: int, skipped: int}
     */
    public function escalate(iterable $observations, User $user, ?string $note = null): array
    {
        $note = trim((string) $note) ?: null;
        $autoAssign = AppSetting::get(AppSetting::AUTO_ASSIGN) === 'team';
        $accounts = $autoAssign
            ? User::role(User::ROLE_TEAM)->whereNotNull('team_id')->orderBy('id')->get()->unique('team_id')->keyBy('team_id')
            : collect();
        $sent = $skipped = 0;

        foreach ($observations as $observation) {
            if ($observation->kind !== SupervisionObservation::KIND || $observation->auto_closed
                || ! in_array($observation->status, DataQuery::UNRESOLVED, true) || Gate::forUser($user)->denies('validate', $observation)) {
                $skipped++;

                continue;
            }
            DB::transaction(function () use ($observation, $user, $note, $accounts) {
                $observation->forceFill(['kind' => DataQuery::KIND, 'escalated_at' => now(), 'escalated_by' => $user->id])->save();
                $query = DataQuery::findOrFail($observation->id);
                QueryEvent::create([
                    'data_query_id' => $query->id, 'user_id' => $user->id, 'type' => 'system',
                    'comment' => 'Observation de supervision envoyée dans les queries : à résoudre par le binôme.'.($note ? ' Consigne : '.$note : ''),
                ]);
                $teamId = $query->team_id ?? $query->collector?->team_id;
                $account = $teamId ? $accounts->get($teamId) : null;
                if ($account && ! $query->assigned_to && Gate::forUser($account)->allows('view', $query)) {
                    $this->workflow->assign($query, $account, null, $user, $note);
                }
            });
            $sent++;
        }
        DashboardCache::bump();

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
