{{-- Lignes d'une fiche de corrections (PDF) : $rows. --}}
<table>
    <thead><tr>@foreach (\App\Services\Dashboard\CorrectionSheets::COLUMNS as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            <td>{{ $row['n'] }}</td><td class="{{ $row['severity'] === 'CRITIQUE' ? 'sev-critique' : '' }}">{{ $row['severity'] }}</td><td>{{ $row['date'] }}</td>
            <td>{{ $row['village'] }}</td><td>{{ $row['household'] }}</td><td>{{ $row['person'] }}</td><td>{{ $row['question'] }}</td>
            <td>{{ $row['value'] }}</td><td>{{ $row['problem'] }}</td><td>{{ $row['action'] }}</td><td>{{ $row['code'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
