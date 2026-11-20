<x-layouts.app :title="'Villages restants · binôme '.$team->code" page="team">
    @include('team._header')

    <div class="row g-2 mb-3">
        <div class="col-4"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted">Mes villages</div><div class="fs-4 fw-semibold">{{ $summary['total'] }}</div></div></div></div>
        <div class="col-4"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted">Déjà visités</div><div class="fs-4 fw-semibold text-success">{{ $summary['visited'] }}</div></div></div></div>
        <div class="col-4"><div class="card h-100 border-warning-subtle"><div class="card-body py-2"><div class="small text-muted">Restants</div><div class="fs-4 fw-semibold text-warning-emphasis">{{ $summary['remaining'] }}</div></div></div></div>
    </div>

    @if ($summary['total'] === 0)
        <div class="alert alert-info">Aucune liste de villages n'est attribuée à ce binôme.</div>
    @else
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-hourglass-split me-1"></i>Villages où aucun ménage n'a encore été visité</div>
            @include('remaining-villages._table', ['remaining' => $remaining, 'showTeams' => false])
        </div>
        @if ($visitedVillages->isNotEmpty())
            <div class="card">
                <div class="card-header"><i class="bi bi-check2-circle me-1"></i>Villages déjà commencés</div>
                <div class="card-body d-flex flex-wrap gap-1">
                    @foreach ($visitedVillages as $village)
                        <span class="badge text-bg-light border fs-6 fw-normal">{{ $village['name'] }} <span class="text-success small">· {{ $village['visited'] }} ménage(s)</span></span>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-layouts.app>
