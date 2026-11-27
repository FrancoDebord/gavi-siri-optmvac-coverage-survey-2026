<x-layouts.public :title="$space->name">
    <div class="mx-auto" style="max-width: 760px">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <i class="bi bi-folder2-open fs-2 text-primary"></i>
            <div>
                <h1 class="h4 mb-0">{{ $space->name }}</h1>
                <div class="small text-muted">Dépôt des factures et rapports d'activité</div>
            </div>
        </div>
        @if ($space->description)
            <p class="text-muted small">{!! nl2br(e($space->description)) !!}</p>
        @endif

        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Déposer mes documents</div>
            <div class="card-body">
                <form method="POST" action="{{ route('documents.public.upload', $space->token) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3">
                        @if ($space->linked_to_survey)
                            <div class="col-12">
                                <label for="collector_id" class="form-label">Votre nom <span class="text-danger">*</span></label>
                                <select id="collector_id" name="collector_id" class="form-select @error('collector_id') is-invalid @enderror" data-search="true">
                                    <option value="">Choisir votre nom…</option>
                                    @foreach ($collectors->groupBy(fn ($c) => $c->healthZone?->name ?? 'Autres') as $zone => $group)
                                        <optgroup label="{{ $zone }}">
                                            @foreach ($group as $collector)
                                                <option value="{{ $collector->id }}" @selected(old('collector_id') == $collector->id)>{{ $collector->name }} ({{ $collector->code }}){{ $collector->team ? ' · binôme '.$collector->team->code : '' }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error('collector_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <details class="mt-2" @if (old('author_name') || $errors->has('author_name')) open @endif>
                                    <summary class="small text-muted">Vous n'êtes pas dans la liste (superviseur, autre intervenant) ?</summary>
                                    <input id="author_name" name="author_name" type="text" maxlength="120" class="form-control mt-2 @error('author_name') is-invalid @enderror"
                                           value="{{ old('author_name') }}" placeholder="Nom et prénom">
                                    @error('author_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </details>
                            </div>
                        @else
                            <div class="col-12">
                                <label for="author_name" class="form-label">Votre nom et prénom <span class="text-danger">*</span></label>
                                <input id="author_name" name="author_name" type="text" maxlength="120" required class="form-control @error('author_name') is-invalid @enderror" value="{{ old('author_name') }}">
                                @error('author_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif

                        <div class="col-12">
                            <div class="form-label">Type de document <span class="text-danger">*</span></div>
                            @foreach (\App\Models\AgentDocument::TYPES as $value => $label)
                                <div class="form-check form-check-inline">
                                    <input id="type-{{ $value }}" name="type" type="radio" value="{{ $value }}" class="form-check-input @error('type') is-invalid @enderror" required @checked(old('type') === $value)>
                                    <label for="type-{{ $value }}" class="form-check-label">{{ $label }}</label>
                                </div>
                            @endforeach
                            @error('type')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label for="files" class="form-label">Fichier(s) <span class="text-danger">*</span></label>
                            <input id="files" name="files[]" type="file" multiple required class="form-control @if ($errors->has('files') || $errors->has('files.*')) is-invalid @endif"
                                   accept=".{{ implode(',.', $extensions) }}">
                            @if ($errors->has('files') || $errors->has('files.*'))
                                <div class="invalid-feedback">{{ $errors->first('files') ?: collect($errors->get('files.*'))->flatten()->first() }}</div>
                            @endif
                            <div class="form-text">PDF, photo du document (JPEG, PNG, WebP), Word ou Excel · jusqu'à {{ \App\Http\Controllers\PublicDocumentController::MAX_FILES }} fichiers, {{ number_format($maxKb / 1024, 0, ',', ' ') }} Mo chacun. Une facture en plusieurs pages peut être envoyée en plusieurs photos.</div>
                        </div>

                        <div class="col-12">
                            <label for="comment" class="form-label">Commentaire</label>
                            <input id="comment" name="comment" type="text" maxlength="500" class="form-control" value="{{ old('comment') }}" placeholder="Période couverte, précisions… (facultatif)">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" onclick="this.form.checkValidity() && setTimeout(() => { this.disabled = true; this.innerHTML = 'Envoi en cours…'; })">
                        <i class="bi bi-send me-1"></i>Envoyer
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-check2-circle me-1"></i>Mes dépôts sur cet appareil</div>
            @if ($mine->isEmpty())
                <div class="card-body text-muted small">Aucun document déposé depuis cet appareil pour le moment. Seuls les coordonnateurs du projet voient les documents déposés.</div>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($mine as $document)
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <i class="bi bi-{{ $document->icon() }} me-1"></i><span class="fw-semibold">{{ $document->typeLabel() }}</span>
                                · {{ $document->original_name }}
                                <div class="small text-muted">{{ $document->authorLabel() }} · déposé le {{ $document->created_at->format('d/m/Y à H:i') }}</div>
                                @if ($document->status === \App\Models\AgentDocument::STATUS_REDO && $document->review_note)
                                    <div class="small text-danger"><i class="bi bi-exclamation-circle me-1"></i>À corriger : {{ $document->review_note }}</div>
                                @endif
                            </div>
                            <x-agent-document-status :status="$document->status" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.public>
