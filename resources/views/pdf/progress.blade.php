@extends('pdf.layout', ['title' => 'Progression de la collecte'])

@section('content')
    <h1>Progression de la collecte – enquête de couverture vaccinale GAVI SIRI R2</h1>
    <div class="muted">
        Filtres :
        @forelse ($filters->values as $key => $value)
            {{ $key }} = {{ $value }}@if (! $loop->last), @endif
        @empty
            aucun
        @endforelse
    </div>

    <table class="kpis" style="margin-top:8px">
        <tr>
            <td><div class="v">{{ number_format($kpis['visited'], 0, ',', ' ') }}</div>ménages visités</td>
            <td><div class="v">{{ number_format($kpis['surveyed'], 0, ',', ' ') }}</div>ménages enquêtés</td>
            <td><div class="v">{{ number_format($kpis['caregivers'], 0, ',', ' ') }}</div>caregivers</td>
            <td><div class="v">{{ number_format($kpis['children'], 0, ',', ' ') }}</div>enfants enrôlés</td>
            <td><div class="v">{{ $kpis['rate_households'] !== null ? number_format($kpis['rate_households'], 1, ',', ' ').' %' : '—' }}</div>réalisation ménages</td>
            <td><div class="v">{{ $kpis['clusters']['completed'] }} / {{ $kpis['clusters']['started'] }} / {{ $kpis['clusters']['not_started'] }}</div>grappes terminées / démarrées / non démarrées</td>
        </tr>
    </table>

    <h2>Réalisation par niveau</h2>
    <table>
        <thead>
        <tr><th>Niveau</th><th>Libellé</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Caregivers</th><th class="num">Enfants</th><th class="num">Cible</th><th class="num">% réalisé</th><th class="num">Queries ouvertes</th></tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr class="lvl{{ $row['level'] }}">
                <td>{{ $levels[$row['level']] }}</td>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ $row['visited'] }}</td>
                <td class="num">{{ $row['households'] }}</td>
                <td class="num">{{ $row['caregivers'] }}</td>
                <td class="num">{{ $row['children'] }}</td>
                <td class="num">{{ $row['target'] ?: '—' }}</td>
                <td class="num">{{ $row['rate'] !== null ? number_format($row['rate'], 1, ',', ' ').' %' : '—' }}</td>
                <td class="num">{{ $row['queries'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
