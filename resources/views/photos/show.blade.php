<x-layouts.app :title="'Photos · '.$project->name" page="photo-projects">
    <x-page-header :title="$project->name" :subtitle="number_format($total, 0, ',', ' ').' photo(s) · '.number_format($totalSize / 1048576, 1, ',', ' ').' Mo · '.($project->linked_to_survey ? 'binôme et village de l\'enquête' : 'nom et lieu libres')">
        <a href="{{ route('photo-projects.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Espaces photos</a>
        <a href="{{ route('photo-projects.download', [$project] + array_filter(['team_id' => $teamId])) }}" class="btn btn-sm btn-primary @if (! $total) disabled @endif">
            <i class="bi bi-file-earmark-zip me-1"></i>Télécharger {{ $teamId ? 'les photos du binôme' : 'toutes les photos' }} (ZIP)
        </a>
    </x-page-header>

    <div class="row g-3 mb-3">
        {{-- Lien et PIN à partager --}}
        <div class="col-lg-7">
            <div class="card h-100 border-primary">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-share me-1"></i>À partager aux binômes</span>
                    @if ($project->active)<span class="badge text-bg-success">Lien ouvert</span>@else<span class="badge text-bg-secondary">Lien fermé</span>@endif
                </div>
                <div class="card-body">
                    <label class="form-label small text-muted mb-1" for="share-link">Lien (sans compte)</label>
                    <div class="input-group mb-3">
                        <input id="share-link" type="text" class="form-control font-monospace small" value="{{ $project->publicUrl() }}" readonly>
                        <button type="button" class="btn btn-outline-secondary" data-copy-text="{{ $project->publicUrl() }}" title="Copier le lien"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <label class="form-label small text-muted mb-1">Code PIN</label>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="fs-3 fw-bold font-monospace" style="letter-spacing: .2em">{{ $project->pin }}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-copy-text="{{ $project->pin }}" title="Copier le code PIN"><i class="bi bi-clipboard"></i></button>
                    </div>
                    @php($message = "Espace photos « {$project->name} » : {$project->publicUrl()}\nCode PIN : {$project->pin}")
                    <button type="button" class="btn btn-sm btn-outline-primary" data-copy-text="{{ $message }}"><i class="bi bi-chat-text me-1"></i>Copier le message (lien + PIN)</button>
                    <a class="btn btn-sm btn-outline-success" href="https://wa.me/?text={{ rawurlencode($message) }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp me-1"></i>Partager sur WhatsApp</a>
                    <div class="form-text mt-2">Conseil : envoyez le code PIN séparément du lien si le lien risque de circuler hors des équipes.</div>
                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('photo-projects.regenerate', $project) }}" data-confirm="Créer un nouveau lien ? L'ancien lien ne fonctionnera plus : il faudra partager le nouveau." data-confirm-title="Nouveau lien">
                            @csrf <input type="hidden" name="what" value="lien">
                            <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>Nouveau lien</button>
                        </form>
                        <form method="POST" action="{{ route('photo-projects.regenerate', $project) }}" data-confirm="Créer un nouveau code PIN ? L'ancien ne fonctionnera plus et chaque appareil devra saisir le nouveau." data-confirm-title="Nouveau code PIN">
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
                    <form method="POST" action="{{ route('photo-projects.update', $project) }}">
                        @csrf @method('PUT')
                        <div class="mb-2">
                            <label for="name" class="form-label small">Nom du projet</label>
                            <input id="name" name="name" type="text" maxlength="150" required class="form-control form-control-sm" value="{{ old('name', $project->name) }}">
                        </div>
                        <div class="mb-2">
                            <label for="description" class="form-label small">Consignes aux binômes</label>
                            <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control form-control-sm">{{ old('description', $project->description) }}</textarea>
                        </div>
                        <div class="form-check">
                            <input id="linked_to_survey" name="linked_to_survey" type="checkbox" value="1" class="form-check-input" @checked($project->linked_to_survey)>
                            <label for="linked_to_survey" class="form-check-label small">Projet de l'enquête GAVI (listes binômes et villages)</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input id="active" name="active" type="checkbox" value="1" class="form-check-input" @checked($project->active)>
                            <label for="active" class="form-check-label small">Lien ouvert (dépôts et consultation)</label>
                        </div>
                        <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
                    </form>
                    <hr>
                    <form method="POST" action="{{ route('photo-projects.destroy', $project) }}" data-confirm="Supprimer l'espace « {{ $project->name }} » et ses {{ $total }} photo(s) ? Cette action est définitive." data-confirm-variant="danger" data-confirm-label="Supprimer" data-confirm-title="Supprimer l'espace photos">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer l'espace et ses photos</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Photos déposées</span>
            @if ($teams->isNotEmpty())
                <form method="GET" class="d-flex align-items-center gap-2">
                    <label for="filter-team" class="small text-muted mb-0">Binôme</label>
                    <select id="filter-team" name="team_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected($team->id === $teamId)>{{ $team->label() }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>
        <div class="card-body">
            @include('photos._gallery', ['photos' => $photos, 'project' => $project, 'canDelete' => true])
        </div>
    </div>
</x-layouts.app>
