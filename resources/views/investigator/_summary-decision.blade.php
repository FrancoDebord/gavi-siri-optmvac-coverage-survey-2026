{{-- Ligne d'une décision dans le rapport de synthèse (web), avec bouton masquer / réafficher. --}}
<tr>
    <td class="text-nowrap"><a href="{{ route('decisions.show', $decision) }}">{{ $decision->code }}</a></td>
    <td class="text-nowrap">{{ $decision->decided_on?->format('d/m/Y') }}</td>
    <td>{{ $decision->category?->name ?? '—' }}</td>
    <td class="small">{{ $decision->scopeLabel() }}</td>
    <td><div class="fw-semibold">{{ $decision->title }}</div><div class="small text-muted">{{ $decision->decision }}</div></td>
    <td class="small">{{ $decision->decided_by }}</td>
    <td><span class="badge text-bg-{{ $decision->statusColor() }}">{{ $decision->statusLabel() }}</span></td>
    @can('manage decisions')
        <td class="text-end">
            <form method="POST" action="{{ route('decisions.summary.toggle', $decision) }}">
                @csrf
                <button class="btn btn-sm btn-link p-0 {{ $decision->in_summary ? 'text-secondary' : '' }}" title="{{ $decision->in_summary ? 'Masquer du rapport de synthèse' : 'Réafficher dans le rapport de synthèse' }}"
                        aria-label="{{ ($decision->in_summary ? 'Masquer ' : 'Réafficher ').$decision->code }}">
                    <i class="bi bi-{{ $decision->in_summary ? 'eye-slash' : 'eye' }}"></i>{{ $decision->in_summary ? '' : ' Réafficher' }}
                </button>
            </form>
        </td>
    @endcan
</tr>
