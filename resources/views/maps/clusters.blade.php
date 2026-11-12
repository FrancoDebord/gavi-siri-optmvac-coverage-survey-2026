@php
    $windowUrl = route('maps.clusters', array_filter(['village_id' => $village?->id, 'fenetre' => 1]));
    $go = fn ($v) => route('maps.clusters', array_filter(['village_id' => $v?->id, 'fenetre' => $window ? 1 : null]));
@endphp
<x-layouts.app :title="'Carte des grappes'.($village ? ' · '.$village->name : '')" page="cluster-map" :body-class="$window ? 'map-window' : null">
    <div class="map-window-hide">
        <x-page-header title="Carte des grappes et sous-grappes" :subtitle="'Choisissez un village pour voir toutes ses grappes et sous-grappes à la fois · '.$scopeLabel" />
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <a href="{{ $previous ? $go($previous) : '#' }}" class="btn btn-sm btn-outline-secondary {{ $previous ? '' : 'disabled' }}" title="Village précédent" aria-label="Village précédent"><i class="bi bi-chevron-left"></i></a>
        <form method="GET" class="d-flex align-items-center gap-2">
            @if ($window)<input type="hidden" name="fenetre" value="1">@endif
            <label for="village-select" class="small text-muted mb-0">Village</label>
            <select id="village-select" name="village_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()" @disabled($villages->isEmpty())>
                @foreach ($villages->groupBy(fn ($v) => $v->healthZone?->name ?? '—') as $zoneName => $items)
                    <optgroup label="{{ $zoneName }}">
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" @selected($item->id === $village?->id)>{{ $item->name }}{{ $item->has_boundary ? '' : ' (sans contour)' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </form>
        <a href="{{ $next ? $go($next) : '#' }}" class="btn btn-sm btn-outline-secondary {{ $next ? '' : 'disabled' }}" title="Village suivant" aria-label="Village suivant"><i class="bi bi-chevron-right"></i></a>
        <span class="small text-muted ms-1">
            {{ $clusters->count() }} grappe(s) · {{ $clusters->sum(fn ($c) => $c->subClusters->whereNotNull('boundary')->count()) }} sous-grappe(s) relevée(s) · {{ count($mapData['points']) }} ménage(s) géolocalisé(s)
        </span>
        <span class="ms-auto d-flex gap-2">
            @if ($window)
                <button type="button" class="btn btn-sm btn-light" onclick="window.close()"><i class="bi bi-x-lg me-1"></i>Fermer la fenêtre</button>
            @else
                <button type="button" class="btn btn-sm btn-outline-primary" data-open-window="{{ $windowUrl }}"><i class="bi bi-window-stack me-1"></i>Ouvrir dans une fenêtre</button>
            @endif
        </span>
    </div>

    <div class="card mb-3">
        <div class="card-body p-2">
            @if ($village)
                <div id="cluster-map-explorer" class="map-box map-explorer" data-map='@json($mapData)'></div>
                <p class="small text-muted mt-2 mb-0">
                    <span class="text-success">●</span> ménages recrutés · <span class="text-danger">●</span> non recrutés ·
                    <span style="color:#1B3A6B">━</span> grappe · <span style="color:#E8A33D">┅</span> sous-grappes (une couleur par numéro, voir la légende) ·
                    molette pour zoomer après un clic dans la carte · <i class="bi bi-arrows-fullscreen"></i> plein écran
                </p>
            @else
                <p class="text-muted mb-0 p-3">Aucun village à afficher pour le moment.</p>
            @endif
        </div>
    </div>

    @if ($village)
        <div class="card map-window-hide">
            <div class="card-header"><i class="bi bi-pin-map me-1"></i>Grappes du village de {{ $village->name }} ({{ $village->healthZone?->name ?? '—' }})</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Grappe</th><th class="text-end">Ménages</th><th>Contour de la grappe</th><th>Sous-grappes relevées</th></tr></thead>
                    <tbody>
                    @forelse ($clusters as $cluster)
                        @php($area = fn ($source) => number_format(collect($source->boundary['features'] ?? [])->sum('properties.superficie_ha'), 2, ',', ' '))
                        <tr>
                            <td><a href="{{ route('clusters.show', $cluster) }}" class="fw-semibold">{{ $cluster->code }}</a></td>
                            <td class="text-end">{{ $cluster->visible_households }}</td>
                            <td class="small">
                                @if ($cluster->boundary)
                                    <span class="d-inline-block me-1 align-middle" style="width:18px;border-top:3px solid #1B3A6B"></span>{{ $cluster->boundary_date?->format('d/m/Y') ?? '—' }} · {{ $area($cluster) }} ha
                                @else
                                    <span class="text-muted">Non relevé</span>
                                @endif
                            </td>
                            <td class="small">
                                @forelse ($cluster->subClusters->whereNotNull('boundary') as $sub)
                                    <span class="me-2 text-nowrap">S{{ $sub->number }} · {{ $area($sub) }} ha</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">Aucune grappe dans ce village.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
