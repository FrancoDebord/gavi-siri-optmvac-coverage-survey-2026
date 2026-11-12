@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $kpis['total'];
    $day = $kpis['today'];
    $zoneName = $zoneId ? $zones->firstWhere('id', $zoneId)?->name : null;
@endphp
<x-layouts.app title="Progression · Investigateur principal" page="team">
    <x-page-header title="Progression de la collecte" :subtitle="($zoneName ? 'Zone sanitaire de '.$zoneName : 'Toutes les zones sanitaires').' · situation au '.$today->format('d/m/Y').' · dernière soumission : '.($kpis['last_submission']?->format('d/m/Y H:i') ?? 'aucune')">
        <form method="GET" class="d-flex align-items-center gap-2">
            <label for="pi-zone" class="small text-muted mb-0">Zone</label>
            <select id="pi-zone" name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <option value="">Toutes</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
        </form>
    </x-page-header>

    @include('investigator._tabs')

    {{-- KPIs : aujourd'hui et depuis le début --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-kpi-card label="Villages visités" kpi="villages" icon="geo-alt" color="dark" :sub="'Aujourd\'hui : '.$fmt($day['villages']).' · '.$fmt($total['clusters']).' grappe(s)'">{{ $fmt($total['villages']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door" :sub="'Aujourd\'hui : '.$fmt($day['visited'])">{{ $fmt($total['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success" :sub="'Aujourd\'hui : '.$fmt($day['surveyed'])">{{ $fmt($total['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Enfants éligibles (12 à 35 mois)" kpi="children" icon="emoji-smile" color="warning" :sub="'Aujourd\'hui : '.$fmt($day['children'])">{{ $fmt($total['children']) }}</x-kpi-card></div>
    </div>
    <div class="row g-2 mb-3 text-center small">
        @foreach ([
            ['Jours de collecte', $fmt($kpis['days'])],
            ['Ménages visités / jour', $kpis['per_day'] !== null ? number_format($kpis['per_day'], 1, ',', ' ') : '—'],
            ['Durée moyenne d\'interview', $total['avg_duration'] !== null ? $total['avg_duration'].' min' : '—'],
            ['Sans enfant éligible', $fmt($total['no_children'])],
            ['Refus / absents', $fmt($total['refused'])],
            ['Queries à corriger', $fmt($kpis['open_queries'])],
        ] as [$label, $value])
            <div class="col-4 col-lg-2"><div class="card h-100"><div class="card-body py-2"><div class="fw-semibold fs-5">{{ $value }}</div><div class="text-muted">{{ $label }}</div></div></div></div>
        @endforeach
    </div>

    <div class="mb-3">
        <x-chart-card title="Progression journalière" canvas="chart-team-daily" subtitle="Ménages visités, enquêtés et enfants éligibles par jour ; courbe : ménages enquêtés en cumul." />
        <script type="application/json" id="team-daily">@json($daily)</script>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-calendar3 me-1"></i>Point journalier</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Date</th><th class="text-end">Binômes actifs</th><th class="d-none d-lg-table-cell">Villages (grappes)</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants</th><th class="text-end d-none d-md-table-cell">Sans enfant</th><th class="text-end d-none d-md-table-cell">Refus / abs.</th><th class="text-end d-none d-lg-table-cell">Durée moy.</th><th class="text-end">Queries</th><th class="text-end d-none d-md-table-cell">Cumul enquêtés</th></tr>
                </thead>
                <tbody>
                @forelse (array_reverse($daily) as $row)
                    <tr @class(['table-success' => $row['date'] === $today->toDateString()])>
                        <td class="text-nowrap">{{ $row['label'] }}</td>
                        <td class="text-end">{{ $row['teams'] }}</td>
                        <td class="small d-none d-lg-table-cell">{{ $row['places'] }}</td>
                        <td class="text-end">{{ $fmt($row['visited']) }}</td>
                        <td class="text-end">{{ $fmt($row['surveyed']) }}</td>
                        <td class="text-end">{{ $fmt($row['children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($row['no_children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($row['refused']) }}</td>
                        <td class="text-end d-none d-lg-table-cell">{{ $row['avg_duration'] !== null ? $row['avg_duration'].' min' : '—' }}</td>
                        <td class="text-end">{!! $row['open_queries'] ? '<span class="badge text-bg-danger">'.$row['open_queries'].'</span>' : '<span class="text-muted">'.$row['queries'].'</span>' !!}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($row['cum_surveyed']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted">Aucune donnée reçue pour le moment.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($byZone)
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-geo me-1"></i>Par zone sanitaire</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Zone</th><th class="text-end">Aujourd'hui (visités / enquêtés)</th><th class="text-end">Villages</th><th class="text-end">Grappes</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants éligibles</th><th class="text-end">Jours</th><th class="text-end">Queries</th></tr></thead>
                    <tbody>
                    @foreach ($byZone as $row)
                        <tr>
                            <td><a href="{{ route('investigator.progress', ['health_zone_id' => $row['zone']->id]) }}">{{ $row['zone']->name }}</a></td>
                            <td class="text-end">{{ $fmt($row['today']['visited']) }} / {{ $fmt($row['today']['surveyed']) }}</td>
                            <td class="text-end">{{ $row['total']['villages'] }}</td><td class="text-end">{{ $row['total']['clusters'] }}</td>
                            <td class="text-end">{{ $fmt($row['total']['visited']) }}</td><td class="text-end">{{ $fmt($row['total']['surveyed']) }}</td><td class="text-end">{{ $fmt($row['total']['children']) }}</td>
                            <td class="text-end">{{ $row['days'] }}</td><td class="text-end">{{ $fmt($row['open_queries']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people me-1"></i>Par binôme</span>
            <small class="text-muted fw-normal">Les plus actifs aujourd'hui en premier</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Binôme</th><th class="d-none d-md-table-cell">Zone</th><th class="text-end">Aujourd'hui</th><th class="text-end">Villages</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants</th><th class="text-end d-none d-md-table-cell">Jours</th><th class="text-end d-none d-md-table-cell">/ jour</th><th class="text-end">Queries</th><th class="d-none d-lg-table-cell">Dernier envoi</th></tr></thead>
                <tbody>
                @foreach ($byTeam as $row)
                    <tr @class(['text-muted' => ! $row['total']['visited']])>
                        <td>{{ $row['team']->label() }}</td>
                        <td class="small d-none d-md-table-cell">{{ $row['team']->healthZone?->name }}</td>
                        <td class="text-end">{!! $row['today']['visited'] ? '<span class="badge text-bg-success">'.$row['today']['visited'].'</span>' : '<span class="badge text-bg-light border text-muted">0</span>' !!}</td>
                        <td class="text-end">{{ $row['total']['villages'] }}</td>
                        <td class="text-end">{{ $fmt($row['total']['visited']) }}</td><td class="text-end">{{ $fmt($row['total']['surveyed']) }}</td><td class="text-end">{{ $fmt($row['total']['children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $row['days'] }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $row['per_day'] !== null ? number_format($row['per_day'], 1, ',', ' ') : '—' }}</td>
                        <td class="text-end">{{ $fmt($row['open_queries']) }}</td>
                        <td class="small d-none d-lg-table-cell">{{ $row['last_submission']?->format('d/m H:i') ?? 'aucun' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
