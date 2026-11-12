<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Dashboard\ProgressStats;
use App\Support\Filters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        // Superviseurs et investigateur principal arrivent sur leur espace ; le dashboard complet reste accessible depuis le menu.
        if (! $request->boolean('complet')) {
            if ($request->user()->hasRole(User::ROLE_SUPERVISOR)) {
                return redirect()->route('supervision.index');
            }
            if ($request->user()->hasRole(User::ROLE_PI)) {
                return redirect()->route('investigator.index');
            }
        }

        return view('dashboard.progress', [
            'refresh' => config('gavi.dashboard.refresh_seconds'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json((new ProgressStats(Filters::fromRequest($request)))->all());
    }

    /** Export du tableau hiérarchique (Excel ou PDF) avec les filtres courants. */
    public function export(Request $request, string $format): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $filters = Filters::fromRequest($request);
        $stats = new ProgressStats($filters);
        $rows = $stats->hierarchy();
        $levels = ['Zone sanitaire', 'Commune', 'Village', 'Grappe'];
        AuditLog::record('export.progression', null, ['format' => $format, 'filtres' => $filters->values]);

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.progress', ['rows' => $rows, 'kpis' => $stats->kpis(), 'levels' => $levels, 'filters' => $filters])
                ->setPaper('a4', 'landscape')
                ->download('progression-'.now()->format('Ymd-Hi').'.pdf');
        }

        $data = array_map(fn ($r) => [
            $levels[$r['level']], $r['label'], $r['visited'], $r['households'], $r['caregivers'], $r['children'],
            $r['target'] ?: null, $r['rate'] !== null ? $r['rate'] / 100 : null, $r['queries'],
        ], $rows);

        return Excel::download(new ArrayExport(
            $data,
            ['Niveau', 'Libellé', 'Ménages visités', 'Ménages enquêtés', 'Caregivers', 'Enfants enrôlés', 'Cible ménages', '% réalisé', 'Queries ouvertes'],
            'Progression',
            ['H' => '0.0%'],
        ), 'progression-'.now()->format('Ymd-Hi').'.xlsx');
    }
}
