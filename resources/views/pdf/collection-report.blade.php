@extends('pdf.layout', ['title' => 'État de la collecte'])

@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $sum = fn (string $key) => collect($sections)->sum(fn ($s) => $s['kpis'][$key]);
@endphp

@section('content')
    <h1>État de la collecte – enquête de couverture vaccinale GAVI SIRI R2</h1>
    <div class="muted">{{ $scopeLabel }} · situation au {{ $today->format('d/m/Y') }} (aujourd'hui = date d'interview du {{ $today->format('d/m/Y') }})</div>

    @if (count($sections) > 1)
        <h2>Vue d'ensemble</h2>
        <table>
            <thead>
            <tr><th>{{ $overviewLabel ?? 'Zone sanitaire' }}</th><th class="num">Visités</th><th class="num">dont aujourd'hui</th><th class="num">Enquêtés</th><th class="num">Sans enfant éligible</th><th class="num">Refus</th><th class="num">Absents</th><th class="num">Enfants éligibles</th><th class="num">Grappes</th><th class="num">Queries ouvertes</th><th class="num">Incidents ouverts</th></tr>
            </thead>
            <tbody>
            @foreach ($sections as $s)
                <tr>
                    <td>{{ $s['title'] }}</td>
                    <td class="num">{{ $fmt($s['kpis']['visited']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['visited_today']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['surveyed']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['no_children']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['refused']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['absent']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['children']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['clusters']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['open_queries']) }}</td>
                    <td class="num">{{ $fmt($s['kpis']['open_incidents']) }}</td>
                </tr>
            @endforeach
            <tr class="lvl0">
                <td>Total</td>
                @foreach (['visited', 'visited_today', 'surveyed', 'no_children', 'refused', 'absent', 'children', 'clusters', 'open_queries', 'open_incidents'] as $key)
                    <td class="num">{{ $fmt($sum($key)) }}</td>
                @endforeach
            </tr>
            </tbody>
        </table>
    @endif

    @foreach ($sections as $s)
        @if (count($sections) > 1 || ! $loop->first)<div class="page-break"></div>@endif
        <h2>{{ $s['title'] }}</h2>

        <table class="kpis">
            <tr>
                <td><div class="v">{{ $fmt($s['kpis']['visited']) }}</div>ménages visités<br><span class="muted">dont {{ $fmt($s['kpis']['visited_today']) }} aujourd'hui</span></td>
                <td><div class="v">{{ $fmt($s['kpis']['surveyed']) }}</div>ménages enquêtés<br><span class="muted">dont {{ $fmt($s['kpis']['surveyed_today']) }} aujourd'hui</span></td>
                <td><div class="v">{{ $fmt($s['kpis']['children']) }}</div>enfants éligibles</td>
                <td><div class="v">{{ $fmt($s['kpis']['no_children']) }} / {{ $fmt($s['kpis']['refused']) }} / {{ $fmt($s['kpis']['absent']) }}</div>sans enfant / refus / absents</td>
                <td><div class="v">{{ $fmt($s['kpis']['clusters']) }}</div>grappes visitées</td>
                <td><div class="v">{{ $fmt($s['kpis']['teams_today']) }}</div>binômes actifs aujourd'hui</td>
                <td><div class="v">{{ $fmt($s['kpis']['open_queries']) }}</div>queries ouvertes</td>
                <td><div class="v">{{ $fmt($s['kpis']['open_incidents']) }}</div>incidents ouverts</td>
            </tr>
        </table>
        <div class="muted">Dernière soumission reçue : {{ $s['kpis']['last_submission'] ?? 'aucune' }}</div>

        <table style="border:0">
            <tr>
                <td style="border:0; padding:0 6px 0 0; width:38%">
                    <h3>Évolution journalière (14 derniers jours de collecte)</h3>
                    <table>
                        <tr><th>Date</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Enquêtés cumulés</th></tr>
                        @forelse ($s['daily'] as $d)
                            <tr><td>{{ $d['date'] }}</td><td class="num">{{ $d['visited'] }}</td><td class="num">{{ $d['surveyed'] }}</td><td class="num">{{ $d['cum_surveyed'] }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="muted">Aucune donnée.</td></tr>
                        @endforelse
                    </table>
                </td>
                <td style="border:0; padding:0">
                    <h3>Queries</h3>
                    <table>
                        <tr>
                            <th class="num">Total</th><th class="num">Non résolues</th><th class="num">Critiques ouvertes</th><th class="num">Taux de résolution</th><th class="num">Pour 100 ménages</th>
                        </tr>
                        <tr>
                            <td class="num">{{ $fmt($s['queries']['total']) }}</td>
                            <td class="num">{{ $fmt($s['queries']['open']) }}</td>
                            <td class="num sev-critique">{{ $fmt($s['queries']['critical_open']) }}</td>
                            <td class="num">{{ $s['queries']['resolution_rate'] !== null ? number_format($s['queries']['resolution_rate'], 1, ',', ' ').' %' : '—' }}</td>
                            <td class="num">{{ $s['queries']['per_100_households'] ?? '—' }}</td>
                        </tr>
                    </table>
                    <table>
                        <tr><th>Règles les plus fréquentes</th><th class="num">Queries</th><th class="num">Non résolues</th></tr>
                        @forelse ($s['top_rules'] as $rule)
                            <tr><td>{{ $rule['name'] }}</td><td class="num">{{ $rule['n'] }}</td><td class="num">{{ $rule['open'] }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="muted">Aucune query.</td></tr>
                        @endforelse
                    </table>
                </td>
            </tr>
        </table>

        <h3>Villages et grappes</h3>
        <table>
            <tr><th>Village › grappe</th><th class="num">Sous-grappes</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Sans enfant</th><th class="num">Refus / absents</th><th class="num">Enfants</th><th class="num">Queries ouvertes</th><th>Dernière visite</th></tr>
            @forelse ($s['villages'] as $village)
                <tr class="lvl0">
                    <td>{{ $village->name }}</td><td></td>
                    <td class="num">{{ $village->totals['visited'] }}</td><td class="num">{{ $village->totals['surveyed'] }}</td>
                    <td class="num">{{ $village->totals['no_children'] }}</td><td class="num">{{ $village->totals['refused'] }}</td>
                    <td class="num">{{ $village->totals['children'] }}</td><td class="num">{{ $village->totals['open_queries'] }}</td><td></td>
                </tr>
                @foreach ($village->clusters as $cluster)
                    <tr class="lvl1">
                        <td>{{ $cluster->label }} ({{ $cluster->code }})</td><td class="num">{{ $cluster->sub_clusters ?: '—' }}</td>
                        <td class="num">{{ $cluster->visited }}</td><td class="num">{{ $cluster->surveyed }}</td>
                        <td class="num">{{ $cluster->no_children }}</td><td class="num">{{ $cluster->refused }}</td>
                        <td class="num">{{ $cluster->children }}</td><td class="num">{{ $cluster->open_queries }}</td>
                        <td>{{ $cluster->last_visit?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="9" class="muted">Aucune grappe visitée.</td></tr>
            @endforelse
        </table>

        <h3>Binômes</h3>
        <table>
            <tr><th>Binôme › agent</th><th class="num">Aujourd'hui</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Jours</th><th class="num">Visites / jour</th><th class="num">Durée moy.</th><th class="num">Queries ouvertes</th><th>Dernier envoi</th></tr>
            @forelse ($s['teams'] as $team)
                <tr class="lvl0">
                    <td>{{ $team->code ? 'Binôme '.$team->code.' · '.$team->name : $team->name }}</td>
                    <td class="num">{{ $team->today }}</td><td class="num">{{ $team->visited }}</td><td class="num">{{ $team->surveyed }}</td>
                    <td class="num">{{ $team->days }}</td><td class="num">{{ $team->per_day ?? '—' }}</td>
                    <td class="num">{{ $team->avg_duration !== null ? $team->avg_duration.' min' : '—' }}</td>
                    <td class="num">{{ $team->open_queries }}</td><td>{{ $team->last_submission?->format('d/m H:i') ?? 'aucun' }}</td>
                </tr>
                @foreach ($team->agents as $agent)
                    <tr class="lvl1">
                        <td>{{ $agent->name }} ({{ $agent->code }})</td>
                        <td class="num">{{ $agent->today }}</td><td class="num">{{ $agent->visited }}</td><td class="num">{{ $agent->surveyed }}</td>
                        <td class="num">{{ $agent->days }}</td><td class="num">{{ $agent->per_day ?? '—' }}</td>
                        <td class="num">{{ $agent->avg_duration !== null ? $agent->avg_duration.' min' : '—' }}</td>
                        <td class="num">{{ $agent->open_queries }}</td><td>{{ $agent->last_submission?->format('d/m H:i') ?? 'aucun' }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="9" class="muted">Aucun binôme.</td></tr>
            @endforelse
        </table>

        <h3>Incidents de terrain non résolus ({{ $s['incidents']->count() }})</h3>
        <table>
            <tr><th>Code</th><th>Date</th><th>Type</th><th>Gravité</th><th>Titre</th><th>Lieu</th><th>Statut</th></tr>
            @forelse ($s['incidents'] as $incident)
                <tr>
                    <td>{{ $incident->code }}</td><td>{{ $incident->occurred_on->format('d/m/Y') }}</td><td>{{ $incident->categoryLabel() }}</td>
                    <td>{{ $incident->severityLabel() }}</td><td>{{ $incident->title }}</td>
                    <td>{{ collect([$incident->village?->name, $incident->cluster?->code])->filter()->implode(' · ') ?: '—' }}</td>
                    <td>{{ $incident->statusLabel() }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Aucun incident en cours.</td></tr>
            @endforelse
        </table>
    @endforeach
@endsection
