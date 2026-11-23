<?php

namespace App\Http\Controllers;

use App\Exports\CoverageWorkbook;
use App\Models\AuditLog;
use App\Services\Dashboard\ChildCoverageStats;
use App\Services\Dashboard\CoverageReport;
use App\Services\Dashboard\SmcCoverageStats;
use App\Services\Dashboard\VaccineCoverageStats;
use App\Support\Filters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Pages de couverture : antigènes du calendrier (« antigenes ») et CPS (« cps »), filtres du tableau de bord. */
class CoverageController extends Controller
{
    public const TYPES = ['antigenes', 'cps'];

    public function index(string $type): View
    {
        return $type === 'cps'
            ? view('dashboard.smc-coverage', ['maxCycles' => SmcCoverageStats::MAX_CYCLES])
            : view('dashboard.vaccine-coverage', ['doses' => VaccineCoverageStats::doseList()]);
    }

    public function data(Request $request, string $type): JsonResponse
    {
        return response()->json($this->stats($request, $type)->all());
    }

    /**
     * Export Excel (filtres courants), une feuille par niveau : zone ; zone et commune ; zone, commune et village ;
     * âge et sexe. Indicateurs en pourcentages.
     */
    public function export(Request $request, string $type): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $stats = $this->stats($request, $type);
        AuditLog::record('export.couverture_'.$type, null, ['filtres' => Filters::fromRequest($request)->values]);

        $pct = fn (callable $get) => fn ($g) => self::ratio($get($g));
        if ($type === 'cps') {
            // Cycles prévus dans au moins une commune de l'échantillon filtré.
            $total = $stats->groupedBy([])[0] ?? null;
            $cycles = array_filter(range(1, SmcCoverageStats::MAX_CYCLES), fn ($k) => ($total['cycles'][$k]['denominator'] ?? 0) > 0);
            $columns = [
                ...array_map(fn ($k) => ["Cycle {$k}", $pct(fn ($g) => $g['cycles'][$k]['pct']), '0.0%'], $cycles),
                ['Au moins un cycle', $pct(fn ($g) => $g['at_least_one']), '0.0%'],
                ['Tous les cycles prévus', $pct(fn ($g) => $g['all_cycles']), '0.0%'],
                ['CPS déclarée', $pct(fn ($g) => $g['declared']), '0.0%'],
                ['Carte CPS présentée', $pct(fn ($g) => $g['card_present']), '0.0%'],
            ];
        } else {
            $stats->groupedBy([]); // doses renseignées (collected) connues après la lecture des enfants
            $columns = [
                ...array_map(fn ($code) => [config("gavi.vaccines.{$code}.label", $code), $pct(fn ($g) => $g['doses'][$code]['pct'] ?? null), '0.0%'], $stats->collectedDoses()),
                ['Complètement vacciné (base)', $pct(fn ($g) => $g['fully_basic']), '0.0%'],
                ['Complètement vacciné pour l\'âge', $pct(fn ($g) => $g['fully_age']), '0.0%'],
                ['Zéro dose (sans Penta 1)', $pct(fn ($g) => $g['zero_dose']), '0.0%'],
            ];
        }

        return Excel::download(new CoverageWorkbook($stats->groupedBy(...), $columns), "couverture-{$type}-".now()->format('Ymd-Hi').'.xlsx');
    }

    /** Rapport PDF : indicateurs, barres par dose ou cycle, détail et tableaux par niveau (filtres courants). */
    public function pdf(Request $request, string $type): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $filters = Filters::fromRequest($request);
        AuditLog::record('export.couverture_'.$type, null, ['format' => 'pdf', 'filtres' => $filters->values]);
        $stats = $this->stats($request, $type)->all();

        return $type === 'cps' ? CoverageReport::smc($stats, $filters) : CoverageReport::antigens($stats, $filters);
    }

    private function stats(Request $request, string $type): ChildCoverageStats
    {
        $filters = Filters::fromRequest($request);

        return $type === 'cps' ? new SmcCoverageStats($filters) : new VaccineCoverageStats($filters);
    }

    private static function ratio(?float $pct): ?float
    {
        return $pct !== null ? $pct / 100 : null;
    }
}
