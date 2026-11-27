{{-- Tableau journalier d'un binôme (page web) : jours sans données fusionnés sur les colonnes des indicateurs. --}}
@php($fmt = fn ($n) => number_format((int) $n, 0, ',', ' '))
<table class="table table-sm table-bordered align-middle mb-0">
    <thead class="table-light">
        <tr>
            <th>Date</th>
            @foreach (\App\Services\Dashboard\TeamActivityReport::MEASURES as $label)<th class="text-end">{{ $label }}</th>@endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($report['days'] as $day)
            <tr>
                <td class="text-nowrap">{{ ucfirst($day['date']->locale('fr')->isoFormat('ddd DD/MM/YYYY')) }}</td>
                @if ($day['values'])
                    @foreach ($day['values'] as $value)<td class="text-end">{{ $fmt($value) }}</td>@endforeach
                @else
                    <td colspan="{{ count(\App\Services\Dashboard\TeamActivityReport::MEASURES) }}" class="text-center text-muted fst-italic bg-body-tertiary">{{ \App\Services\Dashboard\TeamActivityReport::OFF_DAY }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="fw-semibold table-light">
            <td>Total ({{ $report['active_days'] }} jour(s) de collecte)</td>
            @foreach ($report['totals'] as $value)<td class="text-end">{{ $fmt($value) }}</td>@endforeach
        </tr>
    </tfoot>
</table>
