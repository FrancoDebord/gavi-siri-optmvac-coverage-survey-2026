<x-layouts.app :title="$source->name" page="sources">
    <x-page-header :title="$source->name" :subtitle="$source->typeLabel().' · '.$source->server_url">
        <a href="{{ route('sources.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Sources</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-test-connection="{{ route('sources.test', $source) }}" data-target="#test-result"><i class="bi bi-plug me-1"></i>Tester la connexion</button>
        <form method="POST" action="{{ route('sources.dictionary', $source) }}" data-confirm="Télécharger le formulaire depuis le serveur et l'activer comme data dictionary ?">
            @csrf
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-journal-arrow-down me-1"></i>Récupérer le dictionnaire</button>
        </form>
        <form method="POST" action="{{ route('sources.sync', $source) }}">
            @csrf
            <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Synchroniser maintenant</button>
        </form>
        <form method="POST" action="{{ route('sources.sync', $source) }}" data-confirm="Resynchroniser toutes les soumissions depuis le début ?">
            @csrf
            <input type="hidden" name="full" value="1">
            <button class="btn btn-sm btn-outline-primary">Synchro complète</button>
        </form>
        <a href="{{ route('sources.edit', $source) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
    </x-page-header>

    <x-queue-warning />

    <div id="test-result" class="mb-3"></div>

    <div class="card">
        <div class="card-header">Journal des synchronisations</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light"><tr><th>Début</th><th>Déclenchement</th><th>Statut</th><th class="text-end">Durée</th><th class="text-end">Récupérées</th><th class="text-end">Nouvelles</th><th class="text-end">Mises à jour</th><th>Erreur</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ $log->started_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $log->trigger === 'schedule' ? 'Planifiée' : 'Manuelle'.($log->user ? ' ('.$log->user->name.')' : '') }}</td>
                        <td><span class="badge {{ $log->status === 'success' ? 'text-bg-success' : ($log->status === 'failed' ? 'text-bg-danger' : 'text-bg-primary') }}">{{ ['success' => 'Succès', 'failed' => 'Échec', 'running' => 'En cours'][$log->status] ?? $log->status }}</span></td>
                        <td class="text-end">{{ $log->duration_ms !== null ? number_format($log->duration_ms / 1000, 1, ',', ' ').' s' : '—' }}</td>
                        <td class="text-end">{{ $log->fetched_count }}</td>
                        <td class="text-end">{{ $log->new_count }}</td>
                        <td class="text-end">{{ $log->updated_count }}</td>
                        <td class="text-danger">{{ \Illuminate\Support\Str::limit($log->error, 160) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">Aucune synchronisation.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="card-footer">{{ $logs->links() }}</div>
        @endif
    </div>
</x-layouts.app>
