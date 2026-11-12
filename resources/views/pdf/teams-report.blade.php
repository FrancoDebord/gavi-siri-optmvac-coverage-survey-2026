@extends('pdf.layout', ['title' => 'Rapport des binômes'])

@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $byZone = $rows->groupBy(fn ($r) => $r['team']->healthZone?->name ?? '—');
@endphp

@section('content')
    <h1>{{ $type === 'binomes' ? 'Binômes – synthèse globale' : 'Binômes – point journalier détaillé' }}</h1>
    <div class="muted">{{ $scopeLabel }} · édité le {{ $today->format('d/m/Y') }} · enfants éligibles : 12 à 35 mois</div>

    @if ($type === 'binomes')
        @foreach ($byZone as $zone => $items)
            <h2>Zone sanitaire de {{ $zone }}</h2>
            <table>
                <thead>
                <tr><th>Binôme › agent</th><th class="num">Jours</th><th class="num">Villages</th><th class="num">Grappes</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Enfants éligibles</th><th class="num">Sans enfant</th><th class="num">Refus / absents</th><th class="num">Visites / jour</th><th class="num">Durée moy.</th><th class="num">Queries ouvertes</th><th>Dernier envoi</th></tr>
                </thead>
                <tbody>
                @foreach ($items as $row)
                    @php($k = $row['kpis'])
                    <tr class="lvl0">
                        <td>Binôme {{ $row['team']->code }} · {{ $row['team']->name }}</td>
                        <td class="num">{{ $k['days'] }}</td><td class="num">{{ $k['total']['villages'] }}</td><td class="num">{{ $k['total']['clusters'] }}</td>
                        <td class="num">{{ $fmt($k['total']['visited']) }}</td><td class="num">{{ $fmt($k['total']['surveyed']) }}</td><td class="num">{{ $fmt($k['total']['children']) }}</td>
                        <td class="num">{{ $fmt($k['total']['no_children']) }}</td><td class="num">{{ $fmt($k['total']['refused']) }}</td>
                        <td class="num">{{ $k['per_day'] !== null ? number_format($k['per_day'], 1, ',', ' ') : '—' }}</td>
                        <td class="num">{{ $k['total']['avg_duration'] !== null ? $k['total']['avg_duration'].' min' : '—' }}</td>
                        <td class="num">{{ $k['open_queries'] }}</td><td>{{ $k['last_submission']?->format('d/m H:i') ?? 'aucun' }}</td>
                    </tr>
                    @foreach ($row['agents'] as $agent)
                        <tr class="lvl1">
                            <td>{{ $agent['name'] }} ({{ $agent['code'] }})</td>
                            <td class="num">{{ $agent['days'] }}</td><td class="num">{{ $agent['villages'] }}</td><td class="num">{{ $agent['clusters'] }}</td>
                            <td class="num">{{ $fmt($agent['visited']) }}</td><td class="num">{{ $fmt($agent['surveyed']) }}</td><td class="num">{{ $fmt($agent['children']) }}</td>
                            <td class="num">{{ $fmt($agent['no_children']) }}</td><td class="num">{{ $fmt($agent['refused']) }}</td>
                            <td class="num">{{ $agent['days'] ? number_format($agent['visited'] / $agent['days'], 1, ',', ' ') : '—' }}</td>
                            <td class="num">{{ $agent['avg_duration'] !== null ? $agent['avg_duration'].' min' : '—' }}</td><td></td><td></td>
                        </tr>
                    @endforeach
                    @if ($row['places'])
                        <tr class="lvl1"><td colspan="13" class="muted">Villages › grappes : {{ collect($row['places'])->map(fn ($p) => $p['village'].' › '.($p['cluster'] ?? '—').' ('.$p['surveyed'].'/'.$p['visited'].')')->implode(' ; ') }}</td></tr>
                    @endif
                @endforeach
                <tr class="lvl0">
                    <td>Total {{ $zone }}</td><td></td><td></td><td></td>
                    <td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['total']['visited'])) }}</td>
                    <td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['total']['surveyed'])) }}</td>
                    <td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['total']['children'])) }}</td>
                    <td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['total']['no_children'])) }}</td>
                    <td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['total']['refused'])) }}</td>
                    <td></td><td></td><td class="num">{{ $fmt($items->sum(fn ($r) => $r['kpis']['open_queries'])) }}</td><td></td>
                </tr>
                </tbody>
            </table>
            <div class="muted">Entre parenthèses : ménages enquêtés / visités dans la grappe.</div>
        @endforeach
    @else
        @foreach ($rows as $row)
            @php($k = $row['kpis'])
            @if (! $loop->first)<div class="page-break"></div>@endif
            <h2>Binôme {{ $row['team']->code }} · {{ $row['team']->name }} ({{ $row['team']->healthZone?->name ?? '—' }})</h2>
            <div class="muted">Agents : {{ collect($row['agents'])->map(fn ($a) => $a['name'].' ('.$a['code'].') : '.$a['visited'].' visités, '.$a['surveyed'].' enquêtés')->implode(' · ') }}</div>
            <table class="kpis" style="margin-top:4px">
                <tr>
                    <td><div class="v">{{ $k['days'] }}</div>jours de collecte</td>
                    <td><div class="v">{{ $k['total']['villages'] }} / {{ $k['total']['clusters'] }}</div>villages / grappes</td>
                    <td><div class="v">{{ $fmt($k['total']['visited']) }}</div>ménages visités</td>
                    <td><div class="v">{{ $fmt($k['total']['surveyed']) }}</div>ménages enquêtés</td>
                    <td><div class="v">{{ $fmt($k['total']['children']) }}</div>enfants éligibles</td>
                    <td><div class="v">{{ $k['per_day'] !== null ? number_format($k['per_day'], 1, ',', ' ') : '—' }}</div>visites / jour</td>
                    <td><div class="v">{{ $k['open_queries'] }}</div>queries ouvertes</td>
                </tr>
            </table>
            <table>
                <thead>
                <tr><th>Date</th><th>Villages (grappes)</th><th class="num">Visités</th><th class="num">Enquêtés</th><th class="num">Enfants éligibles</th><th class="num">Sans enfant</th><th class="num">Refus / absents</th><th class="num">Durée moy.</th><th class="num">Queries (ouvertes)</th><th class="num">Cumul visités</th><th class="num">Cumul enquêtés</th><th class="num">Cumul enfants</th></tr>
                </thead>
                <tbody>
                @forelse ($row['daily'] as $day)
                    <tr>
                        <td>{{ $day['label'] }}</td><td>{{ $day['places'] }}</td>
                        <td class="num">{{ $day['visited'] }}</td><td class="num">{{ $day['surveyed'] }}</td><td class="num">{{ $day['children'] }}</td>
                        <td class="num">{{ $day['no_children'] }}</td><td class="num">{{ $day['refused'] }}</td>
                        <td class="num">{{ $day['avg_duration'] !== null ? $day['avg_duration'].' min' : '—' }}</td>
                        <td class="num">{{ $day['queries'] }} ({{ $day['open_queries'] }})</td>
                        <td class="num">{{ $day['cum_visited'] }}</td><td class="num">{{ $day['cum_surveyed'] }}</td><td class="num">{{ $day['cum_children'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="muted">Aucune donnée sur la période.</td></tr>
                @endforelse
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
