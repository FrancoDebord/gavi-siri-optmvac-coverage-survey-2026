<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HealthZone;
use App\Models\Team;
use App\Services\Dashboard\TeamActivityReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapport d'activité des binômes (administration) : par jour, ménages visités et enquêtés, mères, enfants de moins
 * de 5 ans et enfants éligibles ; jours sans données fusionnés (« préparation du terrain… »). Page et PDF, pour un
 * binôme ou pour tous (une page par binôme).
 */
class TeamActivityReportController extends Controller
{
    private const MAX_DAYS = 120;

    public function index(Request $request): View
    {
        [$filters, $from, $to] = $this->filters($request);

        return view('team-activity.index', [
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'reports' => $from ? (new TeamActivityReport($from, $to))->build($this->teams($filters)) : collect(),
            'zones' => HealthZone::orderBy('name')->get(['id', 'name']),
            'teamOptions' => Team::with('healthZone:id,name')->when($filters['health_zone_id'], fn ($q, $z) => $q->where('health_zone_id', $z))
                ->orderBy('code')->get(['id', 'code', 'name', 'health_zone_id']),
        ]);
    }

    public function pdf(Request $request): Response
    {
        @set_time_limit(300);
        [$filters, $from, $to] = $this->filters($request);
        abort_unless($from, 404, 'Aucune donnée de collecte.');
        $teams = $this->teams($filters);
        $reports = (new TeamActivityReport($from, $to))->build($teams);
        AuditLog::record('export.rapport_activite_binomes', $filters['team_id'] ? $teams->first() : null, array_filter($filters));

        $name = $filters['team_id'] && $teams->count() === 1 ? 'binome-'.$teams->first()->code : 'binomes';

        return Pdf::loadView('pdf.team-activity', [
            'reports' => $reports,
            'from' => $from,
            'to' => $to,
            'generatedAt' => CarbonImmutable::now(config('app.timezone')),
        ])->setPaper('a4', 'portrait')
            ->download('rapport-activite-'.$name.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.pdf');
    }

    /** @return array{0: array, 1: ?CarbonImmutable, 2: ?CarbonImmutable} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'health_zone_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'La date de fin doit suivre la date de début.'], ['from' => 'date de début', 'to' => 'date de fin']);

        $default = TeamActivityReport::defaultPeriod();
        $from = ! empty($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $default[0] ?? null;
        $to = ! empty($data['to']) ? CarbonImmutable::parse($data['to'])->startOfDay() : $default[1] ?? null;
        // Pas avant le début de la collecte (semaine 1).
        if ($from && $from->lessThan(TeamActivityReport::collectionStart())) {
            $from = TeamActivityReport::collectionStart();
        }
        if ($from && (! $to || $to->lessThan($from))) {
            $to = $from;
        }
        // Période plafonnée (une ligne par jour et par binôme).
        if ($from && $to && $from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return [[
            'health_zone_id' => (int) ($data['health_zone_id'] ?? 0) ?: null,
            'team_id' => (int) ($data['team_id'] ?? 0) ?: null,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ], $from, $to];
    }

    /** @return Collection<int, Team> binômes de la zone (ou le binôme choisi), par zone puis code */
    private function teams(array $filters): Collection
    {
        return Team::with(['healthZone:id,name', 'collectors:id,team_id,code,name'])
            ->when($filters['team_id'], fn ($q, $id) => $q->whereKey($id))
            ->when($filters['health_zone_id'], fn ($q, $z) => $q->where('health_zone_id', $z))
            ->get()
            ->sortBy([fn ($a, $b) => strcmp((string) $a->healthZone?->name, (string) $b->healthZone?->name), fn ($a, $b) => strnatcmp($a->code, $b->code)])
            ->values();
    }
}
