<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\AuditLog;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Registre des corrections de la base nettoyée (piste d'audit) : valeur reçue d'ODK, valeur remplacée, nouvelle valeur,
 * motif et source, auteur, décision et signature. Lecture seule.
 */
class DataCorrectionController extends Controller
{
    public function index(Request $request): View
    {
        [$filters, $base] = $this->filtered($request);

        return view('corrections.index', [
            'filters' => $filters,
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'counts' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'corrections' => $base->with(['household:id,household_code,health_zone_id', 'household.healthZone:id,name', 'proposer:id,name', 'decider:id,name'])
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    /** Registre en Excel, avec les filtres affichés. */
    public function export(Request $request): BinaryFileResponse
    {
        [, $base] = $this->filtered($request);
        $rows = $base->with(['household:id,household_code', 'proposer:id,name', 'decider:id,name'])->orderBy('id')->get()->map(fn (DataCorrection $c) => [
            'C-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT), $c->household?->household_code, $c->submission_uuid, $c->data_query_id ? DataQuery::codeFor($c->data_query_id) : null,
            $c->variableLabel(), $c->instance_path, $c->source_value, $c->old_value, $c->new_value, $c->reasonLabel(), $c->source_reference, $c->comment, $c->statusLabel(),
            $c->proposer?->name, $c->proposed_at?->format('d/m/Y H:i'), $c->decider?->name, $c->decided_at?->format('d/m/Y H:i'), $c->signed ? 'oui' : 'non', $c->decision_comment,
        ])->all();
        AuditLog::record('export.registre_corrections', null, ['lignes' => count($rows)]);

        return Excel::download(new ArrayExport($rows, ['N°', 'ID ménage', 'Soumission', 'Query', 'Variable', 'Membre', 'Valeur ODK', 'Avant', 'Après', 'Motif', 'Source', 'Commentaire',
            'Statut', 'Proposée par', 'Proposée le', 'Décidée par', 'Décidée le', 'Signée', 'Commentaire de décision'], 'Registre des corrections'), 'registre-corrections-'.now()->format('Ymd-Hi').'.xlsx');
    }

    private function filtered(Request $request): array
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

        return [$filters, $base];
    }
}
