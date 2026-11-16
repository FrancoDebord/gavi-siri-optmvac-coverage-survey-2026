<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Http\Requests\FieldDecisionRequest;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\DecisionCategory;
use App\Models\FieldDecision;
use App\Models\HealthZone;
use App\Models\TeamMessage;
use App\Models\User;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Registre des décisions de terrain : saisie, recherche, statut (en vigueur / remplacée / annulée), catégories, export. */
class FieldDecisionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $decisions = $this->filtered($request)->with(['category', 'healthZone:id,name', 'village:id,name', 'cluster:id,code',
            'replacedBy' => fn ($q) => $q->visibleTo($user)->select('id', 'title')])
            ->orderByDesc('decided_on')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('decisions.index', [
            'decisions' => $decisions,
            'categories' => DecisionCategory::withCount(['decisions' => fn ($q) => $q->visibleTo($user)])->orderBy('sort')->orderBy('name')->get(),
            'zones' => $request->user()->geographicScope() ? collect() : HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'counts' => FieldDecision::visibleTo($request->user())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Request $request, FieldDecision $decision): View
    {
        $this->ensureVisible($request, $decision);
        $user = $request->user();
        $decision->load(['category', 'healthZone', 'village', 'cluster', 'author',
            'replacedBy' => fn ($q) => $q->visibleTo($user), 'replaces' => fn ($q) => $q->visibleTo($user)]);

        return view('decisions.show', ['decision' => $decision]);
    }

    public function create(Request $request): View
    {
        $replaces = $request->integer('remplace') ? FieldDecision::visibleTo($request->user())->find($request->integer('remplace')) : null;

        return view('decisions.form', $this->formData(new FieldDecision([
            'decided_on' => now(),
            'decided_by' => $request->user()->name,
            'decision_category_id' => $replaces?->decision_category_id,
            'health_zone_id' => $replaces?->health_zone_id,
            'village_id' => $replaces?->village_id,
            'cluster_id' => $replaces?->cluster_id,
        ])) + ['replaces' => $replaces]);
    }

    public function store(FieldDecisionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->ensureLinksVisible($request, $data);
        $decision = DB::transaction(function () use ($data, $request) {
            $decision = FieldDecision::create($this->attributes($data) + $this->confidentiality($request)
                + ['status' => FieldDecision::STATUS_ACTIVE, 'user_id' => $request->user()->id]);
            // Nouvelle décision qui en remplace une autre : l'ancienne passe au statut « remplacée ».
            if (! empty($data['replaces_id'])) {
                FieldDecision::whereKey($data['replaces_id'])->update(['status' => FieldDecision::STATUS_REPLACED, 'replaced_by_id' => $decision->id]);
            }

            return $decision;
        });
        AuditLog::record('decision.created', $decision, ['categorie' => $decision->category->name, 'titre' => $decision->title]);
        // Une décision confidentielle n'est jamais diffusée aux superviseurs.
        $notified = $request->boolean('notify') && ! $decision->is_confidential && $request->user()->can('send messages') ? $this->notify($decision, $request) : false;

        return redirect()->route('decisions.show', $decision)
            ->with('success', "Décision {$decision->code} enregistrée.".($notified ? ' Les superviseurs '.($decision->health_zone_id ? 'de la zone' : 'de toutes les zones').' en ont été informés.' : ''));
    }

    public function edit(Request $request, FieldDecision $decision): View
    {
        $this->ensureVisible($request, $decision);

        return view('decisions.form', $this->formData($decision) + ['replaces' => null]);
    }

    public function update(FieldDecisionRequest $request, FieldDecision $decision): RedirectResponse
    {
        $this->ensureVisible($request, $decision);
        $data = $request->validated();
        $this->ensureLinksVisible($request, $data);
        $decision->update($this->attributes($data) + $this->confidentiality($request) + [
            'status' => $data['status'],
            'replaced_by_id' => $data['status'] === FieldDecision::STATUS_REPLACED ? $data['replaced_by_id'] : null,
        ]);
        AuditLog::record('decision.updated', $decision, ['statut' => $decision->status]);

        return redirect()->route('decisions.show', $decision)->with('success', "Décision {$decision->code} mise à jour.");
    }

    /**
     * Suppression définitive (administrateur uniquement). Les décisions que celle-ci remplaçait redeviennent en vigueur :
     * sans cela, elles resteraient « remplacées » par une décision qui n'existe plus.
     */
    public function destroy(FieldDecision $decision): RedirectResponse
    {
        $code = $decision->code;
        $restored = DB::transaction(function () use ($decision) {
            $restored = $decision->replaces()->where('status', FieldDecision::STATUS_REPLACED)->pluck('id');
            FieldDecision::whereKey($restored)->update(['status' => FieldDecision::STATUS_ACTIVE, 'replaced_by_id' => null]);
            AuditLog::record('decision.deleted', $decision, [
                'code' => $decision->code, 'titre' => $decision->title, 'decision' => $decision->decision,
                'statut' => $decision->status, 'remises_en_vigueur' => $restored->all(),
            ]);
            $decision->delete();

            return $restored;
        });

        return redirect()->route('decisions.index')->with('success', "Décision {$code} supprimée."
            .($restored->isNotEmpty() ? ' '.$restored->count().' décision(s) qu\'elle remplaçait redevien'.($restored->count() > 1 ? 'nent' : 't').' en vigueur.' : ''));
    }

    /** Registre au format Excel, avec les filtres de la liste. */
    public function export(Request $request): BinaryFileResponse
    {
        $rows = $this->filtered($request)->with(['category', 'healthZone', 'village', 'cluster', 'author',
            'replacedBy' => fn ($q) => $q->visibleTo($request->user())])
            ->orderBy('decided_on')->orderBy('id')->get()
            ->map(fn (FieldDecision $d) => [
                $d->code, $d->decided_on?->format('d/m/Y'), $d->category?->name, $d->title, $d->context, $d->decision,
                $d->decided_by, $d->scopeLabel(), $d->effective_from?->format('d/m/Y'), $d->statusLabel(),
                $d->replacedBy ? $d->replacedBy->code.' – '.$d->replacedBy->title : null, $d->author?->name, $d->created_at?->format('d/m/Y H:i'),
                $d->is_confidential ? 'Oui' : 'Non',
            ])->all();
        AuditLog::record('export.decisions', null, ['lignes' => count($rows)]);

        return Excel::download(new ArrayExport($rows, [
            'Code', 'Date', 'Catégorie', 'Titre', 'Contexte / justification', 'Décision', 'Décidé par', 'Portée',
            'Applicable à partir du', 'Statut', 'Remplacée par', 'Saisie par', 'Saisie le', 'Confidentielle',
        ], 'Décisions de terrain'), 'decisions-terrain-'.now()->format('Ymd').'.xlsx');
    }

    /** Ajout d'une catégorie (« Autre à ajouter »). */
    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('decision_categories', 'name')],
            'color' => ['required', Rule::in(array_keys(DecisionCategory::COLORS))],
        ], [], ['name' => 'nom de la catégorie', 'color' => 'couleur']);
        // Les nouvelles catégories se placent avant « Autre ».
        $category = DecisionCategory::create($data + ['sort' => (int) DecisionCategory::where('sort', '<', 900)->max('sort') + 10]);
        AuditLog::record('decision.category', $category, ['nom' => $category->name]);

        return back()->with('success', "Catégorie « {$category->name} » ajoutée.");
    }

    /** Active / désactive une catégorie (une catégorie désactivée n'est plus proposée pour les nouvelles décisions). */
    public function toggleCategory(DecisionCategory $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('success', "Catégorie « {$category->name} » ".($category->is_active ? 'réactivée.' : 'désactivée.'));
    }

    private function filtered(Request $request): Builder
    {
        $status = $request->input('statut', 'tous');

        return FieldDecision::visibleTo($request->user())
            ->when($request->integer('categorie'), fn ($q, $id) => $q->where('decision_category_id', $id))
            ->when(isset(FieldDecision::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->when(! $request->user()->geographicScope() && $request->filled('health_zone_id'), fn ($q) => $request->input('health_zone_id') === 'global'
                ? $q->whereNull('health_zone_id')
                : $q->where('health_zone_id', $request->integer('health_zone_id')))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('decided_on', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('decided_on', '<=', $request->date('au')))
            ->when(trim((string) $request->input('q')) !== '', function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('q'))).'%';
                $q->where(fn ($s) => $s->where('title', 'like', $term)->orWhere('decision', 'like', $term)->orWhere('context', 'like', $term));
            });
    }

    /** Portée cohérente : la grappe fixe son village et sa zone, le village fixe sa zone. */
    private function attributes(array $data): array
    {
        $cluster = ! empty($data['cluster_id']) ? Cluster::find($data['cluster_id']) : null;
        $village = ! empty($data['village_id']) ? Village::find($data['village_id']) : $cluster?->village;
        $zoneId = $data['health_zone_id'] ?? null;

        if ($cluster && $village && $cluster->village_id && $cluster->village_id !== $village->id) {
            throw ValidationException::withMessages(['cluster_id' => 'Cette grappe n\'appartient pas au village choisi.']);
        }
        $derivedZone = $cluster?->health_zone_id ?? $village?->health_zone_id;
        if ($zoneId && $derivedZone && (int) $zoneId !== (int) $derivedZone) {
            throw ValidationException::withMessages(['village_id' => 'Le village ou la grappe n\'est pas dans la zone choisie.']);
        }

        return [
            'decision_category_id' => $data['decision_category_id'],
            'decided_on' => $data['decided_on'],
            'effective_from' => $data['effective_from'] ?? null,
            'title' => $data['title'],
            'context' => $data['context'] ?? null,
            'decision' => $data['decision'],
            'decided_by' => $data['decided_by'] ?? null,
            'health_zone_id' => $zoneId ?: $derivedZone,
            'village_id' => $village?->id,
            'cluster_id' => $cluster?->id,
        ] + (array_key_exists('in_summary', $data) && $data['in_summary'] !== null ? ['in_summary' => (bool) $data['in_summary']] : []);
    }

    /** Affiche ou masque la décision dans le rapport de synthèse du PI. */
    public function toggleSummary(Request $request, FieldDecision $decision): RedirectResponse
    {
        $this->ensureVisible($request, $decision);
        $decision->update(['in_summary' => ! $decision->in_summary]);
        AuditLog::record('decision.rapport_synthese', $decision, ['affichee' => $decision->in_summary]);

        return back()->with('success', "Décision {$decision->code} ".($decision->in_summary ? 'réaffichée dans' : 'masquée du').' rapport de synthèse.');
    }

    private function formData(FieldDecision $decision): array
    {
        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);
        $zoneNames = $zones->pluck('name', 'id');

        return [
            'decision' => $decision,
            'categories' => DecisionCategory::where('is_active', true)->orWhere('id', $decision->decision_category_id ?? 0)->orderBy('sort')->orderBy('name')->get(),
            'zones' => $zones,
            'villages' => Village::whereIn('health_zone_id', $zones->pluck('id'))->orderBy('name')->get(['id', 'name', 'health_zone_id'])
                ->groupBy(fn ($v) => $zoneNames[$v->health_zone_id] ?? ''),
            'clusters' => Cluster::whereNotNull('number')->with('village:id,name')->orderBy('code')->get(['id', 'code', 'village_id'])
                ->groupBy(fn ($c) => $c->village?->name ?? 'Village non renseigné'),
            'others' => FieldDecision::visibleTo(auth()->user())->whereKeyNot($decision->id ?? 0)->orderByDesc('decided_on')->limit(200)->get(['id', 'title', 'decided_on']),
        ];
    }

    /** Décision hors de la vue de l'utilisateur (confidentielle, autre zone) : accès refusé. */
    private function ensureVisible(Request $request, FieldDecision $decision): void
    {
        abort_unless(FieldDecision::visibleTo($request->user())->whereKey($decision->id)->exists(), 403);
    }

    /** Les décisions citées comme remplacée / remplaçante doivent être visibles de l'utilisateur. */
    private function ensureLinksVisible(Request $request, array $data): void
    {
        foreach (['replaces_id', 'replaced_by_id'] as $field) {
            if (! empty($data[$field]) && ! FieldDecision::visibleTo($request->user())->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Décision introuvable.']);
            }
        }
    }

    /** Seul un administrateur décide de la confidentialité ; pour les autres profils, la valeur est ignorée. */
    private function confidentiality(Request $request): array
    {
        return $request->user()->hasRole(User::ROLE_ADMIN) && $request->has('is_confidential')
            ? ['is_confidential' => $request->boolean('is_confidential')]
            : [];
    }

    /** Message d'information aux superviseurs concernés (zone de la décision, ou toutes les équipes). */
    private function notify(FieldDecision $decision, Request $request): bool
    {
        TeamMessage::create([
            'sender_id' => $request->user()->id,
            'target_type' => $decision->health_zone_id ? 'zone' : 'all',
            'target_id' => $decision->health_zone_id,
            'health_zone_id' => $decision->health_zone_id,
            'kind' => 'information',
            'body' => "Décision de terrain {$decision->code} ({$decision->category->name}, {$decision->scopeLabel()}) : {$decision->title}\n"
                .Str::limit($decision->decision, 1500)
                .($decision->effective_from ? "\nApplicable à partir du ".$decision->effective_from->format('d/m/Y').'.' : ''),
        ]);

        return true;
    }
}
