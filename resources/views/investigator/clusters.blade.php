@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $report['totals'];
@endphp
<x-layouts.app title="Grappes par village · Investigateur principal" page="team">
    <x-page-header title="Grappes par village" :subtitle="$scopeLabel.' · situation au '.$generatedAt->format('d/m/Y à H:i')">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <label for="cl-zone" class="visually-hidden">Zone sanitaire</label>
            <select id="cl-zone" name="health_zone_id" class="form-select form-select-sm w-auto">
                <option value="">Toutes les zones</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Afficher</button>
            <a href="{{ route('investigator.clusters.pdf', array_filter(['health_zone_id' => $zoneId])) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF à imprimer</a>
            <a href="{{ route('investigator.clusters.xlsx', array_filter(['health_zone_id' => $zoneId])) }}" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        </form>
    </x-page-header>

    @include('investigator._tabs')

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl"><x-kpi-card label="Villages" kpi="villages" icon="geo-alt" color="dark">{{ $fmt($total['villages']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Grappes sélectionnées" kpi="clusters" icon="bounding-box" color="dark">{{ $fmt($total['clusters']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door">{{ $fmt($total['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success">{{ $fmt($total['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Enfants éligibles (12–35 mois)" kpi="children" icon="person-hearts" color="info">{{ $fmt($total['children']) }}</x-kpi-card></div>
    </div>

    @forelse ($report['zones'] as $zone)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                <span class="fw-semibold"><i class="bi bi-hospital me-1"></i>Zone sanitaire de {{ $zone['name'] }}</span>
                <span class="small text-muted">{{ $zone['totals']['villages'] }} village(s) · {{ $zone['totals']['clusters'] }} grappe(s) · {{ $fmt($zone['totals']['visited']) }} ménage(s) visité(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Village</th>
                        <th class="text-center">N° grappe sélectionnée</th>
                        <th class="text-center">Sous-grappes créées</th>
                        <th class="text-center">N° sous-grappe sélectionnée</th>
                        <th class="text-end">Ménages visités</th>
                        <th class="text-end">Ménages enquêtés</th>
                        <th class="text-end">Enfants éligibles (12–35 m)</th>
                        <th>Binôme</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($zone['villages'] as $village)
                        @foreach ($village['rows'] as $i => $row)
                            <tr>
                                @if ($i === 0)
                                    <td rowspan="{{ count($village['rows']) }}" class="fw-semibold">{{ $village['name'] }}</td>
                                @endif
                                <td class="text-center">
                                    @if ($row['cluster_id'])
                                        <a href="{{ route('clusters.show', $row['cluster_id']) }}" title="Grappe {{ $row['cluster_code'] }}">{{ $row['cluster_numbers'] ? implode(', ', $row['cluster_numbers']) : $row['cluster_code'] }}</a>
                                        <div class="small text-muted">{{ $row['cluster_code'] }}</div>
                                    @else
                                        {{ implode(', ', $row['cluster_numbers']) }}<div class="small text-muted">grappe non rattachée</div>
                                    @endif
                                </td>
                                <td class="text-center">{{ $row['sub_clusters'] ? implode(', ', $row['sub_clusters']) : '—' }}</td>
                                <td class="text-center">{{ $row['selected'] ? implode(', ', $row['selected']) : '—' }}</td>
                                <td class="text-end">{{ $fmt($row['visited']) }}</td>
                                <td class="text-end">{{ $fmt($row['surveyed']) }}</td>
                                <td class="text-end">{{ $fmt($row['children']) }}</td>
                                <td>{!! $row['teams'] ? collect($row['teams'])->map(fn ($t) => e($t))->implode('<br>') : '<span class="text-muted">—</span>' !!}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="table-secondary fw-semibold">
                        <td>Total {{ $zone['name'] }}</td>
                        <td class="text-center">{{ $zone['totals']['clusters'] }}</td>
                        <td class="text-center">{{ $zone['totals']['sub_clusters'] }}</td>
                        <td></td>
                        <td class="text-end">{{ $fmt($zone['totals']['visited']) }}</td>
                        <td class="text-end">{{ $fmt($zone['totals']['surveyed']) }}</td>
                        <td class="text-end">{{ $fmt($zone['totals']['children']) }}</td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @empty
        <div class="alert alert-light border">Aucune grappe sélectionnée ni aucun ménage visité pour l'instant.</div>
    @endforelse

    <p class="small text-muted">
        N° grappe et N° sous-grappe sélectionnée : lus dans l'identifiant ménage (ZONE-VIL-NN-Sx-MMM) ; NA quand l'identifiant ne porte pas de sous-grappe. Sous-grappes créées : réponse 6a du formulaire. Plusieurs valeurs = identifiants ou réponses discordants entre soumissions.
        Ménage enquêté : consentement donné. Les grappes délimitées mais pas encore visitées apparaissent avec 0 ménage et sans réponse.
    </p>
</x-layouts.app>
