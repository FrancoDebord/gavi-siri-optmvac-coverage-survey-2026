@extends('pdf.layout', ['title' => 'Grappes par village'])

@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $report['totals'];
@endphp

@section('header')
    Grappes par village · {{ $scopeLabel }}<span class="date">Situation au {{ $generatedAt->format('d/m/Y à H:i') }}</span>
@endsection

@section('content')
    <h1>Grappes par village</h1>
    <div class="muted">{{ $scopeLabel }} · situation au {{ $generatedAt->format('d/m/Y à H:i') }} · enfants éligibles : 12 à 35 mois</div>

    <table class="kpis" style="margin-top: 8px;">
        <tr>
            <td><div class="v">{{ $fmt($total['villages']) }}</div>villages</td>
            <td><div class="v">{{ $fmt($total['frame_clusters']) }}</div>grappes (base de sondage)</td>
            <td><div class="v">{{ $fmt($total['expected']) }}</div>ménages attendus</td>
            <td><div class="v">{{ $fmt($total['visited']) }}</div>ménages visités</td>
            <td><div class="v">{{ $fmt($total['surveyed']) }}</div>ménages enquêtés</td>
            <td><div class="v">{{ $fmt($total['children']) }}</div>enfants éligibles</td>
        </tr>
    </table>

    @forelse ($report['zones'] as $zone)
        <h2>Zone sanitaire de {{ $zone['name'] }}</h2>
        <table>
            <thead>
            <tr>
                <th>Village</th><th class="num">N° grappe</th><th class="num">Dénombrés</th><th class="num">Subdiv. prévues</th><th class="num">Sous-grappes créées</th><th class="num">N° sous-grappe</th>
                <th class="num">Attendus</th><th class="num">Visités</th><th class="num">Réalisation</th><th class="num">Enquêtés</th><th class="num">Enfants 12–35 m</th><th>Binôme</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($zone['villages'] as $village)
                @foreach ($village['rows'] as $i => $row)
                    <tr>
                        @if ($i === 0)
                            <td rowspan="{{ count($village['rows']) }}"><strong>{{ $village['name'] }}</strong></td>
                        @endif
                        <td class="num">{{ ($row['cluster_numbers'] ? implode(', ', $row['cluster_numbers']) : '—').($row['cluster_id'] ? ' ('.$row['cluster_code'].')' : ($row['frame_only'] ? ' (non visitée)' : ' (non rattachée)')) }}</td>
                        <td class="num">{{ $row['enumerated'] !== null ? $fmt($row['enumerated']) : '—' }}</td>
                        <td class="num">{{ $row['subdivisions'] ?? '—' }}</td>
                        <td class="num">{{ $row['sub_clusters'] ? implode(', ', $row['sub_clusters']) : '—' }}</td>
                        <td class="num">{{ $row['selected'] ? implode(', ', $row['selected']) : '—' }}</td>
                        <td class="num">{{ $row['expected'] !== null ? $fmt($row['expected']) : '—' }}</td>
                        <td class="num">{{ $fmt($row['visited']) }}</td>
                        <td class="num">{{ $row['rate'] !== null ? $row['rate'].' %' : '—' }}</td>
                        <td class="num">{{ $fmt($row['surveyed']) }}</td>
                        <td class="num">{{ $fmt($row['children']) }}</td>
                        <td>{!! $row['teams'] ? collect($row['teams'])->map(fn ($t) => e($t))->implode('<br>') : '—' !!}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="lvl0">
                <td>Total {{ $zone['name'] }} ({{ $zone['totals']['villages'] }} village(s))</td>
                <td class="num">{{ $zone['totals']['frame_clusters'] }}</td>
                <td class="num">{{ $fmt($zone['totals']['enumerated']) }}</td>
                <td></td>
                <td class="num">{{ $zone['totals']['sub_clusters'] }}</td>
                <td></td>
                <td class="num">{{ $fmt($zone['totals']['expected']) }}</td>
                <td class="num">{{ $fmt($zone['totals']['visited']) }}</td>
                <td class="num">{{ $zone['totals']['expected'] ? round(100 * $zone['totals']['visited_framed'] / $zone['totals']['expected']).' %' : '—' }}</td>
                <td class="num">{{ $fmt($zone['totals']['surveyed']) }}</td>
                <td class="num">{{ $fmt($zone['totals']['children']) }}</td>
                <td></td>
            </tr>
            </tbody>
        </table>
    @empty
        <p class="muted">Aucune grappe sélectionnée ni aucun ménage visité pour l'instant.</p>
    @endforelse

    <div class="muted" style="margin-top: 6px;">
        N° grappe et N° sous-grappe sélectionnée : lus dans l'identifiant ménage (ZONE-VIL-NN-Sx-MMM) ; NA quand l'identifiant ne porte pas de sous-grappe. Sous-grappes créées : réponse 6a du formulaire. Plusieurs valeurs = identifiants ou réponses discordants entre soumissions.
        Ménage enquêté : consentement donné. Les grappes délimitées mais pas encore visitées apparaissent avec 0 ménage et sans réponse.
    </div>
@endsection
