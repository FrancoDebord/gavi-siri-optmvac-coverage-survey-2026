{{-- Colonnes d'indicateurs d'un binôme ou d'un agent ($row), après la colonne du nom. --}}
@php($fmt = fn ($n) => number_format((int) $n, 0, ',', ' '))
<td class="text-end">
    @if ($row->today)
        <span class="badge text-bg-success">{{ $row->today }}</span>
    @else
        <span class="badge text-bg-light border text-muted" title="Aucune visite reçue aujourd'hui">0</span>
    @endif
</td>
<td class="text-end">{{ $fmt($row->visited) }}</td>
<td class="text-end">{{ $fmt($row->surveyed) }}</td>
<td class="text-end d-none d-md-table-cell">{{ $fmt($row->no_children) }}</td>
<td class="text-end d-none d-md-table-cell">{{ $fmt($row->refused) }}</td>
<td class="text-end d-none d-lg-table-cell">{{ $row->days }}</td>
<td class="text-end">{{ $row->per_day !== null ? number_format($row->per_day, 1, ',', ' ') : '—' }}</td>
<td class="text-end d-none d-lg-table-cell">{{ $row->avg_duration !== null ? $row->avg_duration.' min' : '—' }}</td>
<td class="text-end">
    @if ($row->open_queries && $queriesHref)
        <a href="{{ $queriesHref }}" class="badge text-bg-danger text-decoration-none">{{ $row->open_queries }}</a>
    @elseif ($row->open_queries)
        <span class="badge text-bg-danger">{{ $row->open_queries }}</span>
    @else
        <span class="text-muted">0</span>
    @endif
</td>
<td class="small d-none d-md-table-cell">{{ $row->last_submission?->format('d/m H:i') ?? 'Aucun' }}</td>
