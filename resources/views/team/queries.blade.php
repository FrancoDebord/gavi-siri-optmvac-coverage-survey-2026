<x-layouts.app :title="'Queries · binôme '.$team->code" page="team">
    @include('team._header')

    <div class="alert alert-info small d-flex gap-2">
        <i class="bi bi-lightbulb"></i>
        <div>Pour chaque query : vérifiez la valeur saisie sur le document (photo, carnet), puis ouvrez-la et cliquez <strong>Corriger</strong>.
            Saisissez la bonne valeur (la valeur lue sur la photo est proposée) ; si elle lève l'erreur, elle est prise en compte tout de suite.
            Si la valeur saisie est juste, choisissez <strong>Valeur confirmée</strong> et expliquez pourquoi. Ne modifiez rien dans ODK.</div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <ul class="nav nav-pills small">
            @foreach (\App\Models\DataQuery::RESOLUTIONS + ['toutes' => ['label' => 'Toutes']] as $key => $group)
                <li class="nav-item">
                    <a class="nav-link py-1 {{ $resolution === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['resolution' => $key, 'page' => null]) }}">
                        {{ $key === \App\Models\DataQuery::RESOLUTION_UNRESOLVED ? 'À corriger' : $group['label'] }}
                        <span class="badge {{ $resolution === $key ? 'text-bg-light' : 'text-bg-secondary' }}">{{ $key === 'toutes' ? $resolutionCounts->sum() : ($resolutionCounts[$key] ?? 0) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="d-flex flex-wrap gap-2">
            @foreach ($teamParam + ['resolution' => $resolution] as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <select name="severite" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Sévérité">
                <option value="">Toutes sévérités</option>
                @foreach ($severities as $key => $label)
                    <option value="{{ $key }}" @selected(request('severite') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm w-auto" placeholder="Ménage ou texte…" aria-label="Recherche">
        </form>
    </div>

    <div class="card">
        <div class="list-group list-group-flush">
            @forelse ($queries as $query)
                <a href="{{ route('queries.show', $query) }}" class="list-group-item list-group-item-action">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span>
                            <x-severity-badge :severity="$query->severity" />
                            <strong class="ms-1">{{ $query->code }}</strong>
                            <span class="text-muted">· ménage {{ $query->household_code ?? '—' }}{{ $query->cluster ? ' · '.$query->cluster->code : '' }}</span>
                        </span>
                        <span class="small text-nowrap"><x-status-badge :status="$query->status" /> <span class="text-muted ms-1">{{ $query->collection_date?->format('d/m/Y') }}</span></span>
                    </div>
                    <div class="mt-1 fw-semibold">{{ $query->message }}</div>
                    @include('queries._focus', ['focus' => $focus[$query->id], 'compact' => true])
                </a>
            @empty
                <div class="list-group-item text-muted py-4 text-center">
                    @if ($resolution === \App\Models\DataQuery::RESOLUTION_UNRESOLVED && ! request()->hasAny(['q', 'severite']))
                        <i class="bi bi-check-circle text-success me-1"></i>Aucune query à corriger. Bravo !
                    @else
                        Aucune query pour ces filtres.
                    @endif
                </div>
            @endforelse
        </div>
        @if ($queries->hasPages())
            <div class="card-footer">{{ $queries->links() }}</div>
        @endif
    </div>
</x-layouts.app>
