<x-layouts.app :title="'Décision '.$decision->code">
    <x-page-header :title="$decision->title" :subtitle="$decision->code.' · '.($decision->category?->name ?? '—').' · décidée le '.$decision->decided_on->format('d/m/Y').($decision->decided_by ? ' par '.$decision->decided_by : '')">
        <a href="{{ route('decisions.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Registre</a>
        @can('manage decisions')
            @if ($decision->status === \App\Models\FieldDecision::STATUS_ACTIVE)
                <a href="{{ route('decisions.create', ['remplace' => $decision->id]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Remplacer par une nouvelle décision</a>
            @endif
            <a href="{{ route('decisions.edit', $decision) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifier</a>
            <form method="POST" action="{{ route('decisions.summary.toggle', $decision) }}" class="d-inline">
                @csrf
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-{{ $decision->in_summary ? 'eye-slash' : 'eye' }} me-1"></i>{{ $decision->in_summary ? 'Masquer du rapport de synthèse' : 'Afficher dans le rapport de synthèse' }}</button>
            </form>
        @endcan
        @role(\App\Models\User::ROLE_ADMIN)
            <form method="POST" action="{{ route('decisions.destroy', $decision) }}" class="d-inline"
                  onsubmit="return confirm(@js('Supprimer définitivement la décision '.$decision->code.' ?'.($decision->replaces->where('status', \App\Models\FieldDecision::STATUS_REPLACED)->isNotEmpty() ? ' Les décisions qu\'elle remplace redeviendront en vigueur.' : '').' Cette action est irréversible.'))">
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer</button>
            </form>
        @endrole
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="badge text-bg-{{ $decision->category?->color ?? 'secondary' }}">{{ $decision->category?->name }}</span>
                    <span>
                        @unless ($decision->in_summary)<span class="badge text-bg-light border me-1"><i class="bi bi-eye-slash me-1"></i>Hors rapport de synthèse</span>@endunless
                        <span class="badge text-bg-{{ $decision->statusColor() }}">{{ $decision->statusLabel() }}</span>
                    </span>
                </div>
                <div class="card-body">
                    @if ($decision->replacedBy)
                        <div class="alert alert-secondary small"><i class="bi bi-arrow-repeat me-1"></i>Remplacée par <a href="{{ route('decisions.show', $decision->replacedBy) }}">{{ $decision->replacedBy->code }} – {{ $decision->replacedBy->title }}</a>.</div>
                    @endif
                    @if ($decision->context)
                        <h2 class="h6 text-muted mb-1">Contexte / justification</h2>
                        <p style="white-space: pre-line">{{ $decision->context }}</p>
                    @endif
                    <h2 class="h6 text-muted mb-1">Décision</h2>
                    <p class="fs-6 mb-0" style="white-space: pre-line">{{ $decision->decision }}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-5">Code</dt><dd class="col-7">{{ $decision->code }}</dd>
                        <dt class="col-5">Date</dt><dd class="col-7">{{ $decision->decided_on->format('d/m/Y') }}</dd>
                        <dt class="col-5">Applicable dès</dt><dd class="col-7">{{ $decision->effective_from?->format('d/m/Y') ?? '—' }}</dd>
                        <dt class="col-5">Décidé par</dt><dd class="col-7">{{ $decision->decided_by ?? '—' }}</dd>
                        <dt class="col-5">Portée</dt><dd class="col-7">
                            {{ $decision->scopeLabel() }}
                            @if ($decision->cluster)<br><a href="{{ route('clusters.show', $decision->cluster) }}">Voir la grappe</a>@endif
                        </dd>
                        <dt class="col-5">Saisie par</dt><dd class="col-7">{{ $decision->author?->name ?? '—' }}<br><span class="text-muted">{{ $decision->created_at->format('d/m/Y H:i') }}</span></dd>
                        @if ($decision->updated_at->ne($decision->created_at))
                            <dt class="col-5">Modifiée le</dt><dd class="col-7">{{ $decision->updated_at->format('d/m/Y H:i') }}</dd>
                        @endif
                        @if ($decision->replaces->isNotEmpty())
                            <dt class="col-5">Remplace</dt><dd class="col-7">
                                @foreach ($decision->replaces as $old)
                                    <a href="{{ route('decisions.show', $old) }}">{{ $old->code }} – {{ $old->title }}</a><br>
                                @endforeach
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
