<?php

namespace App\Http\Controllers;

use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Models\SupervisionObservation;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Observations de supervision : faits relevés sur les paradonnées (heures, durées, volumes, délais d'envoi, GPS).
 * À examiner avec les binômes ; ce ne sont pas des queries de données (aucune valeur à corriger).
 */
class SupervisionObservationController extends Controller
{
    public function index(Request $request): View
    {
        $rules = QualityRule::where('kind', QualityRule::KIND_OBSERVATION)->orderBy('code')->get(['code', 'name']);
        $filters = [
            'health_zone_id' => $request->integer('health_zone_id') ?: null,
            'rule_code' => $rules->contains('code', $request->input('rule_code')) ? $request->input('rule_code') : null,
            'du' => DateInput::parse($request->input('du')),
            'au' => DateInput::parse($request->input('au')),
            'actives' => $request->input('actives', '1') === '1',
        ];

        $base = SupervisionObservation::visibleTo($request->user())
            ->when($filters['health_zone_id'], fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->when($filters['rule_code'], fn ($q, $code) => $q->where('rule_code', $code))
            ->when($filters['du'], fn ($q, $date) => $q->whereDate('collection_date', '>=', $date))
            ->when($filters['au'], fn ($q, $date) => $q->whereDate('collection_date', '<=', $date))
            // Active : toujours constatée à la dernière vérification (non disparue après correction ou réglage).
            ->when($filters['actives'], fn ($q) => $q->where('auto_closed', false));

        return view('observations.index', [
            'rules' => $rules,
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'byRule' => (clone $base)->selectRaw('rule_code, count(*) as n')->groupBy('rule_code')->pluck('n', 'rule_code'),
            'observations' => $base->with(['healthZone:id,name', 'village:id,name', 'collector.team:id,code'])
                ->orderByDesc('collection_date')->orderByDesc('id')->paginate(50)->withQueryString(),
        ]);
    }
}
