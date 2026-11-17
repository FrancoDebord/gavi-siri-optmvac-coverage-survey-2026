<?php

namespace App\Http\Controllers;

use App\Models\DataCorrection;
use App\Models\HealthZone;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Registre des corrections de la base nettoyée (piste d'audit) : valeur reçue d'ODK, valeur remplacée, nouvelle valeur,
 * motif et source, auteur, décision et signature. Lecture seule.
 */
class DataCorrectionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'status' => array_key_exists((string) $request->input('status'), DataCorrection::STATUSES) ? $request->input('status') : null,
            'health_zone_id' => $request->integer('health_zone_id') ?: null,
            'variable' => trim((string) $request->input('variable')) ?: null,
            'du' => DateInput::parse($request->input('du')),
            'au' => DateInput::parse($request->input('au')),
        ];
        $base = DataCorrection::query()
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['health_zone_id'], fn ($q, $zone) => $q->whereHas('household', fn ($h) => $h->where('health_zone_id', $zone)))
            ->when($filters['variable'], fn ($q, $variable) => $q->where('variable', 'like', str_replace(['%', '_'], ['\%', '\_'], $variable).'%'))
            ->when($filters['du'], fn ($q, $date) => $q->whereDate('proposed_at', '>=', $date))
            ->when($filters['au'], fn ($q, $date) => $q->whereDate('proposed_at', '<=', $date));

        return view('corrections.index', [
            'filters' => $filters,
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'counts' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'corrections' => $base->with(['household:id,household_code,health_zone_id', 'household.healthZone:id,name', 'proposer:id,name', 'decider:id,name'])
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }
}
