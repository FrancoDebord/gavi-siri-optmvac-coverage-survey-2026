<x-layouts.app :title="'Factures et rapports · '.$space->name" page="photo-projects">
    @php($query = array_filter(\Illuminate\Support\Arr::except($filters, ['incomplets'])))
    <x-page-header :title="$space->name" :subtitle="number_format($total, 0, ',', ' ').' document(s) · '.number_format($totalSize / 1048576, 1, ',', ' ').' Mo · '.($space->linked_to_survey ? 'agents de l\'enquête' : 'nom libre')">
        <a href="{{ route('document-spaces.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Espaces de dépôt</a>
        <a href="{{ route('document-spaces.download', [$space] + $query) }}" class="btn btn-sm btn-primary @if (! $total) disabled @endif">
            <i class="bi bi-file-earmark-zip me-1"></i>Télécharger {{ $query ? 'la sélection' : 'tous les documents' }} (ZIP)
        </a>
        <button type="button" class="btn btn-sm btn-outline-primary @if (! $total) disabled @endif" data-bs-toggle="collapse" data-bs-target="#send-mail" aria-expanded="{{ $errors->has('recipients') ? 'true' : 'false' }}">
            <i class="bi bi-envelope me-1"></i>Envoyer par e-mail
        </button>
    </x-page-header>

    {{-- Envoi par e-mail de la sélection (filtres de la liste des documents) --}}
    <div id="send-mail" class="collapse @if ($errors->has('recipients') || $errors->has('subject')) show @endif mb-3">
        <div class="card border-primary">
            <div class="card-header"><i class="bi bi-envelope me-1"></i>Envoyer {{ $query ? 'la sélection' : 'tous les documents' }} par e-mail
                <span class="text-muted small ms-1">{{ $documents->total() }} document(s) · {{ number_format($selectionSize / 1048576, 1, ',', ' ') }} Mo</span></div>
            <div class="card-body">
                <form method="POST" action="{{ route('document-spaces.email', $space) }}" class="row g-3">
                    @csrf
                    @foreach (\Illuminate\Support\Arr::only($query, ['type', 'status', 'collector_id', 'health_zone_id']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                    <div class="col-lg-6">
                        <label for="recipients" class="form-label">Destinataires <span class="text-danger">*</span></label>
                        <textarea id="recipients" name="recipients" rows="2" required maxlength="2000" class="form-control @error('recipients') is-invalid @enderror"
                                  placeholder="comptabilite@exemple.org, coordination@exemple.org">{{ old('recipients') }}</textarea>
                        @error('recipients')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Séparées par des virgules ou des retours à la ligne · {{ \App\Http\Controllers\DocumentSpaceController::MAX_RECIPIENTS }} au plus.</div>
                    </div>
                    <div class="col-lg-6">
                        <label for="subject" class="form-label">Objet <span class="text-danger">*</span></label>
                        <input id="subject" name="subject" type="text" required maxlength="200" class="form-control @error('subject') is-invalid @enderror"
                               value="{{ old('subject', 'Factures et rapports d\'activité · '.$space->name) }}">
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="mail-message" class="form-label">Message</label>
                        <textarea id="mail-message" name="message" rows="3" maxlength="3000" class="form-control" placeholder="Bonjour, veuillez trouver ci-joint… (facultatif)">{{ old('message') }}</textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Envoyer</button>
                        <span class="small text-muted">
                            @if ($selectionSize <= \App\Http\Controllers\DocumentSpaceController::ATTACH_MAX_BYTES)
                                Fichiers en pièces jointes, et lien de téléchargement (ZIP).
                            @else
                                Sélection trop lourde pour des pièces jointes : le message contient un lien de téléchargement (ZIP) valable {{ \App\Http\Controllers\DocumentSpaceController::LINK_DAYS }} jours, sans compte.
                            @endif
                            Récapitulatif par agent dans le message. Filtrez la liste (type, statut, agent, zone) pour n'envoyer qu'une partie.
                        </span>
                    </div>
                </form>
                @if ($sends->isNotEmpty())
                    <hr>
                    <div class="small fw-semibold mb-1">Derniers envois</div>
                    <ul class="list-unstyled small mb-0">
                        @foreach ($sends as $send)
                            <li class="text-muted">{{ $send->created_at->format('d/m/Y H:i') }} · {{ $send->user?->name ?? '—' }} → {{ $send->properties['destinataires'] ?? '' }} · {{ $send->properties['documents'] ?? 0 }} document(s)</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        {{-- Lien et PIN à partager --}}
        <div class="col-lg-7">
            <div class="card h-100 border-primary">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-share me-1"></i>À partager aux agents</span>
                    @if ($space->active)<span class="badge text-bg-success">Lien ouvert</span>@else<span class="badge text-bg-secondary">Lien fermé</span>@endif
                </div>
                <div class="card-body">
                    <label class="form-label small text-muted mb-1" for="share-link">Lien (sans compte)</label>
                    <div class="input-group mb-3">
                        <input id="share-link" type="text" class="form-control font-monospace small" value="{{ $space->publicUrl() }}" readonly>
                        <button type="button" class="btn btn-outline-secondary" data-copy-text="{{ $space->publicUrl() }}" title="Copier le lien"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <label class="form-label small text-muted mb-1">Code PIN</label>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="fs-3 fw-bold font-monospace" style="letter-spacing: .2em">{{ $space->pin }}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-copy-text="{{ $space->pin }}" title="Copier le code PIN"><i class="bi bi-clipboard"></i></button>
                    </div>
                    @php($message = "Dépôt des factures et rapports d'activité « {$space->name} » : {$space->publicUrl()}\nCode PIN : {$space->pin}")
                    <button type="button" class="btn btn-sm btn-outline-primary" data-copy-text="{{ $message }}"><i class="bi bi-chat-text me-1"></i>Copier le message (lien + PIN)</button>
                    <a class="btn btn-sm btn-outline-success" href="https://wa.me/?text={{ rawurlencode($message) }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp me-1"></i>Partager sur WhatsApp</a>
                    <div class="form-text mt-2">Les agents ne voient que leurs propres dépôts (sur leur appareil) : les factures des autres ne sont visibles qu'ici.</div>
                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('document-spaces.regenerate', $space) }}" data-confirm="Créer un nouveau lien ? L'ancien lien ne fonctionnera plus : il faudra partager le nouveau." data-confirm-title="Nouveau lien">
                            @csrf <input type="hidden" name="what" value="lien">
                            <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>Nouveau lien</button>
                        </form>
                        <form method="POST" action="{{ route('document-spaces.regenerate', $space) }}" data-confirm="Créer un nouveau code PIN ? L'ancien ne fonctionnera plus et chaque appareil devra saisir le nouveau." data-confirm-title="Nouveau code PIN">
                            @csrf <input type="hidden" name="what" value="pin">
                            <button class="btn btn-sm btn-outline-warning"><i class="bi bi-key me-1"></i>Nouveau code PIN</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Réglages --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-gear me-1"></i>Réglages</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('document-spaces.update', $space) }}">
                        @csrf @method('PUT')
                        <div class="mb-2">
                            <label for="name" class="form-label small">Nom de l'espace</label>
                            <input id="name" name="name" type="text" maxlength="150" required class="form-control form-control-sm" value="{{ old('name', $space->name) }}">
                        </div>
                        <div class="mb-2">
                            <label for="description" class="form-label small">Consignes aux agents</label>
                            <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control form-control-sm">{{ old('description', $space->description) }}</textarea>
                        </div>
                        <div class="form-check">
                            <input id="linked_to_survey" name="linked_to_survey" type="checkbox" value="1" class="form-check-input" @checked($space->linked_to_survey)>
                            <label for="linked_to_survey" class="form-check-label small">Agents de l'enquête GAVI (liste des agents)</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input id="active" name="active" type="checkbox" value="1" class="form-check-input" @checked($space->active)>
                            <label for="active" class="form-check-label small">Lien ouvert (dépôts)</label>
                        </div>
                        <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
                    </form>
                    <hr>
                    <form method="POST" action="{{ route('document-spaces.destroy', $space) }}" data-confirm="Supprimer l'espace « {{ $space->name }} » et ses {{ $total }} document(s) ? Cette action est définitive." data-confirm-variant="danger" data-confirm-label="Supprimer" data-confirm-title="Supprimer l'espace">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer l'espace et ses documents</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Suivi par agent : qui a déposé sa facture et son rapport --}}
    @if ($space->linked_to_survey)
        @php($complete = $tracking->filter(fn ($r) => $r['facture'] && $r['rapport'])->count())
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-person-check me-1"></i>Suivi par agent
                    @unless ($filters['incomplets'])<span class="text-muted small ms-1">{{ $complete }} / {{ $tracking->count() }} avec facture et rapport</span>@endunless</span>
                <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                    @foreach (\Illuminate\Support\Arr::only($query, ['type', 'status', 'collector_id']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                    <select name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Zone de santé">
                        <option value="">Toutes les zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected($zone->id === $filters['health_zone_id'])>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-check form-switch mb-0">
                        <input id="incomplets" name="incomplets" type="checkbox" value="1" class="form-check-input" onchange="this.form.submit()" @checked($filters['incomplets'])>
                        <label for="incomplets" class="form-check-label small">Dossiers incomplets seulement</label>
                    </div>
                </form>
            </div>
            <div class="card-body p-0 table-responsive" style="max-height: 420px">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light sticky-top"><tr><th>Agent</th><th>Binôme</th><th>Zone</th>@foreach (\App\Models\AgentDocument::TYPES as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($tracking as $row)
                            <tr>
                                <td><a href="{{ route('document-spaces.show', [$space, 'collector_id' => $row['collector']->id]) }}">{{ $row['collector']->name }}</a> <span class="text-muted small">({{ $row['collector']->code }})</span></td>
                                <td class="small">{{ $row['collector']->team?->code ?? '—' }}</td>
                                <td class="small">{{ $row['collector']->healthZone?->name ?? '—' }}</td>
                                @foreach (array_keys(\App\Models\AgentDocument::TYPES) as $type)
                                    <td>
                                        @if ($row[$type])
                                            <x-agent-document-status :status="$row[$type]['status']" />
                                            <span class="small text-muted">{{ $row[$type]['at']->format('d/m') }}{{ $row[$type]['count'] > 1 ? ' · '.$row[$type]['count'].' fichiers' : '' }}</span>
                                        @else
                                            <span class="badge text-bg-warning">Manquant</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">{{ $filters['incomplets'] ? 'Tous les dossiers sont complets.' : 'Aucun agent.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Documents déposés --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Documents déposés <span class="text-muted small">({{ $documents->total() }})</span>
                @if ($documents->isNotEmpty())
                    <button type="button" class="btn btn-sm btn-outline-dark ms-2" data-doc-open-all><i class="bi bi-eye me-1"></i>Visionner {{ $documents->count() > 1 ? 'les '.$documents->count().' documents' : 'le document' }}</button>
                @endif
            </span>
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                @if ($filters['health_zone_id'])<input type="hidden" name="health_zone_id" value="{{ $filters['health_zone_id'] }}">@endif
                <select name="type" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Type de document">
                    <option value="">Tous les types</option>
                    @foreach (\App\Models\AgentDocument::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Statut">
                    <option value="">Tous les statuts</option>
                    @foreach (\App\Models\AgentDocument::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @if ($agents->isNotEmpty())
                    <select name="collector_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Agent">
                        <option value="">Tous les agents</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected($agent->id === $filters['collector_id'])>{{ $agent->name }} ({{ $agent->code }})</option>
                        @endforeach
                    </select>
                @endif
                @if ($query)<a href="{{ route('document-spaces.show', $space) }}" class="btn btn-sm btn-link">Effacer les filtres</a>@endif
            </form>
        </div>
        <div class="card-body p-0 table-responsive" data-doc-viewer>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Déposé le</th><th>Agent</th><th>Type</th><th>Fichier</th><th>Statut</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr>
                            <td class="small text-nowrap">{{ $document->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $document->authorLabel() }}
                                @if ($document->team)<div class="small text-muted">Binôme {{ $document->team->code }}</div>@endif</td>
                            <td class="small">{{ $document->typeLabel() }}</td>
                            <td>
                                {{-- Clic : visionneuse (lib/document-viewer.js) ; sans JS ou Ctrl+clic, le fichier s'ouvre. --}}
                                <a href="{{ route('document-spaces.file', [$space, $document]) }}" target="_blank" rel="noopener" title="Visionner"
                                   data-doc-item
                                   data-kind="{{ $document->viewer() }}"
                                   data-icon="{{ $document->icon() }}"
                                   data-download="{{ route('document-spaces.file', [$space, $document, 'telecharger' => 1]) }}"
                                   data-review="{{ route('document-spaces.review', [$space, $document]) }}"
                                   data-name="{{ $document->original_name }}"
                                   data-type="{{ $document->typeLabel() }}"
                                   data-author="{{ $document->authorLabel() }}"
                                   data-team="{{ $document->team ? 'Binôme '.$document->team->code : '' }}"
                                   data-date="{{ $document->created_at->format('d/m/Y H:i') }}"
                                   data-size="{{ number_format($document->size / 1024, 0, ',', ' ') }} Ko"
                                   data-comment="{{ $document->comment }}"
                                   data-status="{{ $document->status }}"
                                   data-status-label="{{ $document->statusLabel() }}"
                                   data-note="{{ $document->review_note }}"><i class="bi bi-{{ $document->icon() }} me-1"></i>{{ \Illuminate\Support\Str::limit($document->original_name, 40) }}</a>
                                <span class="small text-muted">· {{ number_format($document->size / 1024, 0, ',', ' ') }} Ko</span>
                                @if ($document->comment)<div class="small text-muted fst-italic">{{ $document->comment }}</div>@endif
                            </td>
                            <td>
                                <x-agent-document-status :status="$document->status" />
                                @if ($document->review_note)<div class="small text-muted">{{ $document->review_note }}</div>@endif
                                @if ($document->reviewer)<div class="small text-muted">{{ $document->reviewer->name }}, {{ $document->reviewed_at?->format('d/m') }}</div>@endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                    @if ($document->status !== \App\Models\AgentDocument::STATUS_VALID)
                                        <form method="POST" action="{{ route('document-spaces.review', [$space, $document]) }}">
                                            @csrf @method('PUT') <input type="hidden" name="status" value="valide">
                                            <button class="btn btn-sm btn-outline-success" title="Valider"><i class="bi bi-check-lg"></i> Valider</button>
                                        </form>
                                    @endif
                                    <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#redo-{{ $document->id }}" title="Demander une correction"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    <form method="POST" action="{{ route('document-spaces.documents.destroy', [$space, $document]) }}" data-confirm="Supprimer « {{ $document->original_name }} » ? Cette action est définitive." data-confirm-variant="danger" data-confirm-label="Supprimer">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                                <form id="redo-{{ $document->id }}" method="POST" action="{{ route('document-spaces.review', [$space, $document]) }}" class="collapse mt-2">
                                    @csrf @method('PUT') <input type="hidden" name="status" value="a_refaire">
                                    <div class="input-group input-group-sm">
                                        <input name="review_note" type="text" maxlength="500" required class="form-control" placeholder="Ce qu'il faut corriger" value="{{ $document->review_note }}">
                                        <button class="btn btn-warning">À refaire</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun document{{ $query ? ' pour ces filtres' : ' déposé pour le moment' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($documents->hasPages())<div class="card-footer">{{ $documents->links() }}</div>@endif
    </div>
</x-layouts.app>
