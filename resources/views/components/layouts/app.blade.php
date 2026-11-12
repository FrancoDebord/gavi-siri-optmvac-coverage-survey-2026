@props(['title' => null, 'page' => null, 'bodyClass' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Tableau de bord' }} · {{ config('app.name') }}</title>
    @vite(['resources/js/app.js'])
</head>
<body data-page="{{ $page ?? '' }}" @if ($bodyClass) class="{{ $bodyClass }}" @endif>
<div class="app-shell">
    @include('layouts.partials.sidebar')
    <div class="sidebar-backdrop"></div>

    <div class="app-main">
        <header class="app-navbar navbar px-3 py-2">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light btn-sm" type="button" data-sidebar-toggle aria-label="Afficher / masquer le menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <span class="fw-semibold d-none d-sm-inline">{{ $title ?? '' }}</span>
            </div>
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle fs-5"></i>
                    <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><span class="dropdown-item-text small text-muted">{{ auth()->user()->roleLabel() }}
                        @if ($scope = auth()->user()->geographicScope())
                            <br>Périmètre : {{ auth()->user()->commune?->name ?? auth()->user()->healthZone?->name }}
                        @endif
                    </span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-key me-2"></i>Mot de passe</a></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="app-content">
            @include('layouts.partials.flash')
            {{ $slot }}
        </main>

        <footer class="px-4 pb-3 small text-muted">
            Enquête de couverture vaccinale GAVI SIRI – Round 2 · Heure locale {{ now()->format('d/m/Y H:i') }}
        </footer>
    </div>
</div>
@stack('scripts')
</body>
</html>
