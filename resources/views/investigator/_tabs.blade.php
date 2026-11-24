{{-- Onglets de l'espace investigateur principal ; la zone choisie suit d'un onglet à l'autre. --}}
<ul class="nav nav-tabs mb-3">
    @foreach ([
        'investigator.index' => ['grid-1x2', 'Vue d\'ensemble'],
        'investigator.progress' => ['graph-up', 'Progression'],
        'investigator.response' => ['percent', 'Taux de réponse'],
        'investigator.summary' => ['file-earmark-text', 'Rapport de synthèse'],
        'investigator.clusters' => ['bounding-box', 'Grappes par village'],
        'remaining-villages.index' => ['hourglass-split', 'Villages restants'],
    ] as $route => [$icon, $label])
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route, array_filter(['health_zone_id' => $zoneId])) }}" @if (request()->routeIs($route)) aria-current="page" @endif>
                <i class="bi bi-{{ $icon }} me-1"></i>{{ $label }}
            </a>
        </li>
    @endforeach
    <li class="nav-item ms-auto">
        <a class="nav-link" href="{{ route('maps.clusters') }}"><i class="bi bi-map me-1"></i>Carte des grappes</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('submissions.index', array_filter(['health_zone_id' => $zoneId])) }}"><i class="bi bi-house-door me-1"></i>Soumissions</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('queries.dashboard', array_filter(['health_zone_id' => $zoneId])) }}"><i class="bi bi-flag me-1"></i>Queries</a>
    </li>
    @can('manage field photos')
        <li class="nav-item">
            <a class="nav-link" href="{{ route('photo-projects.index') }}"><i class="bi bi-images me-1"></i>Photos de terrain</a>
        </li>
    @endcan
</ul>
