<x-layouts.app title="Photos de terrain">
    <x-page-header title="Photos de terrain" subtitle="Un espace par projet : lien public et code PIN à partager aux binômes, qui déposent leurs photos sans compte" />

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Espaces photos</div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Projet</th><th>Formulaire</th><th class="text-end">Photos</th><th>Dernier dépôt</th><th>Lien</th></tr></thead>
                        <tbody>
                            @forelse ($projects as $project)
                                <tr>
                                    <td><a href="{{ route('photo-projects.show', $project) }}" class="fw-semibold">{{ $project->name }}</a>
                                        <div class="small text-muted">Créé le {{ $project->created_at->format('d/m/Y') }}{{ $project->creator ? ' par '.$project->creator->name : '' }}</div></td>
                                    <td class="small">{{ $project->linked_to_survey ? 'Binôme et village de l\'enquête' : 'Nom et lieu libres' }}</td>
                                    <td class="text-end">{{ number_format($project->photos_count, 0, ',', ' ') }}</td>
                                    <td class="small">{{ $project->photos_max_created_at ? \Carbon\CarbonImmutable::parse($project->photos_max_created_at)->format('d/m/Y H:i') : '—' }}</td>
                                    <td>@if ($project->active)<span class="badge text-bg-success">Ouvert</span>@else<span class="badge text-bg-secondary">Fermé</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">Aucun espace photos. Créez-en un pour votre projet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-1"></i>Nouvel espace photos</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('photo-projects.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom du projet <span class="text-danger">*</span></label>
                            <input id="name" name="name" type="text" maxlength="150" required class="form-control" value="{{ old('name') }}" placeholder="Ex. GAVI SIRI · Round 2">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Consignes aux binômes</label>
                            <textarea id="description" name="description" rows="3" maxlength="2000" class="form-control" placeholder="Ce qu'il faut photographier (facultatif)">{{ old('description') }}</textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input id="linked_to_survey" name="linked_to_survey" type="checkbox" value="1" class="form-check-input" @checked(old('linked_to_survey', true))>
                            <label for="linked_to_survey" class="form-check-label">Projet de l'enquête GAVI</label>
                            <div class="form-text">Coché : le binôme et le village se choisissent dans les listes de l'enquête. Décoché (autre projet) : nom et lieu saisis librement.</div>
                        </div>
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Créer l'espace</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
