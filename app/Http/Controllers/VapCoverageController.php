<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\AuditLog;
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

    /** Export Excel : couverture par zone, commune, village, âge et sexe (filtres courants). */
    public function export(Request $request): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $filters = Filters::fromRequest($request);
        $stats = (new VapCoverageStats($filters))->all();
        AuditLog::record('export.couverture_vap', null, ['filtres' => $filters->values]);

        $rows = [];
        foreach (['by_zone' => 'Zone sanitaire', 'by_commune' => 'Commune', 'by_village' => 'Village', 'by_age' => 'Âge', 'by_sex' => 'Sexe'] as $key => $level) {
            foreach ($stats[$key] as $group) {
                $row = [$level, $group['label'], $group['children']];
                foreach (VapCoverageStats::DOSES as $k) {
                    $dose = $group['doses'][$k];
                    array_push($row, $dose['received'], $dose['denominator'], $dose['pct'] !== null ? $dose['pct'] / 100 : null);
                }
                $rows[] = $row;
            }
        }
        $headings = ['Niveau', 'Libellé', 'Enfants enrôlés'];
        foreach (VapCoverageStats::DOSES as $k) {
            array_push($headings, "VAP {$k} reçues", "VAP {$k} dénominateur", "VAP {$k} %");
        }

        return Excel::download(new ArrayExport($rows, $headings, 'Couverture VAP', ['F' => '0.0%', 'I' => '0.0%', 'L' => '0.0%', 'O' => '0.0%']),
            'couverture-vap-'.now()->format('Ymd-Hi').'.xlsx');
    }
}
