<x-layouts.app title="Observations de supervision" page="observations">
    <x-page-header title="Observations de supervision" subtitle="Faits relevés sur les paradonnées (heures, durées, volumes, délais d'envoi, précision GPS) : à examiner avec les binômes. Ce ne sont pas des queries : aucune valeur n'est à corriger.">
        <a href="{{ route('queries.index') }}" class="btn btn-sm btn-light"><i class="bi bi-list-check me-1"></i>Queries de données</a>
    </x-page-header>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="o-zone">Zone sanitaire</label>
                <select id="o-zone" name="health_zone_id" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected($filters['health_zone_id'] === $zone->id)>{{ $zone->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="o-rule">Type d'observation</label>
                <select id="o-rule" name="rule_code" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    @foreach ($rules as $rule)
                        <option value="{{ $rule->code }}" @selected($filters['rule_code'] === $rule->code)>{{ $rule->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="o-du">Collecte du</label>
                <input id="o-du" type="date" name="du" value="{{ $filters['du'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="o-au">au</label>
                <input id="o-au" type="date" name="au" value="{{ $filters['au'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="o-actives">Afficher</label>
                <select id="o-actives" name="actives" class="form-select form-select-sm">
                    <option value="1" @selected($filters['actives'])>Toujours constatées</option>
                    <option value="0" @selected(! $filters['actives'])>Toutes</option>
                </select>
            </div>
            <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrer</button></div>
        </div>
    </form>

    <div class="row g-2 mb-3">
        @foreach ($rules as $rule)
            <div class="col-6 col-md-4 col-xl">
                <a href="{{ request()->fullUrlWithQuery(['rule_code' => $rule->code, 'page' => null]) }}" class="card card-body py-2 text-decoration-none h-100 {{ $filters['rule_code'] === $rule->code ? 'border-primary' : '' }}">
                    <div class="fs-5 fw-bold">{{ number_format($byRule[$rule->code] ?? 0, 0, ',', ' ') }}</div>
                    <div class="small text-muted">{{ $rule->name }}</div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Collecte</th><th>Observation</th><th>Valeur</th><th>Ménage</th><th>Zone</th><th>Village</th><th>Agent · binôme</th><th>État</th></tr>
                </thead>
                <tbody>
                @forelse ($observations as $observation)
                    <tr>
                        <td class="text-nowrap">{{ $observation->collection_date?->format('d/m/Y') ?? '—' }}</td>
                        <td><div class="fw-semibold small">{{ $observation->label ?? $observation->rule_code }}</div><div class="small text-muted">{{ $observation->message }}</div></td>
                        <td class="small">{{ $observation->observed_value ?? '—' }}</td>
                        <td>@if ($observation->household_id)<a href="{{ route('households.show', $observation->household_id) }}">{{ $observation->household_code ?? '—' }}</a>@else — @endif</td>
                        <td class="small">{{ $observation->healthZone?->name ?? '—' }}</td>
                        <td class="small">{{ $observation->village?->name ?? '—' }}</td>
                        <td class="small">{{ $observation->collector?->name ?? '—' }}{{ $observation->collector?->team ? ' · '.$observation->collector->team->code : '' }}</td>
                        <td>@if ($observation->auto_closed)<span class="badge text-bg-light border">disparue</span>@else<span class="badge text-bg-warning">constatée</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted text-center py-4">Aucune observation avec ces filtres.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($observations->hasPages())<div class="card-footer">{{ $observations->links() }}</div>@endif
    </div>
</x-layouts.app>
