<?php

namespace App\Http\Controllers;

use App\Exports\QueriesCsvExport;
use App\Exports\QueriesExport;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Services\Dashboard\QueryStats;
use App\Support\DateInput;
use App\Support\Filters;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class QueryDashboardController extends Controller
{
    public function index(): View
    {
        return view('queries.dashboard', [
            'rules' => QualityRule::orderBy('code')->get(['code', 'name']),
            'refresh' => config('gavi.dashboard.refresh_seconds'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json((new QueryStats(Filters::fromRequest($request), self::extra($request)))->all());
    }

    public static function extra(Request $request): array
    {
        return [
            'status' => in_array($request->input('status'), [...array_keys(DataQuery::STATUSES), 'non_resolues'], true) ? $request->input('status') : null,
            'severity' => array_key_exists((string) $request->input('severity'), QualityRule::SEVERITIES) ? $request->input('severity') : null,
            'rule_code' => is_string($request->input('rule_code')) && $request->input('rule_code') !== '' ? $request->input('rule_code') : null,
            'resolution' => array_key_exists((string) $request->input('resolution'), DataQuery::RESOLUTIONS) ? $request->input('resolution') : null,
            'variable' => is_string($request->input('variable')) && trim($request->input('variable')) !== '' ? trim($request->input('variable')) : null,
            'generated_from' => DateInput::parse($request->input('generated_from')),
            'generated_to' => DateInput::parse($request->input('generated_to')),
            'code' => is_string($request->input('code')) ? DataQuery::idFromCode($request->input('code')) : null,
            'assigned' => match (true) {
                $request->input('assigned') === 'moi' => $request->user()?->id,
                $request->input('assigned') === 'aucun' => 'aucun',
                ctype_digit((string) $request->input('assigned')) => (int) $request->input('assigned'),
                default => null,
            },
            'overdue' => $request->boolean('en_retard') ?: null,
            // Origine : observations de supervision envoyées dans les queries, ou queries du moteur seulement.
            'origin' => in_array($request->input('origine'), ['observation', 'moteur'], true) ? $request->input('origine') : null,
            // Validation confiée : « moi » ou une personne ; seules les réponses en attente.
            'reviewer' => match (true) {
                $request->input('a_valider') === 'moi' => $request->user()?->id,
                ctype_digit((string) $request->input('a_valider')) => (int) $request->input('a_valider'),
                default => null,
            },
        ];
    }

    /**
     * Export des queries selon les critères de la requête (mêmes filtres que la liste).
     * format : xlsx (défaut) ou csv ; group_by : voir QueriesExport::GROUPS.
     */
    public function export(Request $request): Response
    {
        $request->validate([
            'format' => ['nullable', 'in:xlsx,csv'],
            'group_by' => ['nullable', 'in:'.implode(',', array_filter(array_keys(QueriesExport::GROUPS)))],
        ]);
        $filters = Filters::fromRequest($request);
        $extra = self::extra($request);
        $format = $request->input('format', 'xlsx');
        $groupBy = (string) $request->input('group_by', '');
        $export = new QueriesExport($filters, $extra, $groupBy);
        AuditLog::record('export.queries', null, ['format' => $format, 'regroupement' => $groupBy ?: null, 'filtres' => $filters->values + array_filter($extra)]);

        $slug = ['collector' => 'binome', 'health_zone' => 'zone-sanitaire', 'commune' => 'commune', 'village' => 'village', 'cluster' => 'grappe', 'rule' => 'regle', 'resolution' => 'statut'][$groupBy] ?? null;
        $name = 'queries'.($slug ? '-par-'.$slug : '').'-'.now()->format('Ymd-Hi');

        return $format === 'csv'
            ? Excel::download(new QueriesCsvExport($export), $name.'.csv', ExcelFormat::CSV, ['Content-Type' => 'text/csv; charset=UTF-8'])
            : Excel::download($export, $name.'.xlsx');
    }

    /** Retour terrain : une feuille par binôme (colonne « Agent » pour chacun des deux), queries non résolues par défaut. */
    public function exportByCollector(Request $request): Response
    {
        $request->merge(['group_by' => 'team', 'format' => 'xlsx']);
        if (! $request->filled('status') && ! $request->filled('resolution')) {
            $request->merge(['resolution' => DataQuery::RESOLUTION_UNRESOLVED]);
        }

        return $this->export($request);
    }

    /** Rapport PDF quotidien par zone sanitaire (date de collecte, par défaut la veille). */
    public function dailyReport(Request $request): Response
    {
        $date = CarbonImmutable::parse(DateInput::parse($request->input('date')) ?? DateInput::parse($request->input('to')) ?? now()->subDay()->toDateString());
        $filters = Filters::fromRequest($request);
        // Filtres géographiques sous la zone (commune, village…) conservés, y compris le périmètre d'un superviseur.
        $below = array_intersect_key($filters->values, array_flip(['commune_id', 'village_id', 'cluster_id', 'team_id', 'collector_id']));
        $zoneId = $filters->get('health_zone_id') ?? (isset($below['commune_id']) ? Commune::find($below['commune_id'])?->health_zone_id : null);
        $zones = HealthZone::when($zoneId, fn ($q, $id) => $q->whereKey($id))->orderBy('name')->get();

        $sections = $zones->map(function (HealthZone $zone) use ($date, $below) {
            $dayFilters = new Filters(['health_zone_id' => $zone->id, 'from' => $date->toDateString(), 'to' => $date->toDateString()] + $below);
            $cumulFilters = new Filters(['health_zone_id' => $zone->id, 'to' => $date->toDateString()] + $below);
            $households = fn (Filters $f) => $f->apply(DB::table('households'), 'households', 'interview_date');

            return [
                'zone' => $zone,
                'day' => [
                    'visited' => $households($dayFilters)->count(),
                    'surveyed' => $households($dayFilters)->where('consented', true)->count(),
                    'queries' => (new QueryStats($dayFilters))->kpis(),
                    'by_severity' => (new QueryStats($dayFilters))->bySeverity(),
                    'top_rules' => array_slice((new QueryStats($dayFilters))->topRules(), 0, 5),
                    'teams' => (new QueryStats($dayFilters))->teamRanking(),
                ],
                'cumul' => [
                    'visited' => $households($cumulFilters)->count(),
                    'surveyed' => $households($cumulFilters)->where('consented', true)->count(),
                    'queries' => (new QueryStats($cumulFilters))->kpis(),
                ],
                'critical' => DataQuery::with(['collector', 'cluster'])
                    ->where('health_zone_id', $zone->id)->where(array_diff_key($below, ['team_id' => true]))->whereDate('collection_date', $date)
                    ->when($below['team_id'] ?? null, fn ($q, $teamId) => $q->whereIn('collector_id', Collector::where('team_id', $teamId)->select('id')))
                    ->whereIn('status', DataQuery::UNRESOLVED)->where('severity', 'critique')
                    ->orderBy('collector_id')->limit(60)->get(),
            ];
        });
        AuditLog::record('export.rapport_quotidien', null, ['date' => $date->toDateString()]);

        return Pdf::loadView('pdf.daily-report', ['date' => $date, 'sections' => $sections])
            ->setPaper('a4')
            ->download('rapport-queries-'.$date->format('Ymd').'.pdf');
    }
}
