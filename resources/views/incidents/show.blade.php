<x-layouts.app :title="'Incident '.$incident->code">
    <x-page-header :title="$incident->title" :subtitle="$incident->code.' · '.$incident->categoryLabel().' · signalé le '.$incident->created_at->format('d/m/Y H:i').' par '.($incident->reporter?->name ?? '—')">
        <a href="{{ route('incidents.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Incidents</a>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-{{ $incident->categoryIcon() }} me-1"></i>{{ $incident->categoryLabel() }}</span>
                    <span class="text-nowrap">
                        <span class="badge text-bg-{{ $incident->severityColor() }}">Gravité {{ mb_strtolower($incident->severityLabel()) }}</span>
                        <span class="badge text-bg-{{ $incident->statusColor() }}">{{ $incident->statusLabel() }}</span>
                    </span>
                </div>
                <div class="card-body">
                    <h2 class="h6 text-muted mb-1">Ce qui s'est passé</h2>
                    <p style="white-space: pre-line">{{ $incident->description }}</p>
                    @if ($incident->impact)
                        <div class="alert alert-warning small d-flex gap-2">
                            <i class="bi bi-exclamation-diamond"></i>
                            <div style="white-space: pre-line"><strong>Conséquence sur la collecte :</strong> {{ $incident->impact }}</div>
                        </div>
                    @endif
                    <dl class="row small mb-0">
                        <dt class="col-sm-4">Date de l'incident</dt><dd class="col-sm-8">{{ $incident->occurred_on->format('d/m/Y') }}</dd>
                        <dt class="col-sm-4">Zone / commune</dt><dd class="col-sm-8">{{ $incident->healthZone?->name ?? '—' }}{{ $incident->commune ? ' / '.$incident->commune->name : '' }}</dd>
                        <dt class="col-sm-4">Village</dt><dd class="col-sm-8">{{ $incident->village?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Grappe</dt><dd class="col-sm-8">
                            @if ($incident->cluster)<a href="{{ route('clusters.show', $incident->cluster) }}">{{ $incident->cluster->code }}</a>@else — @endif
                        </dd>
                        <dt class="col-sm-4">Agent / binôme</dt><dd class="col-sm-8">{{ $incident->collector?->name ?? '—' }}{{ $incident->collector?->team ? ' · '.$incident->collector->team->label() : '' }}</dd>
                        <dt class="col-sm-4">Résolu le</dt><dd class="col-sm-8">{{ $incident->resolved_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @can('update', $incident)
                <div class="card mb-3">
                    <div class="card-header">Suivi</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('incidents.update', $incident) }}" data-confirm-when="status:resolu" data-confirm-title="Clôturer l'incident" data-confirm-variant="success" data-confirm-label="Marquer comme résolu" data-confirm="Marquer l'incident « {{ $incident->title }} » comme résolu ? Le commentaire sera conservé comme mode de résolution.">
                            @csrf
                            <div class="mb-2">
                                <label for="status" class="form-label small">Nouveau statut</label>
                                <select id="status" name="status" class="form-select form-select-sm @error('status') is-invalid @enderror">
                                    <option value="">Inchangé ({{ $incident->statusLabel() }})</option>
                                    @foreach (\App\Models\FieldIncident::TRANSITIONS[$incident->status] ?? [] as $to)
                                        <option value="{{ $to }}" @selected(old('status') === $to)>{{ $to === \App\Models\FieldIncident::STATUS_OPEN ? 'Rouvrir' : \App\Models\FieldIncident::STATUSES[$to] }}</option>
                                    @endforeach
                                </select>
                                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-2">
                                <label for="comment" class="form-label small">Commentaire</label>
                                <textarea id="comment" name="comment" rows="3" maxlength="3000" class="form-control form-control-sm @error('comment') is-invalid @enderror"
                                          placeholder="Action menée, décision prise, prochaine étape…">{{ old('comment') }}</textarea>
                                @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Obligatoire pour marquer l'incident comme résolu : indiquez comment il a été réglé.</div>
                            </div>
                            <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer le suivi</button>
                        </form>
                    </div>
                </div>
            @endcan

            <div class="card">
                <div class="card-header">Historique</div>
                <ul class="list-group list-group-flush small">
                    @foreach ($incident->updates as $update)
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <strong>{{ $update->user?->name ?? 'Système' }}</strong>
                                <span class="text-muted">{{ $update->created_at->format('d/m/Y H:i') }}</span>
                            </div>
                            @if ($update->to_status)
                                <div>
                                    @if ($update->from_status)
                                        <span class="badge text-bg-{{ \App\Models\FieldIncident::STATUS_COLORS[$update->from_status] ?? 'secondary' }}">{{ \App\Models\FieldIncident::STATUSES[$update->from_status] ?? $update->from_status }}</span>
                                        <i class="bi bi-arrow-right"></i>
                                    @endif
                                    <span class="badge text-bg-{{ \App\Models\FieldIncident::STATUS_COLORS[$update->to_status] ?? 'secondary' }}">{{ \App\Models\FieldIncident::STATUSES[$update->to_status] ?? $update->to_status }}</span>
                                </div>
                            @endif
                            @if ($update->comment)<div class="mt-1" style="white-space: pre-line">{{ $update->comment }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
