@extends('pdf.layout', ['title' => 'Grappes du binôme '.$team->code])

@section('header')
    Binôme {{ $team->code }}{{ $team->name ? ' · '.$team->name : '' }} — cartes des grappes<span class="date">{{ $generatedAt->format('d/m/Y H:i') }}</span>
@endsection

@section('content')
    <h1>Binôme {{ $team->code }}{{ $team->name ? ' · '.$team->name : '' }}</h1>
    <div class="muted">Zone sanitaire : {{ $team->healthZone?->name ?? '—' }} · agents : {{ $team->collectors->map(fn ($c) => $c->name.' ('.$c->code.')')->implode(', ') }} · édité le {{ $generatedAt->format('d/m/Y à H:i') }}</div>

    <table class="kpis" style="margin-top: 8px;">
        <tr>
            <td><div class="v">{{ $totals['villages'] }}</div>village(s)</td>
            <td><div class="v">{{ $totals['clusters'] }}</div>grappe(s)</td>
            <td><div class="v">{{ $totals['households'] }}</div>ménages visités</td>
            <td><div class="v">{{ $totals['surveyed'] }}</div>ménages enquêtés</td>
            <td><div class="v">{{ $totals['gps'] }}</div>géolocalisés</td>
        </tr>
    </table>
    <p class="muted">Sur chaque carte : numéro d'ordre du ménage (identifiant ZONE-VIL-NN-MMM), point vert = enquêté, orange = visité non enquêté, cerclé de rouge = hors du contour de la grappe. Contour bleu = grappe, orange = sous-grappe.</p>

    @foreach ($sections as $village => $clusters)
        @foreach ($clusters as $item)
            <div class="page-break"></div>
            <h2>Village de {{ $village }} — grappe {{ $item['cluster']->number ?? '' }} ({{ $item['cluster']->code }})</h2>
            <img src="{{ $item['map'] }}" style="width: 100%; border: 1px solid #DDDAD8;" alt="Carte de la grappe {{ $item['cluster']->code }}">
            @if (! $item['cluster']->boundary)<div class="muted">Contour de la grappe non encore relevé : seuls les points sont affichés.</div>@endif
            <table style="margin-top: 6px;">
                <thead><tr><th>N°</th><th>ID ménage</th><th>Statut</th><th>Date</th><th class="num">Sous-grappe</th><th>GPS</th><th>Position</th></tr></thead>
                <tbody>
                @foreach ($item['households'] as $h)
                    <tr>
                        <td>{{ ($n = \App\Support\HouseholdCode::parse($h['code'])['order'] ?? null) !== null ? str_pad((string) $n, 3, '0', STR_PAD_LEFT) : '—' }}</td>
                        <td>{{ $h['code'] }}</td>
                        <td>{{ $h['status'] ?? '—' }}</td>
                        <td>{{ $h['date'] ?? '—' }}</td>
                        <td class="num">{{ $h['sub'] ?? '—' }}</td>
                        <td>{{ $h['gps'] ?? 'pas de GPS' }}</td>
                        <td>@if ($h['outside'] !== null)<strong style="color:#C10202">hors contour ({{ $h['outside'] }} m)</strong>@elseif ($h['gps'] && $item['cluster']->boundary) dans le contour @else — @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endforeach

    @if ($unclustered->isNotEmpty())
        <div class="page-break"></div>
        <h2>Ménages sans grappe reconnue (identifiant à vérifier)</h2>
        <table>
            <thead><tr><th>ID ménage</th><th>Date</th><th>GPS</th></tr></thead>
            <tbody>
            @foreach ($unclustered as $h)
                <tr><td>{{ $h->household_code ?? '—' }}</td><td>{{ $h->interview_date?->format('d/m/Y') ?? '—' }}</td><td>{{ $h->latitude !== null ? $h->latitude.', '.$h->longitude : 'pas de GPS' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
