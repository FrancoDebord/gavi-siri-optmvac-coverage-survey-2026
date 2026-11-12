@extends('pdf.layout', ['title' => 'Rapport quotidien des queries'])

@section('content')
    <h1>Rapport quotidien des queries – collecte du {{ $date->format('d/m/Y') }}</h1>
    <div class="muted">Enquête de couverture vaccinale GAVI SIRI – Round 2 · une section par zone sanitaire</div>

    @foreach ($sections as $section)
        <h2>Zone sanitaire {{ $section['zone']->name }}</h2>

        <table class="kpis">
            <tr>
                <td><div class="v">{{ $section['day']['visited'] }}</div>ménages visités le {{ $date->format('d/m') }}</td>
                <td><div class="v">{{ $section['day']['surveyed'] }}</div>enquêtés le {{ $date->format('d/m') }}</td>
                <td><div class="v">{{ $section['day']['queries']['total'] }}</div>queries du jour</td>
                <td><div class="v">{{ $section['day']['queries']['open'] }}</div>non résolues</td>
                <td><div class="v">{{ $section['cumul']['surveyed'] }}</div>enquêtés (cumul)</td>
                <td><div class="v">{{ $section['cumul']['queries']['open'] }}</div>non résolues (cumul)</td>
                <td><div class="v">{{ $section['cumul']['queries']['per_100_households'] ?? '—' }}</div>queries / 100 ménages (cumul)</td>
            </tr>
        </table>

        <table style="width:100%; border:0">
            <tr>
                <td style="width:38%; border:0; padding:0 6px 0 0">
                    <h3>Queries du jour par sévérité</h3>
                    <table>
                        <tr><th>Sévérité</th><th class="num">Non résolues</th><th class="num">Résolues</th></tr>
                        @foreach ($section['day']['by_severity'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['open'] }}</td><td class="num">{{ $row['resolved'] }}</td></tr>
                        @endforeach
                    </table>
                    <h3>Règles les plus fréquentes</h3>
                    <table>
                        <tr><th>Règle</th><th class="num">Queries</th></tr>
                        @forelse ($section['day']['top_rules'] as $rule)
                            <tr><td>{{ $rule['code'] }} – {{ $rule['name'] }}</td><td class="num">{{ $rule['n'] }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="muted">Aucune query.</td></tr>
                        @endforelse
                    </table>
                </td>
                <td style="border:0; padding:0">
                    <h3>Par binôme</h3>
                    <table>
                        <tr><th>Binôme</th><th class="num">Ménages</th><th class="num">Queries</th><th class="num">Non résolues</th><th class="num">Critiques</th><th class="num">/100 ménages</th></tr>
                        @forelse ($section['day']['teams'] as $row)
                            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['households'] }}</td><td class="num">{{ $row['total'] }}</td><td class="num">{{ $row['open'] }}</td><td class="num">{{ $row['critical'] }}</td><td class="num">{{ $row['rate'] ?? '—' }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="muted">Aucune query.</td></tr>
                        @endforelse
                    </table>
                </td>
            </tr>
        </table>

        <h3>Queries critiques non résolues ({{ $section['critical']->count() }})</h3>
        <table>
            <tr><th>Agent</th><th>Grappe</th><th>Ménage</th><th>Variable</th><th>Message</th><th>Valeur</th></tr>
            @forelse ($section['critical'] as $query)
                <tr>
                    <td>{{ $query->collector?->name }}</td>
                    <td>{{ $query->cluster?->code }}</td>
                    <td>{{ $query->household_code }}</td>
                    <td>{{ $query->variable }}</td>
                    <td class="sev-critique">{{ $query->message }}</td>
                    <td>{{ \Illuminate\Support\Str::limit((string) $query->observed_value, 40) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucune query critique en attente pour cette journée.</td></tr>
            @endforelse
        </table>

        @if (! $loop->last)<div class="page-break"></div>@endif
    @endforeach
@endsection
