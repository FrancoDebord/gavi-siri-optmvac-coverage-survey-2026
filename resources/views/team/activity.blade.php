<x-layouts.app :title="'Rapport d\'activité · binôme '.$team->code" page="team">
    @include('team._header')

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-calendar3 me-1"></i>Rapport d'activité du binôme
                @if ($from)<span class="text-muted small">· du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}</span>@endif</span>
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                @foreach ($teamParam as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label for="from" class="small text-muted mb-0">Du</label>
                <input id="from" name="from" type="date" class="form-control form-control-sm w-auto" value="{{ $from?->toDateString() }}" min="{{ \App\Services\Dashboard\TeamActivityReport::collectionStart()->toDateString() }}">
                <label for="to" class="small text-muted mb-0">au</label>
                <input id="to" name="to" type="date" class="form-control form-control-sm w-auto @error('to') is-invalid @enderror" value="{{ $to?->toDateString() }}">
                <button class="btn btn-sm btn-outline-primary">Afficher</button>
                @if ($report)
                    <a href="{{ route('team.activity.pdf', $teamParam + $period) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                @endif
            </form>
        </div>
        @error('to')<div class="alert alert-danger m-3 mb-0">{{ $message }}</div>@enderror
        <div class="card-body p-0 table-responsive">
            @if ($report)
                @include('team-activity._table', ['report' => $report])
            @else
                <p class="text-muted text-center py-4 mb-0">Aucune donnée de collecte pour le moment.</p>
            @endif
        </div>
        <div class="card-footer small text-muted">
            Mères : caregivers interrogés · enfants éligibles : 12 à 35 mois · d'après la date d'entretien des ménages · semaines de 7 jours depuis le début de la collecte.
            Un jour sans données : « {{ \App\Services\Dashboard\TeamActivityReport::OFF_DAY }} ».
        </div>
    </div>
</x-layouts.app>
