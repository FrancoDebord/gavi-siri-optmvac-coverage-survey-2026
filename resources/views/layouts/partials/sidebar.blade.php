@php
    $link = fn (string $route, string $pattern = null) => request()->routeIs($pattern ?? $route) ? 'nav-link active' : 'nav-link';
@endphp
<nav class="app-sidebar" aria-label="Menu principal">
    <a href="{{ auth()->user()->homeRoute() }}" class="brand">
        <i class="bi bi-shield-plus"></i>
        <span class="label">GAVI SIRI · Round 2</span>
    </a>

    @php
        $openIncidents = \App\Models\FieldIncident::visibleTo(auth()->user())->whereIn('status', \App\Models\FieldIncident::UNRESOLVED)->count();
        $unreadMessages = auth()->user()->can('send messages') ? 0 : \App\Models\TeamMessage::visibleTo(auth()->user())->unreadBy(auth()->user())->count();
        $hasOwnHome = auth()->user()->hasAnyRole([\App\Models\User::ROLE_SUPERVISOR, \App\Models\User::ROLE_PI]);
        // Investigateur principal : menu réduit à l'essentiel (pas d'espaces superviseur ni binômes).
        $isPi = auth()->user()->hasRole(\App\Models\User::ROLE_PI);
    @endphp
    @if (auth()->user()->hasRole(\App\Models\User::ROLE_TEAM))
        <div class="nav-section">Mon binôme</div>
        @php($teamQueries = \App\Models\DataQuery::visibleTo(auth()->user())->whereIn('status', \App\Models\DataQuery::UNRESOLVED)->count())
        <a class="{{ $link('team.space') }}" href="{{ route('team.space') }}"><i class="bi bi-people-fill"></i><span class="label">Ma progression
            @if ($unreadMessages)<span class="badge rounded-pill text-bg-primary ms-1" title="Messages non lus">{{ $unreadMessages }}</span>@endif</span></a>
        <a class="{{ $link('team.submissions', 'households.*') }} {{ request()->routeIs('team.submissions') ? 'active' : '' }}" href="{{ route('team.submissions') }}"><i class="bi bi-house-door"></i><span class="label">Mes soumissions</span></a>
        <a class="{{ $link('team.queries', 'queries.*') }} {{ request()->routeIs('team.queries') ? 'active' : '' }}" href="{{ route('team.queries') }}"><i class="bi bi-flag"></i><span class="label">Mes queries
            @if ($teamQueries)<span class="badge rounded-pill text-bg-danger ms-1">{{ $teamQueries }}</span>@endif</span></a>
        <a class="{{ $link('team.clusters', 'clusters.*') }} {{ request()->routeIs('team.clusters') ? 'active' : '' }}" href="{{ route('team.clusters') }}"><i class="bi bi-bounding-box"></i><span class="label">Mes grappes</span></a>
        <a class="{{ $link('maps.clusters') }}" href="{{ route('maps.clusters') }}"><i class="bi bi-map"></i><span class="label">Carte de mes villages</span></a>
    @endif
    @if ($isPi)
        @php($openQueries = \App\Models\DataQuery::whereIn('status', \App\Models\DataQuery::UNRESOLVED)->count())
        <div class="nav-section">Suivi de la collecte</div>
        <a class="{{ $link('investigator.index') }}" href="{{ route('investigator.index') }}"><i class="bi bi-grid-1x2"></i><span class="label">Vue d'ensemble</span></a>
        <a class="{{ $link('investigator.progress') }}" href="{{ route('investigator.progress') }}"><i class="bi bi-graph-up"></i><span class="label">Progression</span></a>
        <a class="{{ $link('investigator.summary*') }}" href="{{ route('investigator.summary') }}"><i class="bi bi-file-earmark-text"></i><span class="label">Rapport de synthèse</span></a>
        <a class="{{ $link('investigator.clusters*') }}" href="{{ route('investigator.clusters') }}"><i class="bi bi-bounding-box"></i><span class="label">Grappes par village</span></a>
        <a class="{{ $link('maps.clusters', 'clusters.*') }} {{ request()->routeIs('maps.clusters') ? 'active' : '' }}" href="{{ route('maps.clusters') }}"><i class="bi bi-map"></i><span class="label">Carte des grappes</span></a>
        <a class="{{ $link('submissions.*', 'households.*') }} {{ request()->routeIs('submissions.*') ? 'active' : '' }}" href="{{ route('submissions.index') }}"><i class="bi bi-house-door"></i><span class="label">Soumissions</span></a>
        <a class="{{ $link('queries.*') }}" href="{{ route('queries.dashboard') }}"><i class="bi bi-flag"></i><span class="label">Queries
            @if ($openQueries)<span class="badge rounded-pill text-bg-danger ms-1" title="Queries non résolues">{{ $openQueries }}</span>@endif</span></a>

        <div class="nav-section">Pilotage</div>
        <a class="{{ $link('decisions.*') }}" href="{{ route('decisions.index') }}"><i class="bi bi-journal-check"></i><span class="label">Décisions de terrain</span></a>
        <a class="{{ $link('messages.*') }}" href="{{ route('messages.index') }}"><i class="bi bi-chat-left-text"></i><span class="label">Messages aux équipes</span></a>
        <a class="{{ $link('incidents.*') }}" href="{{ route('incidents.index') }}"><i class="bi bi-exclamation-triangle"></i><span class="label">Incidents terrain
            @if ($openIncidents)<span class="badge rounded-pill text-bg-danger ms-1">{{ $openIncidents }}</span>@endif</span></a>
    @else
    @can('view pi dashboard')
        <div class="nav-section">Investigateur principal</div>
        <a class="{{ $link('investigator.index') }}" href="{{ route('investigator.index') }}"><i class="bi bi-grid-1x2"></i><span class="label">Vue d'ensemble PI</span></a>
        <a class="{{ $link('investigator.progress') }}" href="{{ route('investigator.progress') }}"><i class="bi bi-graph-up"></i><span class="label">Progression</span></a>
        <a class="{{ $link('investigator.summary*') }}" href="{{ route('investigator.summary') }}"><i class="bi bi-file-earmark-text"></i><span class="label">Rapport de synthèse</span></a>
        <a class="{{ $link('investigator.clusters*') }}" href="{{ route('investigator.clusters') }}"><i class="bi bi-bounding-box"></i><span class="label">Grappes par village</span></a>
    @endcan
    @can('view dashboards')
    <div class="nav-section">Supervision</div>
    <a class="{{ $link('supervision.*') }}" href="{{ route('supervision.index') }}"><i class="bi bi-geo-alt"></i><span class="label">{{ auth()->user()->geographicScope() ? 'Ma zone' : 'Espace superviseur' }}</span></a>
    <a class="{{ $link('incidents.*') }}" href="{{ route('incidents.index') }}"><i class="bi bi-exclamation-triangle"></i><span class="label">Incidents terrain
        @if ($openIncidents)<span class="badge rounded-pill text-bg-danger ms-1">{{ $openIncidents }}</span>@endif</span></a>
    <a class="{{ $link('messages.*') }}" href="{{ route('messages.index') }}"><i class="bi bi-chat-left-text"></i><span class="label">Messages aux équipes
        @if ($unreadMessages)<span class="badge rounded-pill text-bg-primary ms-1">{{ $unreadMessages }}</span>@endif</span></a>
    <a class="{{ $link('team.space') }}" href="{{ route('team.space') }}"><i class="bi bi-people-fill"></i><span class="label">Espace des binômes</span></a>
    <a class="{{ $link('decisions.*') }}" href="{{ route('decisions.index') }}"><i class="bi bi-journal-check"></i><span class="label">Décisions de terrain</span></a>

    <div class="nav-section">Suivi</div>
    <a class="{{ $link('dashboard') }}" href="{{ route('dashboard', $hasOwnHome ? ['complet' => 1] : []) }}"><i class="bi bi-speedometer2"></i><span class="label">Progression</span></a>
    <a class="{{ $link('maps.clusters') }}" href="{{ route('maps.clusters') }}"><i class="bi bi-map"></i><span class="label">Carte des grappes</span></a>
    <a class="{{ $link('submissions.*') }}" href="{{ route('submissions.index') }}"><i class="bi bi-house-door"></i><span class="label">Soumissions</span></a>
    <a class="{{ $link('queries.dashboard') }}" href="{{ route('queries.dashboard') }}"><i class="bi bi-clipboard2-pulse"></i><span class="label">Dashboard queries</span></a>
    <a class="{{ $link('queries.index', 'queries.index') }} {{ request()->routeIs('queries.show') ? 'active' : '' }}" href="{{ route('queries.index') }}"><i class="bi bi-list-check"></i><span class="label">Liste des queries
        @if ($myQueries = \App\Models\DataQuery::where('assigned_to', auth()->id())->whereIn('status', \App\Models\DataQuery::UNRESOLVED)->count())<span class="badge rounded-pill text-bg-primary ms-1" title="Queries qui vous sont assignées">{{ $myQueries }}</span>@endif</span></a>
    <a class="{{ $link('observations.*') }}" href="{{ route('observations.index') }}"><i class="bi bi-binoculars"></i><span class="label">Observations de supervision</span></a>
    @endcan
    @endif

    @canany(['import data', 'manage dictionary'])
        <div class="nav-section">Données</div>
        @can('import data')
            <a class="{{ $link('imports.*') }}" href="{{ route('imports.index') }}"><i class="bi bi-upload"></i><span class="label">Imports</span></a>
        @endcan
        @can('manage dictionary')
            <a class="{{ $link('dictionary.*') }}" href="{{ route('dictionary.index') }}"><i class="bi bi-journal-text"></i><span class="label">Data dictionary</span></a>
            <a class="{{ $link('mapping.*') }}" href="{{ route('mapping.edit') }}"><i class="bi bi-diagram-3"></i><span class="label">Mapping des variables</span></a>
        @endcan
    @endcanany

    @canany(['manage rules', 'manage sources', 'manage users', 'view audit'])
        <div class="nav-section">Paramètres</div>
        @can('manage rules')
            <a class="{{ $link('rules.*') }}" href="{{ route('rules.index') }}"><i class="bi bi-sliders"></i><span class="label">Règles qualité</span></a>
        @endcan
        @can('manage sources')
            <a class="{{ $link('sources.*') }}" href="{{ route('sources.index') }}"><i class="bi bi-cloud-arrow-down"></i><span class="label">Sources de données</span></a>
        @endcan
        @can('manage users')
            <a class="{{ $link('users.*') }}" href="{{ route('users.index') }}"><i class="bi bi-people"></i><span class="label">Utilisateurs</span></a>
        @endcan
        @can('view audit')
            <a class="{{ $link('audit.*') }}" href="{{ route('audit.index') }}"><i class="bi bi-shield-check"></i><span class="label">Journal d'audit</span></a>
        @endcan
        @can('manage settings')
            <a class="{{ $link('settings.*') }}" href="{{ route('settings.edit') }}"><i class="bi bi-gear"></i><span class="label">Paramètres de l'application</span></a>
        @endcan
    @endcanany

    @if (\App\Http\Controllers\DocumentationController::availablePages(auth()->user()))
        <div class="nav-section">Aide</div>
        <a class="{{ $link('documentation.*') }}" href="{{ route('documentation.index') }}"><i class="bi bi-question-circle"></i><span class="label">Documentation</span></a>
    @endif
</nav>
