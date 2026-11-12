@php
    $tabs = ['en_attente' => 'À traiter', \App\Models\FieldIncident::STATUS_OPEN => 'Ouverts', \App\Models\FieldIncident::STATUS_IN_PROGRESS => 'En cours', \App\Models\FieldIncident::STATUS_RESOLVED => 'Résolus', 'tous' => 'Tous'];
    $tabCount = fn (string $key) => match ($key) {
        'en_attente' => ($counts[\App\Models\FieldIncident::STATUS_OPEN] ?? 0) + ($counts[\App\Models\FieldIncident::STATUS_IN_PROGRESS] ?? 0),
        'tous' => $counts->sum(),
        default => $counts[$key] ?? 0,
    };
@endphp
<x-layouts.app title="Incidents terrain">
    <x-page-header title="Incidents terrain" subtitle="Problèmes rencontrés sur le terrain (sécurité, accès, matériel, réseau…) et suivi de leur résolution">
        @can('report incidents')
            <a href="{{ route('incidents.create') }}" class="btn btn-sm btn-danger"><i class="bi bi-plus-lg me-1"></i>Signaler un incident</a>
        @endcan
    </x-page-header>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <ul class="nav nav-pills small">
            @foreach ($tabs as $key => $label)
                <li class="nav-item">
                    <a class="nav-link py-1 {{ $status === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['statut' => $key, 'page' => null]) }}">
                        {{ $label }} <span class="badge {{ $status === $key ? 'text-bg-light' : 'text-bg-secondary' }}">{{ $tabCount($key) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="d-flex flex-wrap gap-2">
            <input type="hidden" name="statut" value="{{ $status }}">
            @if ($zones->isNotEmpty())
                <select name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Zone sanitaire">
                    <option value="">Toutes les zones</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected((int) request('health_zone_id') === $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
            @endif
            <select name="categorie" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Type d'incident">
                <option value="">Tous les types</option>
                @foreach (\App\Models\FieldIncident::CATEGORIES as $key => [$label])
                    <option value="{{ $key }}" @selected(request('categorie') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Incident</th><th>Date</th><th class="d-none d-md-table-cell">Lieu</th><th>Gravité</th><th>Statut</th><th class="d-none d-lg-table-cell">Signalé par</th><th class="d-none d-lg-table-cell">Mis à jour</th></tr>
                </thead>
                <tbody>
                @forelse ($incidents as $incident)
                    <tr>
                        <td>
                            <a href="{{ route('incidents.show', $incident) }}" class="fw-semibold">{{ $incident->title }}</a>
                            <div class="small text-muted"><i class="bi bi-{{ $incident->categoryIcon() }} me-1"></i>{{ $incident->code }} · {{ $incident->categoryLabel() }}</div>
                        </td>
                        <td class="text-nowrap">{{ $incident->occurred_on->format('d/m/Y') }}</td>
                        <td class="small d-none d-md-table-cell">
                            {{ $incident->healthZone?->name }}
                            @if ($incident->village)<br>{{ $incident->village->name }}@endif
                            @if ($incident->cluster) · {{ $incident->cluster->code }}@endif
                            @if ($incident->collector)<br><i class="bi bi-person me-1"></i>{{ $incident->collector->name }}@endif
                        </td>
                        <td><span class="badge text-bg-{{ $incident->severityColor() }}">{{ $incident->severityLabel() }}</span></td>
                        <td><span class="badge text-bg-{{ $incident->statusColor() }}">{{ $incident->statusLabel() }}</span></td>
                        <td class="small d-none d-lg-table-cell">{{ $incident->reporter?->name ?? '—' }}</td>
                        <td class="small d-none d-lg-table-cell">{{ $incident->updated_at->format('d/m H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun incident{{ $status === 'en_attente' ? ' à traiter' : '' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($incidents->hasPages())
            <div class="card-footer">{{ $incidents->links() }}</div>
        @endif
    </div>
</x-layouts.app>
