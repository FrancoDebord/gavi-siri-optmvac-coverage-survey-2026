<x-layouts.app title="Registre des corrections" page="corrections">
    <x-page-header title="Registre des corrections" subtitle="Piste d'audit de la base nettoyée : chaque valeur remplacée est conservée, avec l'auteur, le motif, la source et la décision. Les données ODK ne sont jamais modifiées." />

    <div class="row g-2 mb-3">
        @foreach (\App\Models\DataCorrection::STATUSES as $key => $label)
            <div class="col-6 col-md">
                <a href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}" class="card card-body py-2 text-decoration-none h-100 {{ $filters['status'] === $key ? 'border-primary' : '' }}">
                    <div class="fs-5 fw-bold">{{ number_format($counts[$key] ?? 0, 0, ',', ' ') }}</div>
                    <div class="small text-muted">{{ $label }}</div>
                </a>
            </div>
        @endforeach
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="c-status">Statut</label>
                <select id="c-status" name="status" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    @foreach (\App\Models\DataCorrection::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="c-zone">Zone sanitaire</label>
                <select id="c-zone" name="health_zone_id" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected($filters['health_zone_id'] === $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="c-var">Variable</label>
                <input id="c-var" name="variable" value="{{ $filters['variable'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="c-du">Proposée du</label>
                <input id="c-du" type="date" name="du" value="{{ $filters['du'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="c-au">au</label>
                <input id="c-au" type="date" name="au" value="{{ $filters['au'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrer</button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                <tr><th>N°</th><th>Ménage · query</th><th>Variable · membre</th><th>ODK</th><th>Avant</th><th>Après</th><th>Motif · source</th><th>Proposée</th><th>Décision</th><th>Statut</th></tr>
                </thead>
                <tbody>
                @forelse ($corrections as $correction)
                    <tr>
                        <td class="text-muted">C-{{ str_pad((string) $correction->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>
                            @if ($correction->household)<a href="{{ route('households.show', $correction->household) }}">{{ $correction->household->household_code ?? $correction->submission_uuid }}</a>@else {{ $correction->submission_uuid }} @endif
                            <div class="text-muted">{{ $correction->household?->healthZone?->name }}@if ($correction->data_query_id) · <a href="{{ route('queries.show', $correction->data_query_id) }}">{{ \App\Models\DataQuery::codeFor($correction->data_query_id) }}</a>@endif</div>
                        </td>
                        <td><code>{{ $correction->variable }}</code>@if ($correction->instance_path)<div class="text-muted">{{ \App\Exports\QueriesExport::memberLabel($correction->instance_path) }}</div>@endif</td>
                        <td>{{ \App\Models\DataCorrection::display($correction->source_value) }}</td>
                        <td>{{ \App\Models\DataCorrection::display($correction->old_value) }}</td>
                        <td class="fw-semibold">{{ \App\Models\DataCorrection::display($correction->new_value) }}</td>
                        <td>{{ $correction->reasonLabel() }}@if ($correction->source_reference)<div class="text-muted">{{ $correction->source_reference }}</div>@endif @if ($correction->comment)<div class="text-muted fst-italic">{{ $correction->comment }}</div>@endif</td>
                        <td class="text-nowrap">{{ $correction->proposer?->name ?? 'Système' }}<div class="text-muted">{{ $correction->proposed_at->format('d/m/Y H:i') }}</div></td>
                        <td class="text-nowrap">{{ $correction->decider?->name ?? '—' }}@if ($correction->decided_at)<div class="text-muted">{{ $correction->decided_at->format('d/m/Y H:i') }}</div>@endif @if ($correction->signed)<span class="badge text-bg-success"><i class="bi bi-pen me-1"></i>signée</span>@endif</td>
                        <td><span class="badge text-bg-{{ \App\Models\DataCorrection::STATUS_COLORS[$correction->status] ?? 'secondary' }} {{ $correction->status === 'remplacee' ? 'border' : '' }}">{{ $correction->statusLabel() }}</span>@if ($correction->decision_comment)<div class="text-muted fst-italic">{{ $correction->decision_comment }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">Aucune correction.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($corrections->hasPages())<div class="card-footer">{{ $corrections->links() }}</div>@endif
    </div>
</x-layouts.app>
