<x-layouts.app title="Gel de la base" page="locks">
    <x-page-header title="Gel de la base" subtitle="Le gel fige la base nettoyée : plus aucune correction ni exclusion, et les synchronisations n'écrivent plus dans la base (les changements d'ODK sont repris au dégel). Chaque gel ouvre une version numérotée, archivée par un paquet d'archivage." />

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100 {{ $current ? 'border-primary border-2' : '' }}">
                <div class="card-body">
                    @if ($current)
                        <div class="small text-muted text-uppercase fw-semibold">État</div>
                        <div class="fs-4 fw-bold"><i class="bi bi-lock-fill me-1 text-primary"></i>Gelée · version {{ $current->label() }}</div>
                        <div class="small text-muted">depuis le {{ $current->created_at->format('d/m/Y H:i') }} par {{ $current->user?->name ?? '—' }}</div>
                        <div class="small mt-2">{{ $current->reason }}</div>
                    @else
                        <div class="small text-muted text-uppercase fw-semibold">État</div>
                        <div class="fs-4 fw-bold"><i class="bi bi-unlock me-1"></i>Ouverte</div>
                        <div class="small text-muted">Corrections et synchronisations actives.</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <form method="POST" action="{{ route('locks.store') }}" class="card h-100" data-confirm="{{ $current ? 'Dégeler la base ? Les corrections et les synchronisations reprendront.' : 'Geler la base ? Aucune correction ne sera plus possible jusqu\'au dégel.' }}">
                @csrf
                <div class="card-body">
                    <h2 class="h6">{{ $current ? 'Dégeler la base' : 'Geler la base' }}</h2>
                    @unless ($current)
                        <ul class="small mb-2">
                            <li>{{ $pending['unresolved'] }} query(s) non résolue(s), {{ $pending['to_validate'] }} réponse(s) à valider</li>
                            <li>{{ $pending['proposed'] }} correction(s) proposée(s) non décidée(s), {{ $pending['conflicts'] }} en conflit avec la source</li>
                        </ul>
                    @endunless
                    @if ($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
                    <label class="form-label small mb-0" for="reason">Motif (obligatoire)</label>
                    <textarea id="reason" name="reason" rows="2" class="form-control form-control-sm mb-2" minlength="10" required placeholder="{{ $current ? 'ex. correction demandée par le comité après revue' : 'ex. fin de la collecte, base prête pour l\'analyse' }}">{{ old('reason') }}</textarea>
                    <label class="form-label small mb-0" for="password"><i class="bi bi-pen me-1"></i>Signature : votre mot de passe</label>
                    <input id="password" type="password" name="password" class="form-control form-control-sm" autocomplete="current-password" required>
                </div>
                <div class="card-footer bg-transparent text-end">
                    <button class="btn btn-sm {{ $current ? 'btn-outline-primary' : 'btn-primary' }}"><i class="bi bi-{{ $current ? 'unlock' : 'lock' }} me-1"></i>{{ $current ? 'Dégeler' : 'Geler la base' }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Registre des gels</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Version</th><th>Action</th><th>Date</th><th>Par</th><th>Motif</th><th>État de la base</th><th>Archive de la version</th></tr></thead>
                <tbody>
                @forelse ($locks as $lock)
                    <tr>
                        <td class="fw-semibold">{{ $lock->label() }}</td>
                        <td>@if ($lock->action === 'gel')<span class="badge text-bg-primary"><i class="bi bi-lock me-1"></i>Gel</span>@else<span class="badge text-bg-light border"><i class="bi bi-unlock me-1"></i>Dégel</span>@endif @if ($lock->signed)<span class="badge text-bg-success"><i class="bi bi-pen"></i></span>@endif</td>
                        <td class="text-nowrap">{{ $lock->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $lock->user?->name ?? '—' }}</td>
                        <td>{{ $lock->reason }}</td>
                        <td>@if ($lock->stats){{ $lock->stats['menages'] }} ménages · {{ $lock->stats['menages_exclus'] }} exclus · {{ $lock->stats['corrections_validees'] }} corrections · {{ $lock->stats['queries_non_resolues'] }} queries non résolues @else — @endif</td>
                        <td>
                            @if ($lock->export)
                                <span class="badge text-bg-{{ $lock->export->statusColor() }}">{{ $lock->export->statusLabel() }}</span>
                                @if ($lock->export->sha256)<div><code class="small text-break">{{ $lock->export->sha256 }}</code></div>@endif
                                @if ($lock->export->isDownloadable())<a href="{{ route('exports.download', $lock->export) }}" class="small"><i class="bi bi-download me-1"></i>{{ $lock->export->file_name }}</a>@endif
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">La base n'a jamais été gelée.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">L'archive d'une version reste identifiée par son empreinte SHA-256 même après la suppression du fichier (7 jours) : conservez-la hors de la plateforme.</div>
    </div>
</x-layouts.app>
