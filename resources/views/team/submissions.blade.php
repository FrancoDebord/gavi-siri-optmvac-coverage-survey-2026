@php($fmt = fn ($n) => number_format((int) $n, 0, ',', ' '))
<x-layouts.app :title="'Soumissions · binôme '.$team->code" page="team">
    @include('team._header')

    <form method="GET" class="card mb-3">
        @foreach ($teamParam as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label for="q" class="form-label small mb-0">ID ménage</label>
                    <input id="q" type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Ex. TCH-SEB-01-003 ou Q-000123">
                </div>
                <div class="col-6 col-md-2">
                    <label for="date" class="form-label small mb-0">Jour</label>
                    <select id="date" name="date" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        @foreach ($dates as $date)
                            <option value="{{ $date }}" @selected(request('date') === $date)>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="cluster_id" class="form-label small mb-0">Grappe</label>
                    <select id="cluster_id" name="cluster_id" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($clusterOptions as $cluster)
                            <option value="{{ $cluster->id }}" @selected((int) request('cluster_id') === $cluster->id)>{{ $cluster->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="statut" class="form-label small mb-0">Statut</label>
                    <select id="statut" name="statut" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="enquetes" @selected(request('statut') === 'enquetes')>Ménages enquêtés</option>
                        <option value="non_enquetes" @selected(request('statut') === 'non_enquetes')>Non enquêtés (refus, absents, sans enfant…)</option>
                        <option value="avec_queries" @selected(request('statut') === 'avec_queries')>Avec des queries à corriger</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i><span class="visually-hidden">Filtrer</span></button>
                    <a href="{{ route('team.submissions', $teamParam) }}" class="btn btn-sm btn-light">Effacer</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-house-door me-1"></i>Soumissions du binôme</span>
            <small class="text-muted fw-normal">{{ $fmt($totals->n) }} ménage(s) au total, dont {{ $fmt($totals->surveyed) }} enquêté(s) · {{ $households->total() }} affiché(s) avec ces filtres</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Ménage</th><th>Date</th><th class="d-none d-md-table-cell">Village › grappe</th><th>Statut</th><th class="text-end">Enfants</th><th class="text-end d-none d-lg-table-cell">Durée</th><th class="d-none d-lg-table-cell">Agent</th><th class="text-center">GPS</th><th class="text-end">Queries</th></tr>
                </thead>
                <tbody>
                @forelse ($households as $household)
                    <tr>
                        <td><a href="{{ route('households.show', $household) }}" class="fw-semibold">{{ $household->household_code ?? '—' }}</a></td>
                        <td class="text-nowrap small">{{ $household->interview_date?->format('d/m/Y') ?? '—' }}<span class="d-none d-md-inline text-muted"> {{ $household->started_at?->format('H:i') }}</span></td>
                        <td class="small d-none d-md-table-cell">
                            {{ $household->village?->name ?? '—' }} ›
                            @if ($household->cluster)<a href="{{ route('clusters.show', $household->cluster) }}">{{ $household->cluster->code }}</a>@else — @endif
                            @if ($household->sub_cluster)<span class="text-muted"> · S{{ $household->sub_cluster }}</span>@endif
                        </td>
                        <td><span class="badge {{ $household->consented ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $statusLabel($household->survey_status) }}</span></td>
                        <td class="text-end">{{ $household->eligible_children }}</td>
                        <td class="text-end small d-none d-lg-table-cell">{{ $household->duration_minutes !== null ? $household->duration_minutes.' min' : '—' }}</td>
                        <td class="small d-none d-lg-table-cell">{{ $household->collector?->name }}</td>
                        <td class="text-center">{!! $household->latitude ? '<i class="bi bi-geo-alt-fill text-success" title="'.round($household->latitude, 5).', '.round($household->longitude, 5).'"></i>' : '<i class="bi bi-geo-alt text-danger" title="Pas de GPS"></i>' !!}</td>
                        <td class="text-end">{!! $household->open_queries ? '<span class="badge text-bg-danger">'.$household->open_queries.'</span>' : '<span class="text-muted">0</span>' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucune soumission{{ request()->hasAny(['q', 'date', 'statut', 'cluster_id']) ? ' pour ces filtres' : '' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($households->hasPages())
            <div class="card-footer">{{ $households->links() }}</div>
        @endif
    </div>
</x-layouts.app>
