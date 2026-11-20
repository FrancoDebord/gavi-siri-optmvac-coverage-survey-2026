@extends('pdf.layout', ['title' => 'Fiches de corrections'])

@section('header')
    Fiches de corrections — queries non résolues<span class="date">{{ $generatedAt->format('d/m/Y H:i') }}</span>
@endsection

@section('content')

    <h1>Fiches de corrections</h1>
    <div class="muted">Édité le {{ $generatedAt->format('d/m/Y à H:i') }} · queries non résolues · CRITIQUE : corriger sous 24 h · MAJEURE : corriger ou justifier sous 48 h.</div>

    <h2>1. Synthèse par zone sanitaire et par binôme</h2>
    <table>
        <thead><tr><th>Zone sanitaire</th><th>Commune</th><th>Binôme</th><th class="num">Ménages contrôlés</th><th class="num">Critiques</th><th class="num">Majeures</th><th class="num">Observations</th></tr></thead>
        <tbody>
        @foreach ($synthesis as $row)
            <tr><td>{{ $row['zone'] }}</td><td>{{ $row['commune'] }}</td><td>{{ $row['team'] }}</td><td class="num">{{ $row['households'] }}</td>
                <td class="num {{ $row['critique'] ? 'sev-critique' : '' }}">{{ $row['critique'] }}</td><td class="num">{{ $row['majeure'] }}</td><td class="num">{{ $row['observation'] }}</td></tr>
        @endforeach
        </tbody>
    </table>

    @foreach ($sheets as $sheet)
        <div class="page-break"></div>
        <h2>{{ $sheet['title'] }}</h2>
        <h3>2. Corrections ({{ count($sheet['corrections']) }})</h3>
        @if ($sheet['corrections'])
            @include('pdf._correction-rows', ['rows' => $sheet['corrections']])
        @else
            <div class="muted">Aucune correction critique ou majeure.</div>
        @endif
        @if ($sheet['observations'])
            <h3>3. Observations de supervision ({{ count($sheet['observations']) }}) — vérifiées par le superviseur, sans blocage</h3>
            @include('pdf._correction-rows', ['rows' => $sheet['observations']])
        @endif
    @endforeach

    <div class="page-break"></div>
    <h2>4. Avertissements</h2>
    <div class="muted">Aucun avertissement : AVT-01 n'est pas appliqué.</div>
    <h2>5. Règles non appliquées</h2>
    <table>
        <thead><tr><th>Code</th><th>Raison</th></tr></thead>
        <tbody>@foreach ($not_applied as $rule)<tr><td>{{ $rule['code'] }}</td><td>{{ $rule['reason'] }}</td></tr>@endforeach</tbody>
    </table>
@endsection
