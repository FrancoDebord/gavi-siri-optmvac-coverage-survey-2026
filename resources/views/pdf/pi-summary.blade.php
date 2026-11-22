@extends('pdf.layout', ['title' => 'Rapport de synthèse de la collecte'])

@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $cells = fn (array $k) => '<td class="num">'.$fmt($k['villages']).'</td><td class="num">'.$fmt($k['visited']).'</td><td class="num">'.$fmt($k['surveyed']).'</td><td class="num">'.$fmt($k['children']).'</td>';
    $head = '<th class="num" style="width:13%">Villages visités</th><th class="num" style="width:13%">Ménages visités</th><th class="num" style="width:13%">Ménages enquêtés</th><th class="num" style="width:13%">Enfants éligibles</th>';
    $total = $report['total'];
@endphp

@section('header')
    Rapport de synthèse · {{ $zoneLabel }}<span class="date">Situation au {{ $generatedAt->format('d/m/Y') }} à {{ $generatedAt->format('H:i') }}</span>
@endsection

@section('content')
    <h1>Rapport de synthèse de la collecte</h1>

    <table style="margin-top:6px; border:2px solid #C10202">
        <tr>
            <td style="width:34%; border:0; border-right:1px solid #DDDAD8; padding:6px 8px">
                <div class="muted" style="font-size:8px; text-transform:uppercase">Situation au</div>
                <div style="font-size:16px; font-weight:bold; color:#C10202">{{ $generatedAt->translatedFormat('j F Y') }}</div>
                <div class="muted">{{ ucfirst($generatedAt->translatedFormat('l')) }}, à {{ $generatedAt->format('H:i') }}</div>
            </td>
            <td style="width:33%; border:0; border-right:1px solid #DDDAD8; padding:6px 8px">
                <div class="muted" style="font-size:8px; text-transform:uppercase">Période couverte</div>
                <div style="font-weight:bold; font-size:10px">{{ $periodLabel }}</div>
                <div class="muted">{{ $zoneLabel }}</div>
            </td>
            <td style="width:33%; border:0; padding:6px 8px">
                <div class="muted" style="font-size:8px; text-transform:uppercase">Données collectées</div>
                @if ($firstDay)
                    <div style="font-weight:bold; font-size:10px">
                        {{ $firstDay->equalTo($lastDay) ? 'le '.$firstDay->format('d/m/Y') : 'du '.$firstDay->format('d/m/Y').' au '.$lastDay->format('d/m/Y') }}
                    </div>
                    <div class="muted">{{ $collectionDays }} jour{{ $collectionDays > 1 ? 's' : '' }} de collecte (jours de synchronisation des données)</div>
                @else
                    <div class="muted">Aucun ménage sur la période</div>
                @endif
            </td>
        </tr>
    </table>
    <div class="muted">Enfants éligibles : 12 à 35 mois.</div>

    <table class="kpis" style="margin-top:8px">
        <tr>
            <td><div class="v">{{ $fmt($total['villages']) }}</div>villages visités</td>
            <td><div class="v">{{ $fmt($total['visited']) }}</div>ménages visités</td>
            <td><div class="v">{{ $fmt($total['surveyed']) }}</div>ménages enquêtés</td>
            <td><div class="v">{{ $fmt($total['children']) }}</div>enfants éligibles (12–35 mois)</td>
        </tr>
    </table>

    <h2>1. Chiffres clés par zone sanitaire</h2>
    <table>
        <thead><tr><th>Zone sanitaire</th>{!! $head !!}</tr></thead>
        <tbody>
        @forelse ($report['zones'] as $zone)
            <tr><td>{{ $zone['name'] }}</td>{!! $cells($zone['kpis']) !!}</tr>
        @empty
            <tr><td colspan="5" class="muted">Aucun ménage sur la période.</td></tr>
        @endforelse
        @if (count($report['zones']) > 1)
            <tr class="lvl0"><td>Ensemble</td>{!! $cells($total) !!}</tr>
        @endif
        </tbody>
    </table>

    <h2>2. Chiffres clés par zone sanitaire et par binôme</h2>
    <table>
        <thead><tr><th>Zone sanitaire › binôme</th>{!! $head !!}</tr></thead>
        <tbody>
        @forelse ($report['zones'] as $zone)
            <tr class="lvl0"><td>{{ $zone['name'] }}</td>{!! $cells($zone['kpis']) !!}</tr>
            @foreach ($zone['teams'] as $team)
                <tr class="lvl1"><td>{{ $team['label'] }}</td>{!! $cells($team['kpis']) !!}</tr>
            @endforeach
        @empty
            <tr><td colspan="5" class="muted">Aucun ménage sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="page-break"></div>
    <h2>3. Chiffres clés par zone sanitaire et par village</h2>
    <table>
        <thead><tr><th>Zone sanitaire › village</th>{!! $head !!}</tr></thead>
        <tbody>
        @forelse ($report['zones'] as $zone)
            <tr class="lvl0"><td>{{ $zone['name'] }}</td>{!! $cells($zone['kpis']) !!}</tr>
            @foreach ($zone['villages'] as $village)
                <tr class="lvl1"><td>{{ $village['name'] }}</td>{!! $cells($village['kpis']) !!}</tr>
            @endforeach
        @empty
            <tr><td colspan="5" class="muted">Aucun ménage sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="page-break"></div>
    <h2>4. Chiffres clés par zone sanitaire, par binôme et par date de collecte</h2>
    <table>
        <thead><tr><th>Zone › binôme › date de collecte</th>{!! $head !!}</tr></thead>
        <tbody>
        @forelse ($report['zones'] as $zone)
            <tr class="lvl0"><td>{{ $zone['name'] }}</td>{!! $cells($zone['kpis']) !!}</tr>
            @foreach ($zone['teams'] as $team)
                <tr class="lvl1" style="font-weight:bold"><td>{{ $team['label'] }}</td>{!! $cells($team['kpis']) !!}</tr>
                @foreach ($team['days'] as $day)
                    <tr class="lvl2"><td>{{ $day['label'] }}</td>{!! $cells($day['kpis']) !!}</tr>
                @endforeach
            @endforeach
        @empty
            <tr><td colspan="5" class="muted">Aucun ménage sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>5. Décisions de collecte prises ({{ $decisions->count() }})</h2>
    <table>
        <thead><tr><th style="width:9%">Réf.</th><th style="width:9%">Date</th><th style="width:13%">Catégorie</th><th style="width:15%">Portée</th><th>Décision</th><th style="width:12%">Décidée par</th><th style="width:9%">Statut</th></tr></thead>
        <tbody>
        @forelse ($decisions as $decision)
            <tr>
                <td>{{ $decision->code }}</td>
                <td>{{ $decision->decided_on?->format('d/m/Y') }}</td>
                <td>{{ $decision->category?->name ?? '—' }}</td>
                <td>{{ $decision->scopeLabel() }}</td>
                <td><strong>{{ $decision->title }}</strong><br>{{ $decision->decision }}</td>
                <td>{{ $decision->decided_by }}</td>
                <td>{{ $decision->statusLabel() }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Aucune décision enregistrée sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="muted" style="margin-top:6px">Ménages enquêtés : consentement obtenu. Les villages sont comptés sans doublon à chaque niveau : un village visité par deux binômes compte une seule fois dans le total de la zone.</div>
@endsection
