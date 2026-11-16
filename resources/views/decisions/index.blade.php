@php
    $status = request('statut', 'tous');
@endphp
<x-layouts.app title="Décisions de terrain">
    <x-page-header title="Décisions de terrain" subtitle="Registre des décisions prises sur le terrain pour la conduite des activités : quoi, pourquoi, pour quelle portée, et si elles sont toujours en vigueur.">
        @can('export data')
            <a href="{{ route('decisions.export', request()->query()) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Exporter le registre</a>
        @endcan
        @can('manage decisions')
            <a href="{{ route('decisions.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle décision</a>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-9">
            <form method="GET" class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="q" class="form-label small mb-0">Recherche</label>
                            <input id="q" type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Titre, contexte ou décision…">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="categorie" class="form-label small mb-0">Catégorie</label>
                            <select id="categorie" name="categorie" class="form-select form-select-sm">
                                <option value="">Toutes</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((int) request('categorie') === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($zones->isNotEmpty())
                            <div class="col-6 col-md-2">
                                <label for="zone" class="form-label small mb-0">Portée</label>
                                <select id="zone" name="health_zone_id" class="form-select form-select-sm">
                                    <option value="">Toutes</option>
                                    <option value="global" @selected(request('health_zone_id') === 'global')>Toutes les zones (générales)</option>
                                    @foreach ($zones as $zone)
                                        <option value="{{ $zone->id }}" @selected((string) request('health_zone_id') === (string) $zone->id)>{{ $zone->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="col-6 col-md-auto">
                            <label for="du" class="form-label small mb-0">Du</label>
                            <input id="du" type="date" name="du" value="{{ request('du') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-auto">
                            <label for="au" class="form-label small mb-0">Au</label>
                            <input id="au" type="date" name="au" value="{{ request('au') }}" class="form-control form-control-sm">
                        </div>
                        <div class="col-auto">
                            <input type="hidden" name="statut" value="{{ $status }}">
                            <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i><span class="visually-hidden">Filtrer</span></button>
                            <a href="{{ route('decisions.index') }}" class="btn btn-sm btn-light">Effacer</a>
                        </div>
                    </div>
                </div>
            </form>

            <ul class="nav nav-pills small mb-2">
                @foreach (['tous' => ['Toutes', $counts->sum()]] + collect(\App\Models\FieldDecision::STATUSES)->map(fn ($s, $k) => [$s[0], $counts[$k] ?? 0])->all() as $key => [$label, $n])
                    <li class="nav-item">
                        <a class="nav-link py-1 {{ $status === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['statut' => $key, 'page' => null]) }}">{{ $label }} <span class="badge {{ $status === $key ? 'text-bg-light' : 'text-bg-secondary' }}">{{ $n }}</span></a>
                    </li>
                @endforeach
            </ul>

            <div class="card">
                <div class="list-group list-group-flush">
                    @forelse ($decisions as $decision)
                        <a href="{{ route('decisions.show', $decision) }}" class="list-group-item list-group-item-action {{ $decision->status !== \App\Models\FieldDecision::STATUS_ACTIVE ? 'opacity-75' : '' }}">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span class="badge text-bg-{{ $decision->category?->color ?? 'secondary' }}">{{ $decision->category?->name }}</span>
                                    <span class="fw-semibold ms-1">{{ $decision->title }}</span>
                                </div>
                                <span class="small text-nowrap">
                                    @if ($decision->is_confidential)<span class="badge text-bg-dark me-1"><i class="bi bi-lock me-1"></i>Confidentielle</span>@endif
                                    <span class="badge text-bg-{{ $decision->statusColor() }}">{{ $decision->statusLabel() }}</span>
                                    <span class="text-muted ms-1">{{ $decision->code }} · {{ $decision->decided_on->format('d/m/Y') }}</span>
                                </span>
                            </div>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-geo-alt me-1"></i>{{ $decision->scopeLabel() }}
                                @if ($decision->decided_by) · <i class="bi bi-person me-1"></i>{{ $decision->decided_by }}@endif
                                @if ($decision->replacedBy) · remplacée par « {{ $decision->replacedBy->title }} »@endif
                            </div>
                            <div class="small mt-1">{{ \Illuminate\Support\Str::limit($decision->decision, 220) }}</div>
                        </a>
                    @empty
                        <div class="list-group-item text-muted py-4 text-center">Aucune décision enregistrée{{ request()->hasAny(['q', 'categorie', 'health_zone_id', 'du', 'au']) ? ' pour ces filtres' : '' }}.</div>
                    @endforelse
                </div>
                @if ($decisions->hasPages())
                    <div class="card-footer">{{ $decisions->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xl-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-tags me-1"></i>Catégories</span>
                    @can('manage decisions')
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#category-modal"><i class="bi bi-plus-lg"></i><span class="visually-hidden">Ajouter une catégorie</span></button>
                    @endcan
                </div>
                <ul class="list-group list-group-flush small">
                    @foreach ($categories as $category)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 {{ $category->is_active ? '' : 'text-muted' }}">
                            <a href="{{ route('decisions.index', ['categorie' => $category->id]) }}" class="text-decoration-none text-reset">
                                <span class="d-inline-block rounded-circle me-1 bg-{{ $category->color }}" style="width:10px;height:10px"></span>{{ $category->name }}
                                @unless ($category->is_active)<span class="badge text-bg-light border ms-1">désactivée</span>@endunless
                            </a>
                            <span class="d-flex align-items-center gap-2">
                                <span class="badge text-bg-secondary">{{ $category->decisions_count }}</span>
                                @can('manage decisions')
                                    <form method="POST" action="{{ route('decisions.categories.toggle', $category) }}"
                                          data-confirm="{{ $category->is_active ? 'Désactiver' : 'Réactiver' }} la catégorie « {{ $category->name }} » ? {{ $category->is_active ? 'Elle ne sera plus proposée pour les nouvelles décisions ; les décisions existantes la conservent.' : '' }}">
                                        @csrf
                                        <button class="btn btn-sm btn-link p-0 text-muted" title="{{ $category->is_active ? 'Désactiver' : 'Réactiver' }}"><i class="bi bi-{{ $category->is_active ? 'eye-slash' : 'eye' }}"></i></button>
                                    </form>
                                @endcan
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    @can('manage decisions')
        <div class="modal fade" id="category-modal" tabindex="-1" aria-labelledby="category-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <form method="POST" action="{{ route('decisions.categories.store') }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h6" id="category-modal-title">Nouvelle catégorie</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label for="category-name" class="form-label small">Nom</label>
                            <input id="category-name" name="name" maxlength="80" class="form-control form-control-sm" placeholder="Ex. : Décisions de sécurité" required>
                        </div>
                        <div>
                            <label for="category-color" class="form-label small">Couleur</label>
                            <select id="category-color" name="color" class="form-select form-select-sm">
                                @foreach (\App\Models\DecisionCategory::COLORS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
                        <button class="btn btn-primary btn-sm">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
</x-layouts.app>
