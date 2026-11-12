<?php

namespace App\Http\Controllers;

use App\Http\Requests\FieldIncidentRequest;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\FieldIncident;
use App\Models\HealthZone;
use App\Models\Village;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Incidents de terrain : signalement par le superviseur et suivi jusqu'à la résolution. */
class FieldIncidentController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->input('statut', 'en_attente');
        $incidents = FieldIncident::visibleTo($user)
            ->with(['healthZone:id,name', 'village:id,name', 'cluster:id,code', 'collector:id,name', 'reporter:id,name'])
            ->when($status === 'en_attente', fn ($q) => $q->whereIn('status', FieldIncident::UNRESOLVED))
            ->when(isset(FieldIncident::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->when($request->filled('categorie'), fn ($q) => $q->where('category', $request->input('categorie')))
            ->when(! $user->geographicScope() && $request->integer('health_zone_id'), fn ($q) => $q->where('health_zone_id', $request->integer('health_zone_id')))
            ->orderByRaw('case when status = ? then 1 else 0 end', [FieldIncident::STATUS_RESOLVED])
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $counts = FieldIncident::visibleTo($user)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('incidents.index', [
            'incidents' => $incidents,
            'counts' => $counts,
            'status' => $status,
            'zones' => $user->geographicScope() ? collect() : HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', FieldIncident::class);

        return view('incidents.create', $this->formOptions($request) + [
            'defaults' => $request->only(['cluster_id', 'village_id', 'collector_id']),
        ]);
    }

    public function store(FieldIncidentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $location = $this->location($request, $data);

        $incident = DB::transaction(function () use ($data, $location, $request) {
            $incident = FieldIncident::create($location + [
                'occurred_on' => $data['occurred_on'],
                'category' => $data['category'],
                'severity' => $data['severity'],
                'title' => $data['title'],
                'description' => $data['description'],
                'impact' => $data['impact'] ?? null,
                'status' => FieldIncident::STATUS_OPEN,
                'reported_by' => $request->user()->id,
            ]);
            $incident->updates()->create(['user_id' => $request->user()->id, 'to_status' => FieldIncident::STATUS_OPEN, 'comment' => 'Incident signalé.']);

            return $incident;
        });
        AuditLog::record('incident.created', $incident, ['categorie' => $incident->category, 'gravite' => $incident->severity]);

        return redirect()->route('incidents.show', $incident)->with('success', "Incident {$incident->code} enregistré.");
    }

    public function show(FieldIncident $incident): View
    {
        $this->authorize('view', $incident);
        $incident->load(['healthZone', 'commune', 'village', 'cluster', 'collector', 'reporter', 'updates.user']);

        return view('incidents.show', ['incident' => $incident]);
    }

    /** Commentaire de suivi, avec changement de statut facultatif (la résolution exige un commentaire). */
    public function update(Request $request, FieldIncident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(FieldIncident::STATUSES))],
            'comment' => [Rule::requiredIf(fn () => in_array($request->input('status'), [null, '', FieldIncident::STATUS_RESOLVED], true)), 'nullable', 'string', 'max:3000'],
        ], [], ['status' => 'statut', 'comment' => 'commentaire']);

        $to = ($data['status'] ?? null) ?: null;
        if ($to === $incident->status) {
            $to = null;
        }
        if ($to && ! $incident->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Passage de « {$incident->statusLabel()} » à « ".FieldIncident::STATUSES[$to].' » non autorisé.']);
        }

        DB::transaction(function () use ($incident, $to, $data, $request) {
            $incident->updates()->create([
                'user_id' => $request->user()->id,
                'from_status' => $to ? $incident->status : null,
                'to_status' => $to,
                'comment' => $data['comment'] ?? null,
            ]);
            if ($to) {
                $incident->update([
                    'status' => $to,
                    'resolved_at' => $to === FieldIncident::STATUS_RESOLVED ? now() : null,
                ]);
            } else {
                $incident->touch();
            }
        });
        AuditLog::record('incident.updated', $incident, array_filter(['statut' => $to]));

        return back()->with('success', $to ? 'Incident passé au statut « '.$incident->statusLabel().' ».' : 'Suivi ajouté.');
    }

    /**
     * Zone, commune et lieu de l'incident, vérifiés contre le périmètre de l'utilisateur.
     *
     * @return array{health_zone_id: int, commune_id: ?int, village_id: ?int, cluster_id: ?int, collector_id: ?int}
     */
    private function location(Request $request, array $data): array
    {
        $scope = $request->user()->geographicScope();
        $zoneId = match ($scope['column'] ?? null) {
            'health_zone_id' => (int) $scope['id'],
            'commune_id' => (int) Commune::whereKey($scope['id'])->value('health_zone_id'),
            default => (int) $data['health_zone_id'],
        };
        $communeId = ($scope['column'] ?? null) === 'commune_id' ? (int) $scope['id'] : null;

        $cluster = isset($data['cluster_id']) ? Cluster::find($data['cluster_id']) : null;
        $village = isset($data['village_id']) ? Village::find($data['village_id']) : ($cluster?->village);
        $collector = isset($data['collector_id']) ? Collector::find($data['collector_id']) : null;

        $errors = [];
        if ($cluster && (int) $cluster->health_zone_id !== $zoneId) {
            $errors['cluster_id'] = 'Cette grappe n\'est pas dans la zone de l\'incident.';
        }
        if ($village && (int) $village->health_zone_id !== $zoneId) {
            $errors['village_id'] = 'Ce village n\'est pas dans la zone de l\'incident.';
        }
        if ($cluster && $village && $cluster->village_id && $cluster->village_id !== $village->id) {
            $errors['cluster_id'] = 'Cette grappe n\'appartient pas au village choisi.';
        }
        if ($communeId && $village && (int) $village->commune_id !== $communeId) {
            $errors['village_id'] = 'Ce village n\'est pas dans votre commune.';
        }
        if ($collector && $collector->health_zone_id && (int) $collector->health_zone_id !== $zoneId) {
            $errors['collector_id'] = 'Cet agent n\'est pas affecté à la zone de l\'incident.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'health_zone_id' => $zoneId,
            'commune_id' => $communeId ?? $village?->commune_id ?? $cluster?->commune_id,
            'village_id' => $village?->id,
            'cluster_id' => $cluster?->id,
            'collector_id' => $collector?->id,
        ];
    }

    /** Listes du formulaire, limitées au périmètre de l'utilisateur. */
    private function formOptions(Request $request): array
    {
        $scope = $request->user()->geographicScope();
        $zoneId = match ($scope['column'] ?? null) {
            'health_zone_id' => (int) $scope['id'],
            'commune_id' => (int) Commune::whereKey($scope['id'])->value('health_zone_id'),
            default => null,
        };
        $communeId = ($scope['column'] ?? null) === 'commune_id' ? (int) $scope['id'] : null;
        $zones = HealthZone::whereHas('communes')->when($zoneId, fn ($q) => $q->whereKey($zoneId))->orderBy('name')->get(['id', 'name']);
        $zoneNames = $zones->pluck('name', 'id');

        $villages = Village::whereIn('health_zone_id', $zones->pluck('id'))->when($communeId, fn ($q) => $q->where('commune_id', $communeId))
            ->orderBy('name')->get(['id', 'name', 'health_zone_id']);
        $clusters = Cluster::whereIn('health_zone_id', $zones->pluck('id'))->when($communeId, fn ($q) => $q->where('commune_id', $communeId))
            ->where(fn ($q) => $q->whereNotNull('target_households')->orWhereHas('households'))
            ->with('village:id,name')->orderBy('code')->get(['id', 'code', 'village_id', 'health_zone_id']);
        $collectors = Collector::whereIn('health_zone_id', $zones->pluck('id'))->with('team:id,code')->orderBy('name')->get(['id', 'name', 'health_zone_id', 'team_id']);

        return [
            'scoped' => (bool) $scope,
            'zones' => $zones,
            'villages' => $villages->groupBy(fn ($v) => $zoneNames[$v->health_zone_id] ?? ''),
            'clusters' => $clusters->groupBy(fn ($c) => $c->village?->name ?? 'Village non renseigné'),
            'collectors' => $collectors->groupBy(fn ($c) => $zoneNames[$c->health_zone_id] ?? ''),
        ];
    }
}
