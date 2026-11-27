{{--
    Tableau journalier d'un binôme (page web) : jours regroupés par semaine de collecte avec sous-total, total général ;
    jours sans données fusionnés sur les colonnes des indicateurs.
--}}
@php($fmt = fn ($n) => number_format((int) $n, 0, ',', ' '))
@php($measures = \App\Services\Dashboard\TeamActivityReport::MEASURES)
<table class="table table-sm table-bordered align-middle mb-0">
    <thead class="table-light">
        <tr>
            <th>Date</th>
            @foreach ($measures as $label)<th class="text-end">{{ $label }}</th>@endforeach
        </tr>
    </thead>
    @foreach ($report['weeks'] as $week)
        <tbody>
            <tr class="table-secondary">
                <th colspan="{{ count($measures) + 1 }}" class="small">Semaine {{ $week['number'] }} · du {{ $week['from']->format('d/m') }} au {{ $week['to']->format('d/m/Y') }}</th>
            </tr>
            @foreach ($week['days'] as $day)
                <tr>
                    <td class="text-nowrap">{{ ucfirst($day['date']->locale('fr')->isoFormat('ddd DD/MM/YYYY')) }}</td>
                    @if ($day['values'])
                        @foreach ($day['values'] as $value)<td class="text-end">{{ $fmt($value) }}</td>@endforeach
                    @else
                        <td colspan="{{ count($measures) }}" class="text-center text-muted fst-italic bg-body-tertiary">{{ \App\Services\Dashboard\TeamActivityReport::OFF_DAY }}</td>
                    @endif
                </tr>
            @endforeach
            <tr class="fw-semibold">
                <td>Sous-total semaine {{ $week['number'] }} <span class="fw-normal text-muted small">({{ $week['active_days'] }} j. de collecte)</span></td>
                @foreach ($week['totals'] as $value)<td class="text-end">{{ $fmt($value) }}</td>@endforeach
            </tr>
        </tbody>
    @endforeach
    <tfoot>
        <tr class="fw-bold table-dark">
            <td>Total général ({{ $report['active_days'] }} jour(s) de collecte)</td>
            @foreach ($report['totals'] as $value)<td class="text-end">{{ $fmt($value) }}</td>@endforeach
        </tr>
    </tfoot>
</table>
