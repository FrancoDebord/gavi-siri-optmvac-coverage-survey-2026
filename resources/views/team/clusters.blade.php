<x-layouts.app :title="'Grappes · binôme '.$team->code" page="team">
    @include('team._header')

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-bounding-box me-1"></i>Contours des grappes et ménages du binôme</span>
            <small class="text-muted fw-normal"><span class="text-success">●</span> ménages enquêtés · <span class="text-danger">●</span> autres ménages visités · <span style="color:#1B3A6B">━</span> grappe (bleu nuit) · <span style="color:#E8A33D">┅</span> sous-grappes (pointillés de couleur) · fond de carte au choix</small>
        </div>
        <div class="card-body">
            <div id="team-map" class="map-box" data-map='@json($mapData)'></div>
            @if (! $mapData['boundaries'])
                <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Aucun contour relevé n'a encore été importé pour les grappes de ce binôme : seuls les ménages sont affichés.</p>
            @endif
        </div>
    </div>

    <h2 class="h6 mt-2"><i class="bi bi-clipboard-data me-1"></i>Mes villages : grappes de la base de sondage, ménages attendus et visités</h2>
    <p class="small text-muted mb-2">Ménages attendus = ménages dénombrés ÷ subdivisions prévues (une subdivision tirée est enquêtée). Réalisation = ménages visités ÷ attendus. Cartes détaillées : bouton « Cartes des grappes (PDF) ».</p>
    @include('investigator._cluster-table', ['report' => $selection, 'withTeams' => false])

    <div class="card">
        <div class="card-header"><i class="bi bi-pin-map me-1"></i>Grappes où le binôme a travaillé</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Grappe</th><th>Village</th><th class="text-end">Ménages du binôme</th><th>Contour relevé</th><th class="d-none d-md-table-cell">Sous-grappes relevées</th></tr></thead>
                <tbody>
                @forelse ($clusters as $cluster)
                    @php($area = fn ($source) => number_format(collect($source->boundary['features'] ?? [])->sum('properties.superficie_ha'), 2, ',', ' '))
                    <tr>
                        <td><a href="{{ route('clusters.show', $cluster) }}" class="fw-semibold">{{ $cluster->code }}</a></td>
                        <td>{{ $cluster->village?->name ?? '—' }}</td>
                        <td class="text-end">{{ $cluster->team_households }}</td>
                        <td class="small">
                            @if ($cluster->boundary)
                                <i class="bi bi-check-circle text-success me-1"></i>{{ $cluster->boundary_date?->format('d/m/Y') ?? '—' }} · {{ $area($cluster) }} ha
                                @if (($hamlets = collect($cluster->boundary['features'] ?? [])->pluck('properties.hameau')->filter()->unique()->count()) > 0) · {{ $hamlets }} hameau(x)@endif
                                <div class="text-muted">{{ $cluster->boundary_file }}</div>
                            @else
                                <span class="text-muted">Non relevé</span>
                            @endif
                        </td>
                        <td class="small d-none d-md-table-cell">
                            @forelse ($cluster->subClusters->whereNotNull('boundary') as $sub)
                                <div>S{{ $sub->number }} · {{ $sub->boundary_date?->format('d/m/Y') ?? '—' }} · {{ $area($sub) }} ha</div>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">Aucune grappe pour le moment.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
