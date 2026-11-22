@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $report['total'];
    $filters = array_filter(['health_zone_id' => $zoneId, 'du' => $from, 'au' => $to]);
    $head = '<th class="text-end">Villages visités</th><th class="text-end">Ménages visités</th><th class="text-end">Ménages enquêtés</th><th class="text-end">Enfants éligibles</th>';
@endphp
<x-layouts.app title="Rapport de synthèse · Investigateur principal" page="team">
    <x-page-header title="Rapport de synthèse" :subtitle="$scopeLabel.' · situation au '.$today->format('d/m/Y')">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <label for="sum-zone" class="visually-hidden">Zone sanitaire</label>
            <select id="sum-zone" name="health_zone_id" class="form-select form-select-sm w-auto">
                <option value="">Toutes les zones</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
            <label for="sum-from" class="small text-muted mb-0">Du</label>
            <input id="sum-from" type="date" name="du" value="{{ $from }}" class="form-control form-control-sm w-auto" max="{{ $today->toDateString() }}">
            <label for="sum-to" class="small text-muted mb-0">au</label>
            <input id="sum-to" type="date" name="au" value="{{ $to }}" class="form-control form-control-sm w-auto" max="{{ $today->toDateString() }}">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Afficher</button>
            <a href="{{ route('investigator.summary.pdf', $filters) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF à imprimer</a>
        </form>
    </x-page-header>

    @include('investigator._tabs')

    @error('au')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

    {{-- Date du rapport, bien visible --}}
    <div class="card mb-3 border-primary border-2 summary-date">
        <div class="card-body py-2">
            <div class="row g-3 align-items-center">
                <div class="col-md-4 d-flex align-items-center gap-3">
                    <i class="bi bi-calendar-check fs-1 text-primary"></i>
                    <div>
                        <div class="small text-muted text-uppercase fw-semibold">Situation au</div>
                        <div class="fs-4 fw-bold lh-sm">{{ $generatedAt->translatedFormat('j F Y') }}</div>
                        <div class="small text-muted">{{ ucfirst($generatedAt->translatedFormat('l')) }}, à {{ $generatedAt->format('H:i') }}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4">
                    <div class="small text-muted text-uppercase fw-semibold">Période couverte</div>
                    <div class="fw-semibold">{{ $periodLabel }}</div>
                    <div class="small text-muted">{{ $zoneLabel }}</div>
                </div>
                <div class="col-sm-6 col-md-4">
                    <div class="small text-muted text-uppercase fw-semibold">Données collectées</div>
                    @if ($firstDay)
                        <div class="fw-semibold">
                            @if ($firstDay->equalTo($lastDay))
                                le {{ $firstDay->format('d/m/Y') }}
                            @else
                                du {{ $firstDay->format('d/m/Y') }} au {{ $lastDay->format('d/m/Y') }}
                            @endif
                        </div>
                        <div class="small text-muted">{{ $collectionDays }} jour{{ $collectionDays > 1 ? 's' : '' }} de collecte (jours de synchronisation des données)</div>
                    @else
                        <div class="fw-semibold text-muted">Aucun ménage sur la période</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Chiffres clés d'ensemble --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-kpi-card label="Villages visités" kpi="villages" icon="geo-alt" color="dark">{{ $fmt($total['villages']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door">{{ $fmt($total['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success">{{ $fmt($total['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Enfants éligibles (12 à 35 mois)" kpi="children" icon="emoji-smile" color="warning">{{ $fmt($total['children']) }}</x-kpi-card></div>
    </div>

    {{-- 1. Par zone sanitaire --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-1-circle me-1"></i>Chiffres clés par zone sanitaire</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Zone sanitaire</th>{!! $head !!}</tr></thead>
                <tbody>
                @forelse ($report['zones'] as $zone)
                    <tr>
                        <td>{{ $zone['name'] }}</td>
                        @include('investigator._summary-cells', ['k' => $zone['kpis']])
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">Aucun ménage sur la période.</td></tr>
                @endforelse
                </tbody>
                @if (count($report['zones']) > 1)
                    <tfoot><tr class="table-secondary fw-semibold"><td>Ensemble</td>@include('investigator._summary-cells', ['k' => $total])</tr></tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- 2. Par zone et binôme --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-2-circle me-1"></i>Chiffres clés par zone sanitaire et par binôme</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Zone sanitaire › binôme</th>{!! $head !!}</tr></thead>
                <tbody>
                @forelse ($report['zones'] as $zone)
                    <tr class="table-secondary fw-semibold"><td>{{ $zone['name'] }}</td>@include('investigator._summary-cells', ['k' => $zone['kpis']])</tr>
                    @foreach ($zone['teams'] as $team)
                        <tr><td class="ps-4">{{ $team['label'] }}</td>@include('investigator._summary-cells', ['k' => $team['kpis']])</tr>
                    @endforeach
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">Aucun ménage sur la période.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3. Par zone et village --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-3-circle me-1"></i>Chiffres clés par zone sanitaire et par village</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Zone sanitaire › village</th>{!! $head !!}</tr></thead>
                <tbody>
                @forelse ($report['zones'] as $zone)
                    <tr class="table-secondary fw-semibold"><td>{{ $zone['name'] }}</td>@include('investigator._summary-cells', ['k' => $zone['kpis']])</tr>
                    @foreach ($zone['villages'] as $village)
                        <tr><td class="ps-4">{{ $village['name'] }}</td>@include('investigator._summary-cells', ['k' => $village['kpis']])</tr>
                    @endforeach
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">Aucun ménage sur la période.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 4. Par zone, binôme et date --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-4-circle me-1"></i>Chiffres clés par zone sanitaire, par binôme et par date de collecte</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Zone › binôme › date de collecte</th>{!! $head !!}</tr></thead>
                <tbody>
                @forelse ($report['zones'] as $zone)
                    <tr class="table-secondary fw-semibold"><td>{{ $zone['name'] }}</td>@include('investigator._summary-cells', ['k' => $zone['kpis']])</tr>
                    @foreach ($zone['teams'] as $team)
                        <tr class="fw-semibold"><td class="ps-3">{{ $team['label'] }}</td>@include('investigator._summary-cells', ['k' => $team['kpis']])</tr>
                        @foreach ($team['days'] as $day)
                            <tr class="small"><td class="ps-5 text-nowrap">{{ $day['label'] }}</td>@include('investigator._summary-cells', ['k' => $day['kpis']])</tr>
                        @endforeach
                    @endforeach
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">Aucun ménage sur la période.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 5. Décisions de collecte --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-5-circle me-1"></i>Décisions de collecte prises <span class="badge text-bg-light ms-1">{{ $decisions->count() }}</span></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Réf.</th><th>Date</th><th>Catégorie</th><th>Portée</th><th>Décision</th><th>Décidée par</th><th>Statut</th>@can('manage decisions')<th></th>@endcan</tr></thead>
                <tbody>
                @forelse ($decisions as $decision)
                    @include('investigator._summary-decision')
                @empty
                    <tr><td colspan="8" class="text-muted text-center py-3">Aucune décision à afficher sur la période.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($hiddenDecisions->isNotEmpty())
            <div class="card-footer small">
                <details>
                    <summary class="text-muted"><i class="bi bi-eye-slash me-1"></i>{{ $hiddenDecisions->count() }} décision(s) masquée(s), absente(s) du rapport et du PDF</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0 text-muted">
                            <tbody>
                            @foreach ($hiddenDecisions as $decision)
                                @include('investigator._summary-decision')
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        @endif
    </div>
    <p class="small text-muted">Ménages enquêtés : consentement obtenu. Enfants éligibles : 12 à 35 mois. Les villages sont comptés sans doublon à chaque niveau ; un village visité par deux binômes compte donc une seule fois dans le total de la zone.</p>
</x-layouts.app>
