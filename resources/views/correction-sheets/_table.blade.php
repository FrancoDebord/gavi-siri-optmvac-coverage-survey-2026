{{-- Lignes d'une fiche : $rows (CorrectionSheets::row), $columns. --}}
<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0 small">
        <thead class="table-light"><tr>@foreach ($columns as $column)<th class="text-nowrap">{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['n'] }}</td>
                <td><span class="badge {{ ['CRITIQUE' => 'text-bg-danger', 'MAJEURE' => 'text-bg-warning', 'OBSERVATION' => 'text-bg-secondary'][$row['severity']] ?? 'text-bg-light' }}">{{ $row['severity'] }}</span></td>
                <td class="text-nowrap">{{ $row['date'] }}</td>
                <td>{{ $row['village'] }}</td>
                <td class="text-nowrap"><a href="{{ $row['url'] }}">{{ $row['household'] }}</a></td>
                <td class="text-nowrap">{{ $row['person'] }}</td>
                <td>{{ $row['question'] }}</td>
                <td>{{ $row['value'] }}</td>
                <td>{{ $row['problem'] }}</td>
                <td>{{ $row['action'] }}</td>
                <td class="text-nowrap font-monospace">{{ $row['code'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
