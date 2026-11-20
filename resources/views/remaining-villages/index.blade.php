<x-layouts.app title="Villages restants">
    <x-page-header title="Villages restants" :subtitle="($scopeLabel ? $scopeLabel.' · ' : '').'Villages à couvrir où aucun ménage n\'a encore été visité, et binômes qui doivent y aller'" />

    @if ($showInvestigatorTabs)
        @include('investigator._tabs', ['zoneId' => $zoneId])
    @endif

    <div class="row g-2 mb-3">
        <div class="col-4"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted">Villages à couvrir</div><div class="fs-4 fw-semibold">{{ $summary['total'] }}</div></div></div></div>
        <div class="col-4"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted">Déjà visités</div><div class="fs-4 fw-semibold text-success">{{ $summary['visited'] }}</div></div></div></div>
        <div class="col-4"><div class="card h-100 border-warning-subtle"><div class="card-body py-2"><div class="small text-muted">Restants</div><div class="fs-4 fw-semibold text-warning-emphasis">{{ $summary['remaining'] }}</div></div></div></div>
    </div>

    @unless ($locked)
        <form method="GET" class="d-flex align-items-center gap-2 mb-3">
            <label class="small text-muted mb-0" for="rv-zone">Zone sanitaire</label>
            <select id="rv-zone" name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <option value="">Toutes</option>
                @foreach ($zones as $zone)<option value="{{ $zone->id }}" @selected($zoneId === $zone->id)>{{ $zone->name }}</option>@endforeach
            </select>
        </form>
    @endunless

    <div class="card">
        <div class="card-header"><i class="bi bi-hourglass-split me-1"></i>Villages sans ménage visité</div>
        @include('remaining-villages._table', ['remaining' => $remaining, 'showTeams' => true])
    </div>
</x-layouts.app>
