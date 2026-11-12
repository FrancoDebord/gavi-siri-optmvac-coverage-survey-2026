{{-- En-tête commun de l'espace binôme : titre, choix du binôme (équipe centrale, superviseurs) et onglets. --}}
<x-page-header :title="'Binôme '.$team->code.' · '.$team->name"
               :subtitle="($team->healthZone?->name ?? '—').' · '.$team->collectors->map(fn ($a) => $a->name.' ('.$a->code.')')->implode(' et ').' · situation au '.$today->format('d/m/Y')">
    @unless ($isTeam)
        <form method="GET" action="{{ url()->current() }}" class="d-flex align-items-center gap-2">
            <label for="team-select" class="small text-muted mb-0">Binôme</label>
            <select id="team-select" name="team_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                @foreach ($teams->groupBy(fn ($t) => $t->healthZone?->name ?? '—') as $zoneName => $items)
                    <optgroup label="{{ $zoneName }}">
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" @selected($item->id === $team->id)>{{ $item->label() }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </form>
    @endunless
</x-page-header>

<ul class="nav nav-tabs mb-3">
    @foreach ([
        'progression' => ['team.space', 'graph-up', 'Progression'],
        'soumissions' => ['team.submissions', 'house-door', 'Soumissions'],
        'queries' => ['team.queries', 'flag', 'Queries'],
        'grappes' => ['team.clusters', 'bounding-box', 'Grappes'],
    ] as $key => [$route, $icon, $label])
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route($route, $teamParam) }}" @if ($tab === $key) aria-current="page" @endif>
                <i class="bi bi-{{ $icon }} me-1"></i>{{ $label }}
                @if ($key === 'queries' && $openQueries)<span class="badge rounded-pill text-bg-danger ms-1">{{ $openQueries }}</span>@endif
            </a>
        </li>
    @endforeach
</ul>
