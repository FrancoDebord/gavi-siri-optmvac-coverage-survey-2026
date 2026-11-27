@extends('pdf.layout', ['title' => 'Rapport d\'activité des binômes'])

@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $measures = \App\Services\Dashboard\TeamActivityReport::MEASURES;
@endphp

@section('header')Rapport d'activité des binômes · du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}@endsection

@section('content')
    <style>
        td.off { text-align: center; font-style: italic; color: #706D6B; background: #F1F0EF; }
        tr.total td { font-weight: bold; background: #F1F0EF; }
        .identity td { border: none; padding: 1px 0; }
        .signature { margin-top: 18px; }
        .signature td { border: none; width: 50%; padding-top: 4px; }
    </style>
    @foreach ($reports as $report)
        @php($team = $report['team'])
        <h1>Rapport d'activité · Binôme {{ $team->code }}</h1>
        <table class="identity">
            <tr><td><strong>Agents :</strong> {{ $team->collectors->map(fn ($a) => $a->name.' ('.$a->code.')')->implode(' et ') ?: '—' }}</td></tr>
            <tr><td><strong>Zone sanitaire :</strong> {{ $team->healthZone?->name ?? '—' }}</td></tr>
            <tr><td><strong>Période :</strong> du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} · {{ $report['active_days'] }} jour(s) de collecte</td></tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th style="width: 18%">Date</th>
                    @foreach ($measures as $label)<th class="num">{{ $label }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['days'] as $day)
                    <tr>
                        <td>{{ ucfirst($day['date']->locale('fr')->isoFormat('ddd DD/MM/YYYY')) }}</td>
                        @if ($day['values'])
                            @foreach ($day['values'] as $value)<td class="num">{{ $fmt($value) }}</td>@endforeach
                        @else
                            <td colspan="{{ count($measures) }}" class="off">{{ \App\Services\Dashboard\TeamActivityReport::OFF_DAY }}</td>
                        @endif
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Total</td>
                    @foreach ($report['totals'] as $value)<td class="num">{{ $fmt($value) }}</td>@endforeach
                </tr>
            </tbody>
        </table>
        <div class="muted">Mères : caregivers interrogés · enfants éligibles : 12 à 35 mois · d'après la date d'entretien des ménages · édité le {{ $generatedAt->format('d/m/Y à H:i') }}</div>

        <table class="signature">
            <tr><td>Signature des agents :</td><td>Visa du superviseur :</td></tr>
        </table>
        @unless ($loop->last)<div class="page-break"></div>@endunless
    @endforeach
@endsection
