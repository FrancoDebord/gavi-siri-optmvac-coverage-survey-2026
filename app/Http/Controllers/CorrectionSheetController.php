<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Exports\SheetsExport;
use App\Models\AuditLog;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\CorrectionSheets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fiches de corrections au format du document qualité Bénin : synthèse par zone et binôme, fiche par binôme
 * (critiques puis majeures), observations de supervision, règles non appliquées. Page, Excel et PDF.
 * Superviseurs : leur zone ; binômes : leur propre fiche (PDF, espace binôme).
 */
class CorrectionSheetController extends Controller
{
    private function filters(Request $request): array
    {
        $user = $request->user();
        $zone = $user->supervisedZoneId() ?? ($request->integer('health_zone_id') ?: null);

        return [$zone, $request->integer('team_id') ?: null];
    }

    public function index(Request $request): View
    {
        [$zone, $team] = $this->filters($request);
        $report = (new CorrectionSheets($request->user(), $zone, $team))->build();

        return view('correction-sheets.index', $report + [
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'teams' => Team::with('healthZone:id,name')->when($zone, fn ($q, $z) => $q->where('health_zone_id', $z))->orderBy('code')->get(),
            'filters' => ['health_zone_id' => $zone, 'team_id' => $team],
            'locked' => $request->user()->supervisedZoneId() !== null,
            'columns' => CorrectionSheets::COLUMNS,
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        [$zone, $team] = $this->filters($request);
        $report = (new CorrectionSheets($request->user(), $zone, $team))->build();
        $line = fn (array $r) => [$r['n'], $r['severity'], $r['date'], $r['village'], $r['household'], $r['person'], $r['question'], $r['value'], $r['problem'], $r['action'], $r['code']];

        $sheets = [new ArrayExport(array_map(fn ($s) => array_values($s), $report['synthesis']),
            ['Zone sanitaire', 'Commune', 'Binôme', 'Ménages contrôlés', 'Critiques', 'Majeures', 'Observations'], 'Synthèse')];
        foreach ($report['sheets'] as $sheet) {
            $rows = array_map($line, $sheet['corrections']);
            if ($sheet['observations']) {
                $rows[] = array_fill(0, 11, null);
                $rows[] = ['Observations de supervision', ...array_fill(0, 10, null)];
                $rows = [...$rows, ...array_map($line, $sheet['observations'])];
            }
            $sheets[] = new ArrayExport($rows, CorrectionSheets::COLUMNS, 'Binôme '.($sheet['team']?->code ?? 'sans'));
        }
        $sheets[] = new ArrayExport(array_map(fn ($r) => [$r['code'], $r['reason']], $report['not_applied']), ['Code', 'Raison'], 'Règles non appliquées');
        AuditLog::record('export.fiches_corrections', null, ['binomes' => count($report['sheets'])]);

        return Excel::download(new SheetsExport($sheets), 'fiches-corrections-'.now()->format('Ymd-Hi').'.xlsx');
    }

    public function pdf(Request $request): Response
    {
        [$zone, $team] = $this->filters($request);

        return $this->renderPdf((new CorrectionSheets($request->user(), $zone, $team))->build(), 'fiches-corrections');
    }

    /** Espace binôme : la fiche du binôme connecté (ou choisi par l'équipe centrale). */
    public function team(Request $request): Response
    {
        $user = $request->user();
        $teamId = $user->hasRole(User::ROLE_TEAM) ? $user->team_id : ($request->integer('team_id') ?: $user->team_id);
        abort_unless($teamId, 404);
        abort_unless($user->hasRole(User::ROLE_TEAM) || $user->can('view dashboards'), 403);
        $report = (new CorrectionSheets($user->hasRole(User::ROLE_TEAM) ? null : $user, null, $teamId))->build();

        return $this->renderPdf($report, 'fiche-corrections-binome-'.Team::whereKey($teamId)->value('code'));
    }

    private function renderPdf(array $report, string $name): Response
    {
        AuditLog::record('export.fiches_corrections_pdf', null, ['binomes' => count($report['sheets'])]);

        return Pdf::loadView('pdf.correction-sheets', $report + ['generatedAt' => now()])
            ->setPaper('a4', 'landscape')->download($name.'-'.now()->format('Ymd').'.pdf');
    }
}
