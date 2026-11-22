<x-layouts.app :title="'Queries · binôme '.$team->code" page="team">
    @include('team._header')

    @if ($tab === 'villages')
        <div class="card mb-3 border-warning-subtle">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="bi bi-signpost-split me-1"></i>Villages du binôme {{ $team->code }}</h2>
                @if ($teamVillages->isEmpty())
                    <p class="text-muted small mb-0">Aucune liste de villages n'est attribuée à ce binôme.</p>
                @else
                    @php($left = $teamVillages->filter(fn ($v) => ($villageVisits[$v->id] ?? 0) === 0))
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        @foreach ($teamVillages as $village)
                            @if (($villageVisits[$village->id] ?? 0) > 0)
                                <span class="badge text-bg-light border fs-6 fw-normal">{{ $village->name }} <span class="text-success small">· {{ $villageVisits[$village->id] }} ménage(s)</span></span>
                            @else
                                <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6 fw-normal"><i class="bi bi-hourglass-split me-1"></i>{{ $village->name }} <span class="small">· pas encore visité</span></span>
                            @endif
                        @endforeach
                    </div>
                    @if ($left->isNotEmpty())
                        <p class="small fw-semibold text-warning-emphasis mb-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $left->count() }} village(s) restant(s) : {{ $left->pluck('name')->implode(', ') }}.</p>
                    @endif
                @endif
                <p class="small text-muted mb-0">Village coché hors de cette liste, ou agent d'un autre binôme : ouvrez la query et cochez le bon village ou le bon agent.</p>
            </div>
        </div>
    @endif

    <x-query-progress :counts="$resolutionCounts" :title="'Progression de la résolution des erreurs par le binôme '.$team->code" />

    <div class="alert alert-info small d-flex gap-2">
        <i class="bi bi-lightbulb"></i>
        <div>Ouvrez la query et vérifiez sur le carnet ou la photo. Puis <strong>Corriger</strong> (bonne valeur) ou <strong>Valeur confirmée</strong> (si elle est juste, avec la source). Ne modifiez rien dans ODK.</div>
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
            @if ($ruleOptions->count() > 1 || request()->filled('regle'))
                <select name="regle" class="form-select form-select-sm w-auto" onchange="this.form.submit()" aria-label="Code de la règle">
                    <option value="">Tous les codes</option>
                    @foreach ($ruleOptions as $code => $name)
                        <option value="{{ $code }}" @selected(request('regle') === $code)>{{ $code }} – {{ \Illuminate\Support\Str::limit($name, 40) }}</option>
                    @endforeach
                </select>
            @endif
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm w-auto" placeholder="N° query, ménage ou texte…" aria-label="Recherche">
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
                            <x-rule-badge :code="$query->rule_code" class="ms-1" />
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
