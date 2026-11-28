<x-layouts.app :title="'Query '.$query->code" page="query-show">
    <x-page-header :title="'Query '.$query->code" :subtitle="$query->label ?? $query->rule?->name ?? $query->rule_code">
        <a href="{{ auth()->user()->can('view dashboards') ? route('queries.index') : route('team.queries') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>{{ auth()->user()->can('view dashboards') ? 'Liste' : 'Mes queries' }}</a>
        @if ($query->household)
            <a href="{{ route('households.show', $query->household) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-house-door me-1"></i>Soumission</a>
        @endif
        {{-- Partage : le lien ouvre la query après connexion, selon les droits de la personne. --}}
        <a href="{{ $query->whatsappUrl() }}" target="_blank" rel="noopener" class="btn btn-sm btn-success" title="Envoyer la query par WhatsApp (l'ouverture demande la connexion)"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
        <button type="button" class="btn btn-sm btn-light" data-copy-link="{{ $query->shareUrl() }}" title="Copier le lien de la query"><i class="bi bi-link-45deg me-1"></i><span>Copier le lien</span></button>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-7">
            @include('queries._focus', ['focus' => $focus])
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ $query->code }} · {{ $query->label ?? $query->rule_code }} <x-rule-badge :code="$query->rule_code" :name="$query->rule?->name" class="ms-1" /></span>
                    <span class="text-nowrap"><x-severity-badge :severity="$query->severity" /> <x-status-badge :status="$query->status" /></span>
                </div>
                <div class="card-body">
                    @if ($query->isEscalatedObservation())
                        <div class="alert alert-info small d-flex gap-2 py-2">
                            <i class="bi bi-send-check"></i>
                            <div>
                                <strong>Observation de supervision</strong> envoyée dans les queries{{ $query->escalator ? ' par '.$query->escalator->name : '' }} le {{ $query->escalated_at->format('d/m/Y') }}.
                                @if ($query->isParadataObservation())
                                    Rien à corriger dans le formulaire : le binôme <strong>explique</strong> ce qui s'est passé (heure, durée, envoi, GPS), puis le superviseur et le data manager valident l'explication.
                                @else
                                    Elle se résout comme une query : corriger la valeur, ou la confirmer avec la source.
                                @endif
                            </div>
                        </div>
                    @endif
                    <h2 class="h6 text-muted mb-1">{{ $query->isEscalatedObservation() ? "Description de l'observation" : "Description de l'erreur" }}</h2>
                    <p class="fs-6">{{ $query->message }}</p>
                    @if ($query->variable)
                        {{-- Question à corriger : libellé du formulaire, nom de la variable et membre concerné. --}}
                        <div class="border-start border-4 border-primary bg-primary-subtle rounded-end px-3 py-2 mb-3">
                            <div class="small text-muted">{{ $query->isParadataObservation() ? 'Donnée concernée (paradonnée, non modifiable)' : 'Question à vérifier / corriger' }}</div>
                            <div class="fw-semibold">{{ $query->variable_label ?? $query->variable }}</div>
                            <div class="small"><code>{{ $query->variable }}</code>{{ $query->instance_path ? ' · '.\App\Exports\QueriesExport::memberLabel($query->instance_path) : '' }}</div>
                        </div>
                    @endif
                    @if ($query->suggestion)
                        <div class="alert alert-info small d-flex gap-2">
                            <i class="bi bi-lightbulb text-primary"></i>
                            <div><strong>Suggestion de résolution :</strong> {{ $query->suggestion }}</div>
                        </div>
                    @endif
                    <dl class="row small mb-0">
                        <dt class="col-sm-4">Code</dt><dd class="col-sm-8 fw-semibold">{{ $query->code }}</dd>
                        <dt class="col-sm-4">Statut de résolution</dt><dd class="col-sm-8">{{ $query->resolutionLabel() }} <span class="text-muted">({{ $query->statusLabel() }})</span></dd>
                        <dt class="col-sm-4">Règle</dt><dd class="col-sm-8"><span class="badge text-bg-light border">{{ $query->rule_code }}</span> {{ $query->rule?->name }}</dd>
                        <dt class="col-sm-4">Membre concerné</dt><dd class="col-sm-8">{{ \App\Exports\QueriesExport::memberLabel($query->instance_path) }}</dd>
                        <dt class="col-sm-4">Valeur(s) saisie(s)</dt>
                        <dd class="col-sm-8">
                            @if ($lines = $query->observedLines())
                                @foreach ($lines as $line)
                                    <div>{{ $line['label'] }} : <strong>{{ $line['value'] }}</strong> <code class="text-muted">{{ $line['variable'] }}</code></div>
                                @endforeach
                            @else
                                {{ $query->observed_value !== null && $query->variable ? \App\Models\DataCorrection::displayFor($query->variable, $query->observed_value) : ($query->observed_value ?? '—') }}
                            @endif
                        </dd>
                        @if ($query->expectedForDisplay())
                            <dt class="col-sm-4">Attendu</dt><dd class="col-sm-8">{{ $query->expectedForDisplay() }}</dd>
                        @endif
                        <dt class="col-sm-4">Date de génération</dt><dd class="col-sm-8">{{ $query->detected_at->format('d/m/Y H:i') }} · dernière détection {{ $query->last_detected_at->format('d/m/Y H:i') }}</dd>
                        <dt class="col-sm-4">Assignée à</dt>
                        <dd class="col-sm-8">
                            @if ($query->assignee)
                                {{ $query->assignee->name }}
                                <span class="text-muted">· le {{ $query->assigned_at?->format('d/m/Y') }}</span>
                                @if ($query->due_on)
                                    · échéance {{ $query->due_on->format('d/m/Y') }}
                                    @if ($query->isOverdue())<span class="badge text-bg-danger ms-1">en retard</span>@endif
                                @endif
                            @else
                                <span class="text-muted">Non assignée</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">Réponse</dt><dd class="col-sm-8">{{ $query->responder ? $query->responder->name.' · '.$query->responded_at?->format('d/m/Y H:i') : '—' }}</dd>
                        @if ($query->resolvedBy)
                            <dt class="col-sm-4">Résolue par</dt>
                            <dd class="col-sm-8">la correction proposée dans <a href="{{ route('queries.show', $query->resolvedBy) }}">{{ $query->resolvedBy->code }}</a> <span class="text-muted small">(fermée à sa validation, renvoyée si elle est refusée)</span></dd>
                        @endif
                        <dt class="col-sm-4">Vérification superviseur</dt><dd class="col-sm-8">{{ $query->supervisor ? $query->supervisor->name.' · '.$query->supervised_at?->format('d/m/Y H:i') : ($query->status === \App\Models\DataQuery::STATUS_CORRECTED || $query->status === \App\Models\DataQuery::STATUS_JUSTIFIED ? 'en attente du superviseur de la zone' : '—') }}</dd>
                        <dt class="col-sm-4">Validation finale</dt><dd class="col-sm-8">{{ $query->validator ? $query->validator->name : ($query->reviewer ? 'confiée à '.$query->reviewer->name : '—') }} @if ($doubleValidation)<span class="badge text-bg-light border ms-1" title="Validation par une autre personne que l'auteur de la réponse, avec signature électronique">double validation</span>@endif</dd>
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

            @if ($query->variable && $query->household && ! $query->isParadataObservation() && (auth()->user()->can('update', $query) || auth()->user()->can('validate', $query)) && ! $query->isResolved())
                <form method="POST" action="{{ route('queries.suggest', $query) }}" class="card mb-3">
                    @csrf
                    <div class="card-header"><i class="bi bi-eye me-1"></i>Valeur lue sur la photo</div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">Valeur lisible sur une photo ? Saisissez-la : elle sera proposée au binôme.</p>
                        <div class="row g-2">
                            <div class="col-sm-5">
                                <input name="suggested_value" class="form-control form-control-sm @error('suggested_value') is-invalid @enderror" value="{{ old('suggested_value', $query->suggested_value) }}" placeholder="{{ $correctionField && $correctionField['type'] === 'date' ? 'AAAA-MM-JJ' : 'valeur' }}" aria-label="Valeur lue">
                                @error('suggested_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-5">
                                <input name="suggested_note" class="form-control form-control-sm" value="{{ old('suggested_note', $query->suggested_note) }}" maxlength="255" placeholder="Photo utilisée, précision…" aria-label="Précision">
                            </div>
                            <div class="col-sm-2"><button class="btn btn-sm btn-outline-primary w-100">Enregistrer</button></div>
                        </div>
                        <div class="form-text">Laisser vide puis enregistrer pour retirer la suggestion.</div>
                    </div>
                </form>
                @if (\App\Services\Attachments\PhotoReader::enabled() && $photos)
                    <form method="POST" action="{{ route('queries.suggest-ai', $query) }}" class="mb-3 mt-n2 text-end">
                        @csrf
                        <button class="btn btn-sm btn-outline-info" onclick="this.disabled = true; this.form.submit(); this.innerHTML = 'Lecture en cours…';"><i class="bi bi-stars me-1"></i>Lire la valeur avec l'IA</button>
                        <div class="form-text">Envoie les photos à Claude (10 à 30 s). La valeur lue est proposée « à vérifier ».</div>
                    </form>
                @endif
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
                        <dt class="col-sm-4">Compte ODK</dt><dd class="col-sm-8">{{ $query->household?->submitter ?? '—' }}</dd>
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
            @php
                $user = auth()->user();
                $awaitingFinal = $query->status === \App\Models\DataQuery::STATUS_SUPERVISED;
                $paradata = $query->isParadataObservation();
                $options = collect(\App\Models\DataQuery::TRANSITIONS[$query->status] ?? [])
                    // Observation de paradonnées : rien à corriger, le binôme explique (valeur confirmée).
                    ->reject(fn ($status) => $paradata && $status === \App\Models\DataQuery::STATUS_CORRECTED)
                    ->filter(fn ($status) => \App\Services\Quality\QueryWorkflow::allowed($user, $query, $status))
                    ->mapWithKeys(fn ($status) => [$status => match ($status) {
                        \App\Models\DataQuery::STATUS_OPEN => in_array($query->status, [...\App\Models\DataQuery::RESPONSES, ...\App\Models\DataQuery::AWAITING_FINAL], true) ? 'Rejeter la réponse (retour au binôme)' : 'Rouvrir la query',
                        \App\Models\DataQuery::STATUS_JUSTIFIED => $paradata ? "Expliquer l'observation (rien à corriger)" : \App\Models\DataQuery::STATUSES[$status],
                        \App\Models\DataQuery::STATUS_SUPERVISED => 'Valider (vérification du superviseur) → validation finale',
                        \App\Models\DataQuery::STATUS_VALIDATED => match (true) {
                            // Réponse « âge confirmé » (MAJ_18…) : la validation ferme la query comme irrésoluble.
                            $query->closes_as === \App\Models\DataQuery::STATUS_UNRESOLVABLE => $awaitingFinal ? 'Validation finale : fermer comme irrésoluble' : 'Valider directement : fermer comme irrésoluble',
                            $awaitingFinal => 'Validation finale et fermeture',
                            default => 'Valider directement et fermer (sans vérification du superviseur)',
                        },
                        default => \App\Models\DataQuery::STATUSES[$status],
                    }]);
                $needsSignature = $doubleValidation && $user->can('validate', $query);
            @endphp

            @can('assign', $query)
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-person-check me-1"></i>Affectation</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('queries.assign', $query) }}">
                            @csrf
                            <label class="form-label" for="assigned_to">Assigner à</label>
                            <select id="assigned_to" name="assigned_to" class="form-select mb-2">
                                <option value="">— Personne (retirer l'affectation) —</option>
                                @foreach ($assignees as $assignee)
                                    <option value="{{ $assignee->id }}" @selected((int) old('assigned_to', $query->assigned_to) === $assignee->id)>{{ \App\Http\Controllers\DataQueryController::assigneeLabel($assignee) }}</option>
                                @endforeach
                            </select>
                            @error('assigned_to')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                            <div class="row g-2 mb-2">
                                <div class="col-sm-5">
                                    <label class="form-label" for="due_on">Échéance</label>
                                    <input id="due_on" type="date" name="due_on" class="form-control" value="{{ old('due_on', $query->due_on?->toDateString()) }}" min="{{ today()->toDateString() }}">
                                </div>
                                <div class="col-sm-7">
                                    <label class="form-label" for="assign-comment">Consigne</label>
                                    <input id="assign-comment" name="comment" class="form-control" maxlength="2000">
                                </div>
                            </div>
                            <div class="form-text mb-2">Sans échéance saisie : {{ (int) \App\Models\AppSetting::get(\App\Models\AppSetting::DUE_DAYS) }} jour(s) (paramètre).</div>
                            <button class="btn btn-outline-primary w-100"><i class="bi bi-person-check me-1"></i>Enregistrer l'affectation</button>
                        </form>
                    </div>
                </div>
            @endcan

            @can('assignReview', $query)
                @unless ($query->isResolved() && ! in_array($query->status, \App\Models\DataQuery::RESPONSES, true))
                    <div class="card mb-3">
                        <div class="card-header"><i class="bi bi-patch-check me-1"></i>Validation</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('queries.review-assign', $query) }}">
                                @csrf
                                <label class="form-label" for="reviewer_id">Confier la validation à</label>
                                <select id="reviewer_id" name="reviewer_id" class="form-select mb-2">
                                    <option value="">— Data manager (moi / l'équipe) —</option>
                                    @foreach ($reviewers as $reviewer)
                                        <option value="{{ $reviewer->id }}" @selected((int) $query->reviewer_id === $reviewer->id)>{{ \App\Http\Controllers\DataQueryController::assigneeLabel($reviewer) }}</option>
                                    @endforeach
                                </select>
                                @error('reviewer_id')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                                <input name="comment" class="form-control mb-2" maxlength="2000" placeholder="Consigne (facultatif)">
                                <button class="btn btn-outline-primary w-100"><i class="bi bi-patch-check me-1"></i>Enregistrer</button>
                            </form>
                        </div>
                    </div>
                @endunless
            @endcan

            @if ($pendingCorrections > 0 && $user->can('comment', $query))
                <div class="card mb-3 border-primary-subtle">
                    <div class="card-body">
                        <div class="small mb-2"><i class="bi bi-arrow-repeat me-1"></i><strong>{{ $pendingCorrections }} correction(s) proposée(s)</strong> sur ce ménage, en attente de validation. Rechargez les données pour résoudre les autres queries qui dépendaient de ces valeurs (date de naissance, dates de vaccination…).</div>
                        <form method="POST" action="{{ route('queries.reload', $query) }}">
                            @csrf
                            <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat me-1"></i>Recharger les données du ménage</button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($options->isNotEmpty() || $user->can('comment', $query))
                <div class="card mb-3">
                    <div class="card-header">Traitement</div>
                    <div class="card-body">
                        @if ($query->closes_as === \App\Models\DataQuery::STATUS_UNRESOLVABLE && in_array($query->status, [...\App\Models\DataQuery::RESPONSES, ...\App\Models\DataQuery::AWAITING_FINAL], true))
                            <div class="alert alert-secondary py-2 small"><i class="bi bi-info-circle me-1"></i>Réponse : valeur confirmée, rien à corriger. La validation finale fermera la query comme <strong>irrésoluble</strong>.</div>
                        @endif
                        @if ($options->isNotEmpty())
                            <form id="query-status-form" method="POST" action="{{ route('queries.status', $query) }}" class="mb-3" data-confirm-when="status:irresoluble" data-confirm-variant="warning" data-confirm-title="Query irrésoluble" data-confirm="Déclarer la query {{ $query->code }} irrésoluble ? Elle sera fermée sans correction de la donnée.">
                                @csrf
                                <label class="form-label" for="status">Action</label>
                                <select id="status" name="status" class="form-select mb-1" required>
                                    @foreach ($options as $status => $label)
                                        <option value="{{ $status }}" data-help="{{ $paradata && $status === \App\Models\DataQuery::STATUS_JUSTIFIED ? "Choisissez l'explication, ajoutez les précisions utiles : la réponse part au superviseur." : \App\Services\Quality\QueryWorkflow::help($status) }}" data-signature="{{ $needsSignature && in_array($status, [\App\Models\DataQuery::STATUS_VALIDATED, \App\Models\DataQuery::STATUS_UNRESOLVABLE], true) ? '1' : '0' }}" @selected(old('status') === $status)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div id="status-help" class="form-text mb-2"></div>
                                @error('status')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                                <div id="corrections-block" class="d-none mb-2" data-next-index="{{ $correctionField ? 1 + count($correctionField['related']) : 0 }}">
                                    <div class="small text-muted mb-1"><i class="bi bi-shield-lock me-1"></i>Correction appliquée à la base nettoyée ; ODK reste intact.</div>
                                    <div data-correction-rows>
                                        @if ($geoField)
                                            <div class="border rounded p-2 mb-2 bg-light-subtle" data-correction-row data-geo>
                                                <div class="small fw-semibold mb-1">Cochez le bon arrondissement et le bon village ; corrigez l'identifiant ménage si besoin</div>
                                                <input type="hidden" name="corrections[60][variable]" value="{{ $geoField['arr_var'] }}">
                                                <input type="hidden" name="corrections[60][optional]" value="1">
                                                <input type="hidden" name="corrections[60][group]" value="geo">
                                                <div class="small text-muted">{{ $geoField['arr_label'] }}</div>
                                                <div class="geo-choices border rounded bg-body p-2 mb-2" style="max-height: 180px; overflow:auto">
                                                    @foreach ($geoField['arrondissements'] as $choice)
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="corrections[60][new_value]" id="arr-{{ $choice['code'] }}" value="{{ $choice['code'] }}" data-geo-arr @checked($choice['code'] === $geoField['arr_selected'])>
                                                            <label class="form-check-label" for="arr-{{ $choice['code'] }}">{{ $choice['label'] }} @if ($choice['code'] === $geoField['arr_current'])<span class="badge text-bg-light border">saisi</span>@endif</label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <input type="hidden" name="corrections[61][variable]" value="{{ $geoField['village_var'] }}">
                                                <input type="hidden" name="corrections[61][optional]" value="1">
                                                <input type="hidden" name="corrections[61][group]" value="geo">
                                                <div class="d-flex justify-content-between align-items-center gap-2">
                                                    <span class="small text-muted">{{ $geoField['village_label'] }}</span>
                                                    <input type="search" class="form-control form-control-sm w-50" placeholder="Rechercher un village…" data-geo-search aria-label="Rechercher un village">
                                                </div>
                                                <div class="geo-choices border rounded bg-body p-2 mb-2" style="max-height: 240px; overflow:auto">
                                                    @foreach ($geoField['villages'] as $choice)
                                                        <div class="form-check" data-parent="{{ $choice['parent'] }}" data-name="{{ mb_strtolower($choice['label']) }}">
                                                            <input class="form-check-input" type="radio" name="corrections[61][new_value]" id="vil-{{ $choice['code'] }}" value="{{ $choice['code'] }}" @checked($choice['code'] === $geoField['village_selected'])>
                                                            <label class="form-check-label" for="vil-{{ $choice['code'] }}">{{ $choice['label'] }} @if ($choice['code'] === $geoField['village_current'])<span class="badge text-bg-light border">saisi</span>@endif @if ($choice['code'] === $geoField['village_proposed'])<span class="badge text-bg-success-subtle text-success-emphasis border">proposé</span>@endif</label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @if ($geoField['code_var'])
                                                    {{-- Identifiant ménage : peut être en cause aussi (format, code village). --}}
                                                    <input type="hidden" name="corrections[63][variable]" value="{{ $geoField['code_var'] }}">
                                                    <input type="hidden" name="corrections[63][optional]" value="1">
                                                    <input type="hidden" name="corrections[63][group]" value="geo">
                                                    <label class="form-label small mb-0" for="hh-code">Identifiant ménage <span class="text-muted">(saisi : <code>{{ $geoField['code_current'] ?: '—' }}</code>)</span></label>
                                                    <input id="hh-code" name="corrections[63][new_value]" class="form-control form-control-sm font-monospace mb-1" value="{{ $geoField['code_proposed'] ?? $geoField['code_current'] }}" maxlength="40">
                                                    <div class="form-text mb-2">
                                                        @if ($geoField['code_proposed'])<span class="badge text-bg-success-subtle text-success-emphasis border me-1">proposé</span>format corrigé, à vérifier avec le binôme. @endif
                                                        Format : ZONE-VIL-NN[-Sx]-MMM, ex. TCH-KAP-01-S1-124.
                                                    </div>
                                                @endif
                                                @include('queries._correction-reason', ['i' => 60])
                                            </div>
                                        @endif
                                        @if ($agentField)
                                            {{-- Agent à cocher : binômes de la zone sanitaire, binôme du compte ODK en tête. --}}
                                            <div class="border rounded p-2 mb-2 bg-light-subtle" data-correction-row>
                                                <div class="small fw-semibold mb-1">Cochez le bon agent</div>
                                                <input type="hidden" name="corrections[62][variable]" value="{{ $agentField['variable'] }}">
                                                <input type="hidden" name="corrections[62][optional]" value="1">
                                                <div class="geo-choices border rounded bg-body p-2 mb-2" style="max-height: 320px; overflow:auto">
                                                    @foreach ($agentField['teams'] as $team)
                                                        <div class="mb-2 {{ $team['proposed'] ? 'border border-success-subtle rounded p-1' : '' }}">
                                                            <div class="small fw-semibold">
                                                                {{ $team['id'] ? 'Binôme '.$team['label'] : $team['label'] }}
                                                                @if ($team['proposed'])<span class="badge text-bg-success-subtle text-success-emphasis border ms-1">binôme du compte ODK</span>@endif
                                                            </div>
                                                            @foreach ($team['agents'] as $agent)
                                                                <div class="form-check ms-2">
                                                                    <input class="form-check-input" type="radio" name="corrections[62][new_value]" id="agent-{{ $agent['code'] }}" value="{{ $agent['code'] }}" @checked($agent['code'] === $agentField['current'])>
                                                                    <label class="form-check-label" for="agent-{{ $agent['code'] }}">
                                                                        <code>{{ $agent['code'] }}</code> – {{ $agent['label'] }}
                                                                        @if ($agent['code'] === $agentField['current'])<span class="badge text-bg-light border">saisi</span>@endif
                                                                    </label>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @include('queries._correction-reason', ['i' => 62])
                                            </div>
                                        @endif
                                        @if ($correctionField && $correctionField['pair'])
                                            @php($noError = \App\Http\Controllers\DataQueryController::NO_ERROR[$query->rule_code] ?? null)
                                            @php($pairText = \App\Http\Controllers\DataQueryController::PAIR_TEXT[$query->rule_code] ?? [])
                                            <fieldset class="border border-warning-subtle rounded p-2 mb-2" data-pair-choice>
                                                <legend class="float-none w-auto small fw-semibold px-1 mb-1">{{ $noError['legend'] ?? $pairText['legend'] ?? 'Quelle valeur est fausse ?' }}</legend>
                                                <div class="small text-muted mb-1">{{ $noError['hint'] ?? $pairText['hint'] ?? 'Vérifiez sur le carnet laquelle est fausse.' }}</div>
                                                @foreach ($correctionField['pair'] as $k => $f)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="pair_choice" id="pair-{{ $k }}" value="{{ $k }}" required>
                                                        <label class="form-check-label" for="pair-{{ $k }}">@if (isset($noError['legend']))Oui : corriger @endif<strong>{{ $f['short_label'] ?? $f['label'] }}</strong> ({{ $f['display'] }})</label>
                                                    </div>
                                                @endforeach
                                                @if (count($correctionField['pair']) > 1)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="pair_choice" id="pair-all" value="all" required>
                                                        <label class="form-check-label" for="pair-all">{{ $pairText['all'] ?? (count($correctionField['pair']) > 2 ? 'Plusieurs' : 'Les deux') }}</label>
                                                    </div>
                                                @endif
                                                @if ($noError)
                                                    {{-- Rien à changer : la query est enregistrée comme valeur confirmée, avec cette justification
                                                         (MAJ_18 : fermée comme irrésoluble à la validation finale, ou tout de suite par le data manager). --}}
                                                    @php($closesNow = ($noError['closes_as'] ?? null) && \App\Services\Quality\QueryWorkflow::allowed($user, $query, $noError['closes_as']))
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="pair_choice" id="pair-none" value="none" required
                                                               @if ($closesNow && $needsSignature) data-signature="1" @endif>
                                                        <label class="form-check-label" for="pair-none">{{ $noError['label'] }}</label>
                                                        @if ($noError['closes_as'] ?? null)
                                                            <div class="form-text mt-0">{{ $closesNow ? 'La query sera fermée tout de suite comme irrésoluble.' : 'Après vérification, la validation finale fermera la query comme irrésoluble.' }}</div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </fieldset>
                                            @foreach ($correctionField['pair'] as $k => $f)
                                                @include('queries._correction-field', ['field' => $f, 'i' => $k, 'path' => $f['path'] ?? $correctionField['path'], 'suggested' => $k === 0 ? $correctionField['suggested'] : null, 'paired' => true])
                                            @endforeach
                                        @elseif ($correctionField)
                                            @include('queries._correction-field', ['field' => $correctionField, 'i' => 0, 'path' => $correctionField['path'], 'suggested' => $correctionField['suggested'], 'paired' => false])
                                        @endif
                                    </div>
                                    @if (in_array($query->rule_code, ['DUP_HOUSEHOLD', 'DUP_SUBMISSION'], true) && $query->household && ! $query->household->excluded_at)
                                        <div class="border border-danger-subtle rounded p-2 mb-2" data-correction-row>
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" id="exclude-toggle" data-exclude-toggle>
                                                <label class="form-check-label small fw-semibold" for="exclude-toggle">Exclure cette soumission ({{ $query->household_code }}) de la base nettoyée : doublon</label>
                                            </div>
                                            <div data-exclude-fields class="d-none">
                                                <input type="hidden" name="corrections[99][variable]" value="{{ \App\Models\DataCorrection::EXCLUSION }}" disabled>
                                                <input type="hidden" name="corrections[99][new_value]" value="1" disabled>
                                                @include('queries._correction-reason', ['i' => 99])
                                                <div class="form-text">Indiquez la soumission conservée. L'exclue reste dans la base brute.</div>
                                            </div>
                                        </div>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-add-correction><i class="bi bi-plus-lg me-1"></i>Corriger une autre question de la soumission</button>
                                    <template id="correction-template">
                                        <div class="border rounded p-2 mb-2" data-correction-row data-free-row>
                                            <div class="row g-2 mb-2">
                                                <div class="col-sm-4">
                                                    <label class="form-label small mb-0">Membre</label>
                                                    <select name="corrections[__i__][instance_path]" class="form-select form-select-sm" data-member>
                                                        @foreach ($members as $path => $memberLabel)
                                                            <option value="{{ $path }}" data-repeat="{{ $path === '' ? '' : preg_replace('/\\[\\d+\\]$/', '', \Illuminate\Support\Str::afterLast($path, '/')) }}" @selected($path === (string) $query->instance_path)>{{ $memberLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-sm-8">
                                                    <label class="form-label small mb-0">Question</label>
                                                    <select name="corrections[__i__][variable]" class="form-select form-select-sm" data-variable>
                                                        <option value="">— Choisir la question —</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="small mb-1 d-none" data-current-value></div>
                                            <label class="form-label small mb-0">Nouvelle valeur</label>
                                            <div data-value-slot><input name="corrections[__i__][new_value]" class="form-control form-control-sm mb-2"></div>
                                            @include('queries._correction-reason', ['i' => '__i__'])
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-correction>Retirer</button>
                                        </div>
                                    </template>
                                    <script type="application/json" id="variable-options">@json($variableOptions)</script>
                                    <script type="application/json" id="member-values">@json((object) $memberValues)</script>
                                </div>
                                @if ($paradata && $options->has(\App\Models\DataQuery::STATUS_JUSTIFIED))
                                    {{-- Zone de résolution des agents : explications usuelles de l'observation, puis précisions. --}}
                                    <fieldset id="explanation-block" class="border border-info-subtle rounded p-2 mb-2 d-none">
                                        <legend class="float-none w-auto small fw-semibold px-1 mb-1">Que s'est-il passé ?</legend>
                                        @foreach (\App\Models\SupervisionObservation::explanationsFor($query->rule_code) as $value => $text)
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="explanation" id="explanation-{{ $loop->index }}" value="{{ $value }}" @checked(old('explanation') === $value) disabled>
                                                <label class="form-check-label small" for="explanation-{{ $loop->index }}">{{ $text }}</label>
                                            </div>
                                        @endforeach
                                    </fieldset>
                                @endif
                                <label class="form-label" for="comment">{{ $paradata ? 'Précisions (obligatoires pour « Autre explication ») / motif' : 'Justification / motif' }}</label>
                                <textarea id="comment" name="comment" rows="3" class="form-control mb-2" maxlength="2000">{{ old('comment') }}</textarea>
                                @if ($needsSignature)
                                    <div id="signature-field" class="mb-2 d-none">
                                        <label class="form-label" for="password"><i class="bi bi-pen me-1"></i>Signature électronique : votre mot de passe</label>
                                        <input id="password" type="password" name="password" class="form-control" autocomplete="current-password">
                                        <div class="form-text">Query {{ $query->severity }} : validation signée, par une autre personne que l'auteur.</div>
                                    </div>
                                @endif
                                <button class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Appliquer</button>
                            </form>
                        @endif
                        @can('comment', $query)
                            <form method="POST" action="{{ route('queries.comment', $query) }}">
                                @csrf
                                <label class="form-label" for="note">Ajouter un commentaire sans changer le statut</label>
                                <div class="input-group">
                                    <input id="note" name="comment" class="form-control" maxlength="2000" required>
                                    <button class="btn btn-outline-primary"><i class="bi bi-chat-left-text"></i></button>
                                </div>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($corrections->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-pencil-square me-1"></i>Corrections (registre)</div>
                    <ul class="list-group list-group-flush small">
                        @foreach ($corrections as $correction)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between gap-2">
                                    <span><code>{{ $correction->variableLabel() }}</code>{{ $correction->instance_path ? ' · '.\App\Exports\QueriesExport::memberLabel($correction->instance_path) : '' }}</span>
                                    <span class="badge text-bg-{{ \App\Models\DataCorrection::STATUS_COLORS[$correction->status] ?? 'secondary' }} {{ $correction->status === 'remplacee' ? 'border' : '' }}">{{ $correction->statusLabel() }}</span>
                                </div>
                                <div>{{ $correction->displayValue($correction->old_value) }} <i class="bi bi-arrow-right"></i> <strong>{{ $correction->displayValue($correction->new_value) }}</strong> <span class="text-muted">(ODK : {{ $correction->displayValue($correction->source_value) }})</span></div>
                                <div class="text-muted">{{ $correction->reasonLabel() }}{{ $correction->source_reference ? ' · '.$correction->source_reference : '' }}{{ $correction->comment ? ' · '.$correction->comment : '' }}</div>
                                <div class="text-muted">Proposée par {{ $correction->proposer?->name ?? 'Système' }} le {{ $correction->proposed_at->format('d/m/Y H:i') }}@if ($correction->decider) · {{ $correction->status === 'rejetee' ? 'rejetée' : 'décidée' }} par {{ $correction->decider->name }} le {{ $correction->decided_at?->format('d/m/Y H:i') }}@endif @if ($correction->signed)<span class="badge text-bg-success ms-1"><i class="bi bi-pen me-1"></i>signée</span>@endif</div>
                                @if ($correction->decision_comment)<div class="text-muted fst-italic">{{ $correction->decision_comment }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card">
                <div class="card-header">Historique</div>
                <div class="card-body">
                    <div class="timeline">
                        @forelse ($query->events as $event)
                            <div class="event small">
                                <div class="text-muted">
                                    {{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->user?->name ?? 'Système' }}
                                    @if ($event->type === 'assignment')<span class="badge text-bg-light border ms-1">affectation</span>@endif
                                    @if ($event->type === 'correction')<span class="badge text-bg-light border ms-1">base nettoyée</span>@endif
                                    @if ($event->signed)<span class="badge text-bg-success ms-1" title="Action signée par mot de passe"><i class="bi bi-pen me-1"></i>signée</span>@endif
                                </div>
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
