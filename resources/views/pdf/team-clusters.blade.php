@extends('pdf.layout', ['title' => 'Grappes du binôme '.$team->code])

@section('header')
    Binôme {{ $team->code }}{{ $team->name ? ' · '.$team->name : '' }} — cartes des grappes<span class="date">{{ $generatedAt->format('d/m/Y H:i') }}</span>
@endsection

@section('content')
    <h1>Binôme {{ $team->code }}{{ $team->name ? ' · '.$team->name : '' }} — cartes des grappes</h1>
    <div class="muted">Zone sanitaire : {{ $team->healthZone?->name ?? '—' }} · agents : {{ $team->collectors->map(fn ($c) => $c->name)->implode(', ') }} · édité le {{ $generatedAt->format('d/m/Y à H:i') }}</div>
    <p>Une page par grappe, sur fond satellite. Points : ménages déjà visités (vert : enquêté, orange : visité non enquêté, cerclé de rouge : hors du contour). Les zones sans point à l'intérieur du contour restent à parcourir.</p>
    <table style="width: auto;">
        <thead><tr><th>Village</th><th class="num">Grappes</th></tr></thead>
        <tbody>
        @foreach ($villages as $village => $count)
            <tr><td>{{ $village }}</td><td class="num">{{ $count }}</td></tr>
        @endforeach
        </tbody>
    </table>

    @foreach ($pages as $page)
        <div class="page-break"></div>
        <h2 style="margin-top: 0;">{{ $page['village'] }} — grappe {{ $page['cluster']->number ?? '' }} ({{ $page['cluster']->code }})</h2>
        <div class="muted" style="margin-bottom: 4px;">{{ $page['visited'] }} ménage(s) visité(s), dont {{ $page['surveyed'] }} enquêté(s){{ $page['outside'] ? ' · '.$page['outside'].' hors du contour' : '' }}{{ $page['no_gps'] ? ' · '.$page['no_gps'].' sans GPS (non affiché(s))' : '' }}{{ $page['cluster']->boundary ? '' : ' · contour non encore relevé' }}</div>
        <img src="{{ $page['map'] }}" style="width: 100%;" alt="Carte de la grappe {{ $page['cluster']->code }}">
    @endforeach
@endsection
