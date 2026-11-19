@props(['title' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="session-refresh" content="{{ route('session.token') }}">
    <title>{{ $title ?? 'Connexion' }} · {{ config('app.name') }}</title>
    @vite(['resources/js/app.js'])
</head>
<body>
<main class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4 auth-brand">
            <i class="bi bi-shield-plus display-5"></i>
            <h1 class="h4 mt-2 mb-0">Enquête de couverture vaccinale</h1>
            <p class="mb-0 text-muted">GAVI SIRI · Round 2 – suivi de la collecte</p>
        </div>
        <div class="card">
            <div class="card-body p-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</main>
</body>
</html>
