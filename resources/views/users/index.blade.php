<x-layouts.app title="Utilisateurs">
    <x-page-header title="Utilisateurs" subtitle="Rôles et périmètres géographiques">
        <a href="{{ route('users.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Nouvel utilisateur</a>
    </x-page-header>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Périmètre</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->roleLabel() }}</td>
                        <td class="small">{{ $user->team ? $user->team->label() : ($user->commune ? 'Commune '.$user->commune->name : ($user->healthZone ? 'Zone '.$user->healthZone->name : 'Toutes les zones')) }}</td>
                        <td class="small">{{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td><span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                        <td class="text-end"><a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())<div class="card-footer">{{ $users->links() }}</div>@endif
    </div>
</x-layouts.app>
