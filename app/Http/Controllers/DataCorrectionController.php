<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\AuditLog;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Services\Cleaning\CleanDataService;
use App\Support\DateInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
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
            'toReview' => DataCorrection::where('auto_applied', true)->whereNull('reviewed_at')->where('status', DataCorrection::STATUS_VALIDATED)->count(),
            'corrections' => $base->with(['household:id,household_code,health_zone_id', 'household.healthZone:id,name', 'proposer:id,name', 'decider:id,name'])
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    /** Revue a posteriori : la correction appliquée immédiatement est acceptée. */
    public function accept(DataCorrection $correction, CleanDataService $cleaning): RedirectResponse
    {
        $cleaning->markReviewed([$correction], request()->user());
        AuditLog::record('correction.acceptee', $correction);

        return back()->with('success', 'Correction acceptée.');
    }

    /** Toutes les corrections « à revoir » affichées (filtres) sont acceptées. */
    public function acceptAll(Request $request, CleanDataService $cleaning): RedirectResponse
    {
        [, $base] = $this->filtered($request);
        $count = $cleaning->markReviewed($base->where('auto_applied', true)->whereNull('reviewed_at')->get(), $request->user());
        AuditLog::record('correction.acceptees_en_bloc', null, ['nombre' => $count]);

        return back()->with('success', "{$count} correction(s) acceptée(s).");
    }

    /** Refus : la valeur précédente est rétablie et la query rouvre si l'erreur revient. */
    public function refuse(Request $request, DataCorrection $correction, CleanDataService $cleaning): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], [], ['reason' => 'motif'])['reason'];
        try {
            $cleaning->revoke($correction, $request->user(), $reason);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }
        AuditLog::record('correction.refusee', $correction, ['motif' => $reason]);

        return back()->with('success', 'Correction refusée : la valeur précédente est rétablie.');
    }

    /** Registre en Excel, avec les filtres affichés. */
    public function export(Request $request): BinaryFileResponse
    {
        [, $base] = $this->filtered($request);
        $rows = $base->with(['household:id,household_code', 'proposer:id,name', 'decider:id,name'])->orderBy('id')->get()->map(fn (DataCorrection $c) => [
            'C-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT), $c->household?->household_code, $c->submission_uuid, $c->data_query_id ? DataQuery::codeFor($c->data_query_id) : null,
            // Codes bruts (analyse) et libellés des choix à côté (lecture).
            $c->variableLabel(), $c->instance_path, $c->source_value, $c->choiceLabel($c->source_value), $c->old_value, $c->choiceLabel($c->old_value),
            $c->new_value, $c->choiceLabel($c->new_value), $c->reasonLabel(), $c->source_reference, $c->comment, $c->statusLabel(),
            $c->proposer?->name, $c->proposed_at?->format('d/m/Y H:i'), $c->decider?->name, $c->decided_at?->format('d/m/Y H:i'), $c->signed ? 'oui' : 'non', $c->decision_comment,
        ])->all();
        AuditLog::record('export.registre_corrections', null, ['lignes' => count($rows)]);

        return Excel::download(new ArrayExport($rows, ['N°', 'ID ménage', 'Soumission', 'Query', 'Variable', 'Membre', 'Valeur ODK', 'Libellé ODK', 'Avant', 'Libellé avant', 'Après', 'Libellé après', 'Motif', 'Source', 'Commentaire',
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
            // Corrections appliquées immédiatement, pas encore revues par le data manager.
            'a_revoir' => $request->boolean('a_revoir'),
        ];
        $base = DataCorrection::query()
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['a_revoir'], fn ($q) => $q->where('auto_applied', true)->whereNull('reviewed_at')->where('status', DataCorrection::STATUS_VALIDATED))
            ->when($filters['health_zone_id'], fn ($q, $zone) => $q->whereHas('household', fn ($h) => $h->where('health_zone_id', $zone)))
            ->when($filters['variable'], fn ($q, $variable) => $q->where('variable', 'like', str_replace(['%', '_'], ['\%', '\_'], $variable).'%'))
            ->when($filters['du'], fn ($q, $date) => $q->whereDate('proposed_at', '>=', $date))
            ->when($filters['au'], fn ($q, $date) => $q->whereDate('proposed_at', '<=', $date));

        return [$filters, $base];
    }
}
