<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Models\SupervisionObservation;
use App\Services\Quality\ObservationEscalation;
use App\Support\DateInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Observations de supervision : faits relevés sur les paradonnées (heures, durées, volumes, délais d'envoi, GPS).
 * À examiner avec les binômes ; ce ne sont pas des queries de données (aucune valeur à corriger). L'administrateur
 * (ou le data manager) peut en envoyer dans les queries pour qu'elles soient résolues selon le cycle normal.
 */
class SupervisionObservationController extends Controller
{
    /** Nombre maximal d'observations envoyées en une fois. */
    public const ESCALATE_MAX = 2000;

    public function index(Request $request): View
    {
        $rules = $this->rules();
        $filters = $this->filters($request, $rules->pluck('code')->all());
        $base = $this->filtered($request, $filters);
        $user = $request->user();

        return view('observations.index', [
            'rules' => $rules,
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'byRule' => (clone $base)->selectRaw('rule_code, count(*) as n')->groupBy('rule_code')->pluck('n', 'rule_code'),
            'observations' => $base->with(['healthZone:id,name', 'village:id,name', 'collector.team:id,code'])
                ->orderByDesc('collection_date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'canEscalate' => $user->can('validate queries'),
            'escalatable' => (clone $base)->where('auto_closed', false)->whereIn('status', DataQuery::UNRESOLVED)->count(),
            'escalated' => DataQuery::visibleTo($user)->whereNotNull('escalated_at')
                ->selectRaw('count(*) as total, sum(case when status in (?, ?) then 1 else 0 end) as open', DataQuery::UNRESOLVED)->first(),
        ]);
    }

    /**
     * Envoi dans les queries : observations cochées, ou toutes celles des filtres affichés (encore constatées).
     */
    public function escalate(Request $request, ObservationEscalation $escalation): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:'.self::ESCALATE_MAX],
            'ids.*' => ['integer'],
            'all' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['ids' => 'observations', 'note' => 'consigne']);

        $rules = $this->rules();
        $base = $request->boolean('all')
            ? $this->filtered($request, $this->filters($request, $rules->pluck('code')->all()))
            : SupervisionObservation::visibleTo($request->user())->whereKey($data['ids'] ?? []);
        $observations = $base->where('auto_closed', false)->whereIn('status', DataQuery::UNRESOLVED)
            ->with('collector:id,team_id')->orderBy('id')->limit(self::ESCALATE_MAX)->get();
        if ($observations->isEmpty()) {
            return back()->withErrors(['escalate' => 'Aucune observation à envoyer : cochez des observations encore constatées.']);
        }

        $result = $escalation->escalate($observations, $request->user(), $data['note'] ?? null);
        AuditLog::record('observations.escalated', null, ['envoyees' => $result['sent'], 'ignorees' => $result['skipped'], 'consigne' => $data['note'] ?? null]);

        return back()->with('success', trans_choice(
            '{0} Aucune observation envoyée.|{1} :n observation envoyée dans les queries : le binôme peut la résoudre depuis son espace.|[2,*] :n observations envoyées dans les queries : les binômes peuvent les résoudre depuis leur espace.',
            $result['sent'], ['n' => $result['sent']],
        ).($result['skipped'] ? " {$result['skipped']} ignorée(s) (déjà traitées ou hors de votre périmètre)." : ''));
    }

    private function rules()
    {
        return QualityRule::where('kind', QualityRule::KIND_OBSERVATION)->orderBy('code')->get(['code', 'name']);
    }

    /** @param  list<string>  $codes */
    private function filters(Request $request, array $codes): array
    {
        return [
            'health_zone_id' => $request->integer('health_zone_id') ?: null,
            'rule_code' => in_array($request->input('rule_code'), $codes, true) ? $request->input('rule_code') : null,
            'du' => DateInput::parse($request->input('du')),
            'au' => DateInput::parse($request->input('au')),
            'actives' => $request->input('actives', '1') === '1',
        ];
    }

    private function filtered(Request $request, array $filters): Builder
    {
        return SupervisionObservation::visibleTo($request->user())
            ->when($filters['health_zone_id'], fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->when($filters['rule_code'], fn ($q, $code) => $q->where('rule_code', $code))
            ->when($filters['du'], fn ($q, $date) => $q->whereDate('collection_date', '>=', $date))
            ->when($filters['au'], fn ($q, $date) => $q->whereDate('collection_date', '<=', $date))
            // Active : toujours constatée à la dernière vérification (non disparue après correction ou réglage).
            ->when($filters['actives'], fn ($q) => $q->where('auto_closed', false));
    }
}
