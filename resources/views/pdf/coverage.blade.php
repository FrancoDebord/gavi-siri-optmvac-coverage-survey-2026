@extends('pdf.layout', ['title' => $title])

{{-- Rapport PDF d'une page de couverture (CoverageReport) : indicateurs, barres carte / rappel, détail, tableaux par niveau. --}}
@php
    $rateColor = fn (?float $p) => match (true) {
        $p === null => '#706D6B',
        $p >= 90 => '#2E7D4F',
        $p >= 60 => '#010101',
        $p >= 30 => '#D98E04',
        default => '#C10202',
    };
    $pct = fn (?float $p) => \App\Services\Dashboard\CoverageReport::pct($p);
    // Cellule : texte, ou couverture colorée (avec « reçues / dénominateur » si fourni).
    $cell = function ($value) use ($rateColor, $pct) {
        if (! is_array($value)) {
            return e($value);
        }
        $color = ! empty($value['plain']) ? '#010101' : $rateColor($value['pct'] ?? null);
        $html = '<strong style="color:'.$color.'">'.e($pct($value['pct'] ?? null)).'</strong>';
        if (isset($value['denominator']) && $value['denominator'] !== null && isset($value['received'])) {
            $html .= '<br><span class="muted">'.number_format($value['received'], 0, ',', ' ').' / '.number_format($value['denominator'], 0, ',', ' ').'</span>';
        }

        return $html;
    };
@endphp

@section('header')
    {{ $title }} <span class="date">{{ now()->format('d/m/Y') }}</span>
@endsection

@section('content')
    <h1>{{ $title }}</h1>
    <div class="muted">Enquête de couverture vaccinale GAVI SIRI R2 · enfants enrôlés (tranche d'âge éligible), base nettoyée · estimations brutes, non pondérées</div>
    <div class="muted">Filtres : {{ $filters }}</div>

    <table class="kpis" style="margin-top:8px">
        <tr>
            @foreach ($kpis as [$label, $value])
                <td><div class="v">{{ $value }}</div>{{ $label }}</td>
            @endforeach
        </tr>
    </table>
    <p class="muted" style="margin:4px 0 0">{{ $definitions }}</p>

    <h2>{{ $bars['title'] }}</h2>
    <table style="border:0">
        @foreach ($bars['rows'] as [$label, $card, $recall, $total])
            <tr>
                <td style="border:0; width:16%; padding:2px 4px">{{ $label }}</td>
                <td style="border:0; padding:2px 0">
                    {{-- Barre : carte (vert) puis rappel seul (orange), sur 100 %. --}}
                    <table style="width:100%; border:0; margin:0"><tr>
                        @if (($card ?? 0) > 0)<td style="border:0; padding:0; height:11px; width:{{ $card }}%; background:#2E7D4F"></td>@endif
                        @if (($recall ?? 0) > 0)<td style="border:0; padding:0; height:11px; width:{{ $recall }}%; background:#D98E04"></td>@endif
                        @if (100 - ($card ?? 0) - ($recall ?? 0) > 0)<td style="border:0; padding:0; height:11px; background:#F1F0EF"></td>@endif
                    </tr></table>
                </td>
                <td style="border:0; width:9%; text-align:right; padding:2px 4px"><strong>{{ $pct($total) }}</strong></td>
            </tr>
        @endforeach
    </table>
    <div class="muted"><span style="color:#2E7D4F">■</span> carte · <span style="color:#D98E04">■</span> rappel seul · <span style="color:#B8B5B3">■</span> non reçue</div>

    @isset($distribution)
        <h2>{{ $distribution['title'] }}</h2>
        <table style="border:0">
            @foreach ($distribution['rows'] as [$label, $count, $share])
                <tr>
                    <td style="border:0; width:16%; padding:2px 4px">{{ $label }}</td>
                    <td style="border:0; padding:2px 0">
                        <table style="width:100%; border:0; margin:0"><tr>
                            @if (($share ?? 0) > 0)<td style="border:0; padding:0; height:11px; width:{{ $share }}%; background:{{ $loop->first ? '#C10202' : '#010101' }}"></td>@endif
                            @if (100 - ($share ?? 0) > 0)<td style="border:0; padding:0; height:11px; background:#F1F0EF"></td>@endif
                        </tr></table>
                    </td>
                    <td style="border:0; width:16%; text-align:right; padding:2px 4px">{{ number_format($count, 0, ',', ' ') }} enfants ({{ $pct($share) }})</td>
                </tr>
            @endforeach
        </table>
    @endisset

    <h2>Détail</h2>
    <table>
        <thead><tr>@foreach ($detail['headers'] as $i => $header)<th class="{{ $i > 0 ? 'num' : '' }}">{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($detail['rows'] as $row)
                <tr>@foreach ($row as $i => $value)<td class="{{ $i > 0 ? 'num' : '' }}">{!! $cell($value) !!}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>

    @foreach ($tables as $table)
        @if ($table['rows'])
            <h2 @if ($loop->first) style="page-break-before: always" @endif>Couverture {{ $table['title'] }}</h2>
            <table>
                <thead><tr>@foreach ($table['headers'] as $i => $header)<th class="{{ $i > 0 ? 'num' : '' }}">{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($table['rows'] as $row)
                        <tr @if ($row[0] === 'Ensemble') class="lvl0" @endif>@foreach ($row as $i => $value)<td class="{{ $i > 0 ? 'num' : '' }}">{!! $cell($value) !!}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
    <p class="muted">Couleur de la couverture : vert ≥ 90 %, noir ≥ 60 %, orange ≥ 30 %, rouge en dessous.</p>
@endsection
