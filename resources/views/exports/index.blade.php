<x-layouts.app title="Exports des bases" page="exports">
    <x-page-header title="Exports des bases de données" subtitle="Réservé à l'administrateur. Chaque fichier est un ZIP avec un manifeste (empreinte SHA-256 et nombre de lignes de chaque fichier). Demandes et téléchargements tracés au journal d'audit ; fichiers supprimés après {{ \App\Models\DataExport::RETENTION_DAYS }} jours." />

    @if ($exports->getCollection()->contains(fn ($e) => in_array($e->status, ['en_attente', 'en_cours'], true)))
        <meta http-equiv="refresh" content="10">
    @endif

    <div class="row g-3 mb-3">
        @foreach ([
            \App\Models\DataExport::TYPE_RAW => ['database', 'Chaque soumission telle que reçue d\'ODK, y compris les soumissions exclues. Une table CSV par niveau : ménages, caregivers, enfants, autres repeats.'],
            \App\Models\DataExport::TYPE_CLEAN => ['database-check', 'Source + corrections validées, doublons exclus. Mêmes tables que la base brute, avec le registre des corrections.'],
            \App\Models\DataExport::TYPE_ARCHIVE => ['archive', 'Base brute, base nettoyée, registre des corrections, queries et historique, observations, dictionnaire : le paquet à archiver.'],
        ] as $type => [$icon, $text])
            <div class="col-md-4">
                <form method="POST" action="{{ route('exports.store') }}" class="card h-100">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <div class="card-body">
                        <h2 class="h6"><i class="bi bi-{{ $icon }} me-1"></i>{{ \App\Models\DataExport::TYPES[$type] }}</h2>
                        <p class="small text-muted mb-0">{{ $text }}</p>
                    </div>
                    <div class="card-footer bg-transparent"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-gear-wide-connected me-1"></i>Générer</button></div>
                </form>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('exports.store') }}" class="card mb-3 border-danger-subtle">
        @csrf
        <input type="hidden" name="type" value="{{ \App\Models\DataExport::TYPE_SQL }}">
        <div class="card-body">
            <h2 class="h6"><i class="bi bi-shield-lock me-1"></i>{{ \App\Models\DataExport::TYPES[\App\Models\DataExport::TYPE_SQL] }}</h2>
            <p class="small text-muted">Structure et données de toutes les tables (hors cache et sessions), dans un ZIP chiffré AES-256. Le mot de passe de chiffrement n'est pas conservé : notez-le et transmettez-le séparément du fichier.</p>
            @if ($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small mb-0" for="zip_password">Mot de passe de chiffrement (12 caractères min.)</label>
                    <input id="zip_password" type="password" name="zip_password" class="form-control form-control-sm" minlength="12" autocomplete="new-password" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-0" for="zip_password_confirmation">Confirmation</label>
                    <input id="zip_password_confirmation" type="password" name="zip_password_confirmation" class="form-control form-control-sm" minlength="12" autocomplete="new-password" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-0" for="account_password">Votre mot de passe (signature)</label>
                    <input id="account_password" type="password" name="account_password" class="form-control form-control-sm" autocomplete="current-password" required>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent text-end"><button class="btn btn-sm btn-danger"><i class="bi bi-lock me-1"></i>Générer la copie chiffrée</button></div>
    </form>

    <div class="card">
        <div class="card-header">Exports générés</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Réf.</th><th>Type</th><th>Demandé</th><th>Statut</th><th>Fichier</th><th>SHA-256 du ZIP</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                @forelse ($exports as $export)
                    <tr>
                        <td class="text-muted">EXP-{{ str_pad((string) $export->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $export->typeLabel() }}</td>
                        <td class="text-nowrap">{{ $export->requester?->name ?? '—' }}<div class="text-muted">{{ $export->created_at->format('d/m/Y H:i') }}</div></td>
                        <td>
                            <span class="badge text-bg-{{ $export->statusColor() }}">{{ $export->statusLabel() }}</span>
                            @if ($export->error)<div class="text-danger">{{ \Illuminate\Support\Str::limit($export->error, 120) }}</div>@endif
                            @if ($export->isMissing())<div class="text-danger">Fichier introuvable sur le serveur : relancez la génération.</div>@endif
                            @if ($export->isStuck())<div class="text-warning-emphasis">En attente depuis {{ $export->created_at->diffForHumans(null, true) }} : le traitement en arrière-plan (worker de la file) ne semble pas tourner.</div>@endif
                        </td>
                        <td>@if ($export->file_name){{ $export->file_name }}<div class="text-muted">{{ number_format(($export->file_size ?? 0) / 1048576, 1, ',', ' ') }} Mo · {{ collect($export->manifest['fichiers'] ?? [])->count() }} fichier(s) · {{ $export->download_count }} téléchargement(s)</div>@else — @endif</td>
                        <td><code class="small text-break">{{ $export->sha256 ?? '—' }}</code></td>
                        <td class="text-nowrap">{{ $export->expires_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-nowrap">@if ($export->isDownloadable())<a href="{{ route('exports.download', $export) }}" class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Télécharger</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun export.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($exports->hasPages())<div class="card-footer">{{ $exports->links() }}</div>@endif
    </div>
</x-layouts.app>
