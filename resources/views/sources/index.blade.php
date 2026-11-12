<x-layouts.app title="Sources de données" page="sources">
    <x-page-header title="Sources de données" subtitle="Connexions ODK Central et KoboToolbox, synchronisation automatique et journal">
        <a href="{{ route('sources.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle source</a>
    </x-page-header>

    <x-queue-warning />

    <div class="row g-3">
        @forelse ($sources as $source)
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi {{ $source->type === 'odk_central' ? 'bi-phone' : 'bi-box' }} me-1"></i>{{ $source->name }}</span>
                        <span>
                            <span class="badge text-bg-light border">{{ $source->typeLabel() }}</span>
                            <span class="badge {{ $source->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $source->is_active ? 'Active' : 'Inactive' }}</span>
                        </span>
                    </div>
                    <div class="card-body small">
                        <dl class="row mb-2">
                            <dt class="col-5">Serveur</dt><dd class="col-7 text-break">{{ $source->server_url }}</dd>
                            <dt class="col-5">Formulaire</dt><dd class="col-7">{{ $source->type === 'odk_central' ? 'Projet '.$source->project_id.' · '.$source->form_id : $source->asset_uid }}</dd>
                            <dt class="col-5">Fréquence</dt><dd class="col-7">toutes les {{ $source->sync_interval_minutes }} min</dd>
                            <dt class="col-5">Dernière synchro</dt>
                            <dd class="col-7">
                                {{ $source->last_synced_at?->format('d/m/Y H:i') ?? 'Jamais' }}
                                @if ($source->latestSyncLog)
                                    <span class="badge {{ $source->latestSyncLog->status === 'success' ? 'text-bg-success' : ($source->latestSyncLog->status === 'failed' ? 'text-bg-danger' : 'text-bg-primary') }}">{{ ['success' => 'succès', 'failed' => 'échec', 'running' => 'en cours'][$source->latestSyncLog->status] ?? $source->latestSyncLog->status }}</span>
                                @endif
                            </dd>
                        </dl>
                        <div id="test-result-{{ $source->id }}" class="mb-2"></div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-test-connection="{{ route('sources.test', $source) }}" data-target="#test-result-{{ $source->id }}"><i class="bi bi-plug me-1"></i>Tester la connexion</button>
                            <form method="POST" action="{{ route('sources.sync', $source) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Synchroniser maintenant</button>
                            </form>
                            <a href="{{ route('sources.show', $source) }}" class="btn btn-sm btn-light"><i class="bi bi-journal-text me-1"></i>Journal ({{ $source->sync_logs_count }})</a>
                            <a href="{{ route('sources.edit', $source) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil me-1"></i>Modifier</a>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info">Aucune source configurée. Ajoutez une source ODK Central ou KoboToolbox, ou importez des fichiers dans <a href="{{ route('imports.index') }}">Imports</a>.</div></div>
        @endforelse
    </div>
</x-layouts.app>
