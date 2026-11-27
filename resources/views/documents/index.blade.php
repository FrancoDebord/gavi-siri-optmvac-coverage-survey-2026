<x-layouts.app title="Factures et rapports">
    <x-page-header title="Factures et rapports d'activité" subtitle="Un espace de dépôt : lien public et code PIN à partager aux agents, qui envoient leur facture et leur rapport sans compte" />

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Espaces de dépôt</div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Espace</th><th class="text-end">Factures</th><th class="text-end">Rapports</th><th>Dernier dépôt</th><th>Lien</th></tr></thead>
                        <tbody>
                            @forelse ($spaces as $space)
                                <tr>
                                    <td><a href="{{ route('document-spaces.show', $space) }}" class="fw-semibold">{{ $space->name }}</a>
                                        <div class="small text-muted">{{ $space->linked_to_survey ? 'Agents de l\'enquête' : 'Nom libre' }} · créé le {{ $space->created_at->format('d/m/Y') }}{{ $space->creator ? ' par '.$space->creator->name : '' }}</div></td>
                                    <td class="text-end">{{ number_format($space->invoices_count, 0, ',', ' ') }}</td>
                                    <td class="text-end">{{ number_format($space->reports_count, 0, ',', ' ') }}</td>
                                    <td class="small">{{ $space->documents_max_created_at ? \Carbon\CarbonImmutable::parse($space->documents_max_created_at)->format('d/m/Y H:i') : '—' }}</td>
                                    <td>@if ($space->active)<span class="badge text-bg-success">Ouvert</span>@else<span class="badge text-bg-secondary">Fermé</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">Aucun espace. Créez-en un pour recevoir les factures et rapports des agents.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-1"></i>Nouvel espace de dépôt</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('document-spaces.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom de l'espace <span class="text-danger">*</span></label>
                            <input id="name" name="name" type="text" maxlength="150" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="Ex. GAVI SIRI · Round 2 · Paiement">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Consignes aux agents</label>
                            <textarea id="description" name="description" rows="3" maxlength="2000" class="form-control" placeholder="Modèle de facture, période, date limite… (facultatif)">{{ old('description') }}</textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input id="linked_to_survey" name="linked_to_survey" type="checkbox" value="1" class="form-check-input" @checked(old('linked_to_survey', true))>
                            <label for="linked_to_survey" class="form-check-label">Agents de l'enquête GAVI</label>
                            <div class="form-text">Coché : l'agent choisit son nom dans la liste des agents (suivi de qui a déposé quoi). Décoché : nom saisi librement.</div>
                        </div>
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Créer l'espace</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
