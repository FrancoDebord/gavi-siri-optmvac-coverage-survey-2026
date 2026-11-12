<x-layouts.app :title="'Query '.$query->code" page="query-show">
    <x-page-header :title="'Query '.$query->code" :subtitle="$query->label ?? $query->rule?->name ?? $query->rule_code">
        <a href="{{ auth()->user()->can('view dashboards') ? route('queries.index') : route('team.queries') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>{{ auth()->user()->can('view dashboards') ? 'Liste' : 'Mes queries' }}</a>
        @if ($query->household)
            <a href="{{ route('households.show', $query->household) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-house-door me-1"></i>Soumission</a>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ $query->code }} · {{ $query->label ?? $query->rule_code }}</span>
                    <span class="text-nowrap"><x-severity-badge :severity="$query->severity" /> <x-status-badge :status="$query->status" /></span>
                </div>
                <div class="card-body">
                    <h2 class="h6 text-muted mb-1">Description de l'erreur</h2>
                    <p class="fs-6">{{ $query->message }}</p>
                    @if ($query->suggestion)
                        <div class="alert alert-info small d-flex gap-2">
                            <i class="bi bi-lightbulb text-primary"></i>
                            <div><strong>Suggestion de résolution :</strong> {{ $query->suggestion }}</div>
                        </div>
                    @endif
                    <dl class="row small mb-0">
                        <dt class="col-sm-4">Code</dt><dd class="col-sm-8 fw-semibold">{{ $query->code }}</dd>
                        <dt class="col-sm-4">Statut de résolution</dt><dd class="col-sm-8">{{ $query->resolutionLabel() }} <span class="text-muted">({{ $query->statusLabel() }})</span></dd>
                        <dt class="col-sm-4">Règle</dt><dd class="col-sm-8"><span class="badge text-bg-light border">{{ $query->rule_code }}</span> {{ $query->rule?->description }}</dd>
                        <dt class="col-sm-4">Variable</dt><dd class="col-sm-8"><code>{{ $query->variable ?? '—' }}</code> @if ($query->variable_label)<div>{{ $query->variable_label }}</div>@endif</dd>
                        <dt class="col-sm-4">Membre concerné</dt><dd class="col-sm-8">{{ \App\Exports\QueriesExport::memberLabel($query->instance_path) }}</dd>
                        <dt class="col-sm-4">Valeur observée</dt><dd class="col-sm-8">{{ $query->observed_value ?? '—' }}</dd>
                        <dt class="col-sm-4">Attendu</dt><dd class="col-sm-8">{{ $query->expected ?? '—' }}</dd>
                        <dt class="col-sm-4">Date de génération</dt><dd class="col-sm-8">{{ $query->detected_at->format('d/m/Y H:i') }} · dernière détection {{ $query->last_detected_at->format('d/m/Y H:i') }}</dd>
                        <dt class="col-sm-4">Date de résolution</dt><dd class="col-sm-8">{{ $query->resolved_at?->format('d/m/Y H:i') ?? '—' }} @if ($query->auto_closed)<span class="badge text-bg-success">fermeture automatique</span>@endif</dd>
                    </dl>
                </div>
            </div>

            @if ($photos)
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-camera me-1"></i>Photos {{ $query->rule_code === 'DUP_CARD' ? 'des enfants nés le même jour' : ($query->child ? 'de l\'enfant' : 'du ménage') }}</span>
                        <small class="text-muted fw-normal">Cliquez sur une photo pour l'ouvrir en grand</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach ($photos as $photo)
                                <div class="{{ $photo['highlight'] || count($photos) === 1 ? 'col-12' : 'col-md-6' }}">
                                    <figure class="mb-0 query-photo {{ $photo['highlight'] ? 'border border-2 border-danger rounded p-2' : '' }}">
                                        <figcaption class="small mb-1">
                                            @if ($photo['highlight'])<span class="badge text-bg-danger me-1">Photo vérifiée</span>@endif
                                            {{ $photo['label'] }}
                                        </figcaption>
                                        <div class="query-photo-frame bg-light rounded">
                                            <a href="{{ $photo['url'] }}" target="_blank" rel="noopener">
                                                <img src="{{ $photo['url'] }}" alt="{{ $photo['label'] }}" loading="lazy" data-rotation="0">
                                            </a>
                                            <span class="query-photo-error small text-muted d-none p-3">Photo indisponible (serveur ODK injoignable ou pièce jointe absente).</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <code class="small text-muted">{{ $photo['filename'] }}</code>
                                            <button type="button" class="btn btn-sm btn-light" data-rotate aria-label="Faire pivoter la photo"><i class="bi bi-arrow-clockwise"></i></button>
                                        </div>
                                    </figure>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="card mb-3">
                <div class="card-header">Contexte</div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-sm-4">Date de collecte</dt><dd class="col-sm-8">{{ $query->collection_date?->format('d/m/Y') ?? '—' }}</dd>
                        <dt class="col-sm-4">Zone sanitaire</dt><dd class="col-sm-8">{{ $query->healthZone?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Commune</dt><dd class="col-sm-8">{{ $query->commune?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Village</dt><dd class="col-sm-8">{{ $query->village?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Grappe</dt><dd class="col-sm-8">{{ $query->cluster ? $query->cluster->name.' ('.$query->cluster->code.')' : '—' }}</dd>
                        <dt class="col-sm-4">Agent / binôme</dt><dd class="col-sm-8">{{ $query->collector?->name ?? '—' }}{{ $query->collector?->team ? ' · '.$query->collector->team->label() : '' }}</dd>
                        <dt class="col-sm-4">ID ménage</dt><dd class="col-sm-8">{{ $query->household_code ?? '—' }}</dd>
                        <dt class="col-sm-4">ID soumission</dt><dd class="col-sm-8"><code class="small">{{ $query->submission_uuid }}</code></dd>
                        @if ($query->child)
                            <dt class="col-sm-4">Enfant</dt><dd class="col-sm-8">n°{{ $query->child->position }} · {{ $query->child->sex === '1' ? 'fille' : ($query->child->sex === '0' ? 'garçon' : '—') }} · né(e) le {{ $query->child->date_of_birth?->format('d/m/Y') ?? '—' }} · {{ $query->child->age_months ?? '—' }} mois</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($related->isNotEmpty())
                <div class="card">
                    <div class="card-header">Autres queries de ce ménage</div>
                    <div class="list-group list-group-flush">
                        @foreach ($related as $other)
                            <a href="{{ route('queries.show', $other) }}" class="list-group-item list-group-item-action small d-flex justify-content-between gap-2">
                                <span class="text-truncate">{{ $other->message }}</span>
                                <span class="text-nowrap"><x-severity-badge :severity="$other->severity" /> <x-status-badge :status="$other->status" /></span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            @can('update', $query)
                <div class="card mb-3">
                    <div class="card-header">Traitement</div>
                    <div class="card-body">
                        <form id="query-status-form" method="POST" action="{{ route('queries.status', $query) }}" class="mb-3" data-confirm-when="status:irresoluble" data-confirm-variant="warning" data-confirm-title="Query irrésoluble" data-confirm="Déclarer la query {{ $query->code }} irrésoluble ? Elle sera fermée sans correction de la donnée.">
                            @csrf
                            <label class="form-label" for="status">Nouveau statut</label>
                            <select id="status" name="status" class="form-select mb-1" required>
                                @foreach (\App\Models\DataQuery::TRANSITIONS[$query->status] ?? [] as $status)
                                    <option value="{{ $status }}" data-help="{{ \App\Services\Quality\QueryWorkflow::help($status) }}" @selected(old('status') === $status)>{{ \App\Models\DataQuery::STATUSES[$status] }}</option>
                                @endforeach
                            </select>
                            <div id="status-help" class="form-text mb-2"></div>
                            <label class="form-label" for="comment">Commentaire / justification</label>
                            <textarea id="comment" name="comment" rows="3" class="form-control mb-2" maxlength="2000">{{ old('comment') }}</textarea>
                            <button class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Appliquer</button>
                        </form>
                        <form method="POST" action="{{ route('queries.comment', $query) }}">
                            @csrf
                            <label class="form-label" for="note">Ajouter un commentaire sans changer le statut</label>
                            <div class="input-group">
                                <input id="note" name="comment" class="form-control" maxlength="2000" required>
                                <button class="btn btn-outline-primary"><i class="bi bi-chat-left-text"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan

            <div class="card">
                <div class="card-header">Historique</div>
                <div class="card-body">
                    <div class="timeline">
                        @forelse ($query->events as $event)
                            <div class="event small">
                                <div class="text-muted">{{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->user?->name ?? 'Système' }}</div>
                                @if ($event->to_status)
                                    <div>
                                        @if ($event->from_status)<x-status-badge :status="$event->from_status" /> <i class="bi bi-arrow-right"></i>@endif
                                        <x-status-badge :status="$event->to_status" />
                                    </div>
                                @endif
                                @if ($event->comment)<div class="mt-1">{{ $event->comment }}</div>@endif
                            </div>
                        @empty
                            <p class="text-muted small mb-0">Aucun événement.</p>
                        @endforelse
                        <div class="event small">
                            <div class="text-muted">{{ $query->detected_at->format('d/m/Y H:i') }} · Moteur de queries</div>
                            <div>Query ouverte</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
