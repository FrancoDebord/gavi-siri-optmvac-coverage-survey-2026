{{-- Villages restants groupés par zone : $remaining (RemainingVillages::remaining), $showTeams (colonne binômes). --}}
@php($columns = ($showTeams ?? true) ? 6 : 5)
<div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Village</th><th>Commune</th>@if ($showTeams ?? true)<th>Binôme(s) concerné(s)</th>@endif
            <th class="text-end">Nombre de grappes</th><th class="text-end">Sous-grappes prévues</th><th>Ménages dénombrés par grappe</th>
        </tr></thead>
        <tbody>
        @forelse ($remaining as $zoneName => $items)
            <tr class="table-light"><th colspan="{{ $columns }}">{{ $zoneName }} <span class="fw-normal text-muted small">· {{ $items->count() }} village(s) restant(s)</span></th></tr>
            @foreach ($items as $item)
                <tr>
                    <td class="fw-semibold"><i class="bi bi-hourglass-split text-warning me-1"></i>{{ $item['village'] }}</td>
                    <td class="small text-muted">{{ $item['commune'] ?? '—' }}</td>
                    @if ($showTeams ?? true)
                        <td class="small">{!! $item['teams'] ? e(implode(' · ', $item['teams'])) : '<span class="text-danger">aucun binôme attribué</span>' !!}</td>
                    @endif
                    <td class="text-end">{{ $item['clusters'] ?: '—' }}</td>
                    <td class="text-end">{{ $item['sub_clusters'] ?: '—' }}</td>
                    <td class="small">
                        @forelse ($item['per_cluster'] as $cluster)
                            <span class="badge text-bg-light border fw-normal me-1 mb-1">G{{ $cluster['number'] }} : {{ number_format($cluster['enumerated'], 0, ',', ' ') }}</span>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="{{ $columns }}" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i>Tous les villages ont au moins un ménage visité.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
