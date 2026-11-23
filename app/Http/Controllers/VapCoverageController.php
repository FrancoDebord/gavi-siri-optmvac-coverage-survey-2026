<?php

namespace App\Http\Controllers;

use App\Exports\CoverageWorkbook;
use App\Models\AuditLog;
use App\Services\Dashboard\CoverageReport;
use App\Services\Dashboard\VapCoverageStats;
use App\Support\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Couverture du vaccin antipaludique (VAP 1 à 4) : indicateurs, tableaux et graphiques, filtres du tableau de bord. */
class VapCoverageController extends Controller
{
    public function index(): View
    {
        return view('dashboard.vap-coverage', [
            'vap4Ages' => ['Tchaourou' => VapCoverageStats::vap4MinMonths('1'), 'Dassa-Glazoué' => VapCoverageStats::vap4MinMonths('2')],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json((new VapCoverageStats(Filters::fromRequest($request)))->all());
    }

    /** Rapport PDF : indicateurs, barres par dose, détail et tableaux par niveau (filtres courants). */
    public function pdf(Request $request): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $filters = Filters::fromRequest($request);
        AuditLog::record('export.couverture_vap', null, ['format' => 'pdf', 'filtres' => $filters->values]);

        return CoverageReport::vap((new VapCoverageStats($filters))->all(), $filters);
    }

    /**
     * Export Excel (filtres courants), une feuille par niveau : zone ; zone et commune ; zone, commune et village ;
     * âge et sexe. Pour chaque dose : reçues, dénominateur, couverture.
     */
    public function export(Request $request): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $filters = Filters::fromRequest($request);
        $stats = new VapCoverageStats($filters);
        AuditLog::record('export.couverture_vap', null, ['filtres' => $filters->values]);

        $columns = [];
        foreach (VapCoverageStats::DOSES as $k) {
            $columns[] = ["VAP {$k} reçues", fn ($g) => $g['doses'][$k]['received'], null];
            $columns[] = ["VAP {$k} dénominateur", fn ($g) => $g['doses'][$k]['denominator'], null];
            $columns[] = ["VAP {$k} %", fn ($g) => $g['doses'][$k]['pct'] !== null ? $g['doses'][$k]['pct'] / 100 : null, '0.0%'];
        }

        return Excel::download(new CoverageWorkbook($stats->groupedBy(...), $columns), 'couverture-vap-'.now()->format('Ymd-Hi').'.xlsx');
    }
}
