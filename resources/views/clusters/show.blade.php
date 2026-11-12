@php
    $hasBoundary = $cluster->boundary || $cluster->subClusters->whereNotNull('boundary')->isNotEmpty();
    $place = collect([$cluster->village?->name, $cluster->village?->arrondissement?->name, $cluster->commune?->name, $cluster->healthZone?->name])->filter()->implode(' · ');
@endphp
<x-layouts.app :title="'Grappe '.$cluster->code" page="cluster">
    <x-page-header :title="'Grappe '.$cluster->code" :subtitle="$place">
        @can('view dashboards')
            <a href="{{ route('queries.index', ['cluster_id' => $cluster->id, 'status' => 'non_resolues']) }}" class="btn btn-sm btn-outline-danger"><i class="bi bi-flag me-1"></i>Queries de la grappe</a>
            <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
        @else
            <a href="{{ route('team.clusters') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Mes grappes</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-2"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door">{{ $totals['visited'] }}</x-kpi-card></div>
        <div class="col-6 col-lg-2"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success">{{ $totals['surveyed'] }}</x-kpi-card></div>
        <div class="col-6 col-lg-2"><x-kpi-card label="Sans enfant éligible" kpi="no_children" icon="person-dash" color="secondary">{{ $totals['no_children'] }}</x-kpi-card></div>
        <div class="col-6 col-lg-2"><x-kpi-card label="Refus" kpi="refused" icon="hand-thumbs-down" color="danger">{{ $totals['refused'] }}</x-kpi-card></div>
        <div class="col-6 col-lg-2"><x-kpi-card label="Absents / pas d'accès" kpi="absent" icon="door-closed" color="secondary">{{ $totals['absent'] }}</x-kpi-card></div>
        <div class="col-6 col-lg-2"><x-kpi-card label="Enfants éligibles" kpi="children" icon="emoji-smile" color="warning">{{ $totals['children'] }}</x-kpi-card></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header">Contour de la grappe et ménages visités</div>
                <div class="card-body">
                    <div id="cluster-map" class="map-box" data-map='@json($mapData)'></div>
                    <p class="small text-muted mt-2 mb-0">
                        <span class="text-success">●</span> ménages recrutés · <span class="text-danger">●</span> autres ménages visités · <span style="color:#1B3A6B">━</span> grappe (bleu nuit, trait plein) · <span style="color:#E8A33D">┅</span> sous-grappes (pointillés de couleur, voir la légende) · fond de carte au choix en haut à droite.
                        @foreach (collect([$cluster])->merge($cluster->subClusters)->filter(fn ($c) => $c->boundary_file) as $source)
                            <br><i class="bi bi-bounding-box me-1"></i>Contour {{ $source instanceof \App\Models\SubCluster ? 'de la sous-grappe '.$source->number : 'de la grappe' }} :
                            relevé du {{ $source->boundary_date?->format('d/m/Y') ?? '—' }} ({{ $source->boundary_file }}),
                            {{ number_format(collect($source->boundary['features'] ?? [])->sum('properties.superficie_ha'), 2, ',', ' ') }} ha
                            @if (($hamlets = collect($source->boundary['features'] ?? [])->pluck('properties.hameau')->filter()->unique()->count()) > 0) · {{ $hamlets }} hameau(x)@endif
                        @endforeach
                        @unless ($hasBoundary)
                            <br><i class="bi bi-info-circle me-1"></i>Le contour de cette grappe n'a pas encore été importé : seuls les ménages sont affichés.
                        @endunless
                    </p>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">Répartition par sous-grappe</div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light"><tr><th>Sous-grappe</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Sans enfant</th><th class="text-end">Enfants</th></tr></thead>
                        <tbody>
                        @forelse ($bySubCluster as $row)
                            <tr>
                                <td>{{ $row['number'] ?? 'Non renseignée' }}</td>
                                <td class="text-end">{{ $row['visited'] }}</td>
                                <td class="text-end">{{ $row['surveyed'] }}</td>
                                <td class="text-end">{{ $row['no_children'] }}</td>
                                <td class="text-end">{{ $row['children'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">Aucun ménage</td></tr>
                        @endforelse
                        </tbody>
                        @if (count($bySubCluster) > 1)
                            <tfoot class="fw-semibold"><tr><td>Total</td><td class="text-end">{{ $totals['visited'] }}</td><td class="text-end">{{ $totals['surveyed'] }}</td><td class="text-end">{{ $totals['no_children'] }}</td><td class="text-end">{{ $totals['children'] }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Ménages de la grappe ({{ $households->count() }})</div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Ménage</th><th>Sous-grappe</th><th>Date</th><th>Agent (binôme)</th><th>Statut</th><th class="text-end">Enfants éligibles</th><th class="text-end">Queries ouvertes</th><th>GPS</th></tr>
                </thead>
                <tbody>
                @forelse ($households as $household)
                    <tr>
                        <td><a href="{{ route('households.show', $household) }}">{{ $household->household_code ?? '—' }}</a></td>
                        <td>{{ $household->sub_cluster ?? '—' }}</td>
                        <td>{{ $household->interview_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $household->collector?->name ?? '—' }}@if ($household->collector?->team)<div class="small text-muted">Binôme {{ $household->collector->team->code }}</div>@endif</td>
                        <td><span class="badge {{ $household->consented ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $statusLabel($household->survey_status) }}</span></td>
                        <td class="text-end">{{ $household->eligible_children }}</td>
                        <td class="text-end">{!! $household->open_queries ? '<span class="badge text-bg-danger">'.$household->open_queries.'</span>' : '<span class="text-muted">0</span>' !!}</td>
                        <td>{!! $household->latitude ? '<i class="bi bi-geo-alt-fill text-success" title="'.round($household->latitude, 5).', '.round($household->longitude, 5).'"></i>' : '<i class="bi bi-geo-alt text-muted" title="Pas de GPS"></i>' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">Aucun ménage visité dans cette grappe</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
