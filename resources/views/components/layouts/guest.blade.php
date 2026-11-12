@props(['title' => null])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Connexion' }} · {{ config('app.name') }}</title>
    @vite(['resources/js/app.js'])
</head>
<body>
<main class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center text-white mb-4">
            <i class="bi bi-shield-plus display-5"></i>
            <h1 class="h4 mt-2 mb-0">Enquête de couverture vaccinale</h1>
            <p class="mb-0 opacity-75">GAVI SIRI · Round 2 – suivi de la collecte</p>
        </div>
        <div class="card shadow">
            <div class="card-body p-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</main>
</body>
</html>
