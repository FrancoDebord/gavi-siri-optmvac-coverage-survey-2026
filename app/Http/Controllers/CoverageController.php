<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
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

    private const LEVELS = ['by_zone' => 'Zone sanitaire', 'by_commune' => 'Commune', 'by_village' => 'Village', 'by_age' => 'Âge', 'by_sex' => 'Sexe'];

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

    /** Export Excel : indicateurs par zone, commune, village, âge et sexe (filtres courants), en pourcentages. */
    public function export(Request $request, string $type): Response
    {
        abort_unless($request->user()->can('export data'), 403);
        $stats = $this->stats($request, $type)->all();
        AuditLog::record('export.couverture_'.$type, null, ['filtres' => Filters::fromRequest($request)->values]);

        $columns = $type === 'cps'
            ? collect(range(1, SmcCoverageStats::MAX_CYCLES))->mapWithKeys(fn ($k) => ["Cycle {$k}" => fn ($g) => $g['cycles'][$k]['pct']])
                ->merge(['Au moins un cycle' => fn ($g) => $g['at_least_one'], 'Tous les cycles prévus' => fn ($g) => $g['all_cycles'], 'CPS déclarée' => fn ($g) => $g['declared'], 'Carte CPS présentée' => fn ($g) => $g['card_present']])
            : collect($stats['collected'])->mapWithKeys(fn ($code) => [config("gavi.vaccines.{$code}.label", $code) => fn ($g) => $g['doses'][$code]['pct'] ?? null])
                ->merge(['Complètement vacciné (base)' => fn ($g) => $g['fully_basic'], 'Complètement vacciné pour l\'âge' => fn ($g) => $g['fully_age'], 'Zéro dose (sans Penta 1)' => fn ($g) => $g['zero_dose']]);

        $rows = [['Total', 'Ensemble', $stats['total']['children'], ...$columns->map(fn ($get) => self::ratio($get($stats['total'])))->values()]];
        foreach (self::LEVELS as $key => $level) {
            foreach ($stats[$key] as $group) {
                $rows[] = [$level, $group['label'], $group['children'], ...$columns->map(fn ($get) => self::ratio($get($group)))->values()];
            }
        }
        $formats = [];
        foreach (range(0, $columns->count() - 1) as $i) {
            $formats[self::column(3 + $i)] = '0.0%';
        }

        return Excel::download(new ArrayExport($rows, ['Niveau', 'Libellé', 'Enfants enrôlés', ...$columns->keys()], $type === 'cps' ? 'Couverture CPS' : 'Couverture antigènes', $formats),
            "couverture-{$type}-".now()->format('Ymd-Hi').'.xlsx');
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

    /** Lettre de colonne Excel (0 → A). */
    private static function column(int $index): string
    {
        $letters = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letters = chr(65 + $i % 26).$letters;
        }

        return $letters;
    }
}
