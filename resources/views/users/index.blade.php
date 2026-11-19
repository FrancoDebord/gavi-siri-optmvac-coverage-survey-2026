<x-layouts.app title="Utilisateurs">
    <x-page-header title="Utilisateurs" subtitle="Rôles et périmètres géographiques">
        <form method="POST" action="{{ route('users.team-accounts') }}" data-confirm="Créer le compte des binômes qui n'en ont pas (identifiant : noms des deux agents, mot de passe : password) ?">
            @csrf
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-people me-1"></i>Créer les comptes binômes</button>
        </form>
        <a href="{{ route('users.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Nouvel utilisateur</a>
    </x-page-header>

    {{-- Filtre rapide : la liste se met à jour pendant la frappe (sans recharger la page). --}}
    <form method="GET" action="{{ route('users.index') }}" class="row g-2 align-items-end mb-3" data-live-filter="#users-results" role="search">
        <div class="col-md-6">
            <label class="form-label small mb-0" for="users-q">Rechercher</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="users-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Nom, identifiant, e-mail, binôme, zone, commune…" autocomplete="off" autofocus>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-0" for="users-role">Rôle</label>
            <select id="users-role" name="role" class="form-select form-select-sm">
                <option value="">Tous les rôles</option>
                @foreach (\App\Models\User::ROLES as $role => $label)
                    <option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-0" for="users-statut">Statut</label>
            <select id="users-statut" name="statut" class="form-select form-select-sm">
                <option value="">Tous</option>
                <option value="actif" @selected($filters['statut'] === 'actif')>Actifs</option>
                <option value="desactive" @selected($filters['statut'] === 'desactive')>Désactivés</option>
            </select>
        </div>
        <noscript><div class="col-12"><button class="btn btn-sm btn-primary">Filtrer</button></div></noscript>
    </form>

    <div class="card" id="users-results">
        <div class="card-header small text-muted">
            {{ $users->total() }} utilisateur(s){{ $users->total() !== $total ? ' sur '.$total : '' }}
            @if ($filters['q'] !== '' || $filters['role'] || $filters['statut'])
                · <a href="{{ route('users.index') }}">effacer les filtres</a>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Nom</th><th>Identifiant / e-mail</th><th>Rôle</th><th>Périmètre</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>@if ($user->username)<code>{{ $user->username }}</code><div class="small text-muted">{{ $user->email }}</div>@else{{ $user->email }}@endif</td>
                        <td>{{ $user->roleLabel() }}</td>
                        <td class="small">{{ $user->team ? $user->team->label() : ($user->commune ? 'Commune '.$user->commune->name : ($user->healthZone ? 'Zone '.$user->healthZone->name : 'Toutes les zones')) }}</td>
                        <td class="small">{{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td><span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                        <td class="text-end"><a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a></td>
                    </tr>
                @endforeach
                @if ($users->isEmpty())
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun utilisateur ne correspond à la recherche.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())<div class="card-footer">{{ $users->links() }}</div>@endif
    </div>
</x-layouts.app>
