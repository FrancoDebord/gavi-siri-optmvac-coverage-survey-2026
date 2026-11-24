@props(['title' => null, 'page' => null])
{{-- Pages publiques sans compte (espaces photos de terrain) : sans menu, adaptées au téléphone. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="session-refresh" content="{{ route('session.token') }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? 'Photos de terrain' }} · {{ config('app.name') }}</title>
    @vite(['resources/js/app.js'])
</head>
<body data-page="{{ $page ?? '' }}" class="public-page">
<main class="container py-3 py-md-4" style="max-width: 1100px">
    @include('layouts.partials.flash')
    {{ $slot }}
</main>
</body>
</html>
