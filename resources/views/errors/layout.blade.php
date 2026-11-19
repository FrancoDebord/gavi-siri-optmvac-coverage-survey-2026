{{--
    Page d'erreur autonome (styles en ligne, sans Vite ni base de données) : elle s'affiche même quand l'application est en panne.
    Variables : $code, $title, $message, $icon (pictogramme SVG : lock, search, clock, alert, tool), $retry (bouton « Réessayer »).
--}}
@php
    $home = url('/');
    try {
        $home = auth()->check() ? auth()->user()->homeRoute() : route('login');
    } catch (\Throwable) {
    }
    $icons = [
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4 2.6-2.6z"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name', 'GAVI SIRI') }}</title>
    <style>
        :root { --red: #c10202; --ink: #1d1b1a; --muted: #6f6c6a; --line: #ebe8e6; --bg: #faf9f8; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--ink);
            font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .box { width: 100%; max-width: 480px; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 36px 32px 30px;
            box-shadow: 0 10px 30px rgba(29, 27, 26, .06); text-align: center; border-top: 4px solid var(--red); }
        .icon { width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center; background: #fdeeee; color: var(--red); }
        .icon svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .code { font-size: 13px; letter-spacing: .12em; color: var(--muted); text-transform: uppercase; margin: 0 0 4px; }
        h1 { font-size: 22px; margin: 0 0 10px; font-weight: 650; }
        p { color: var(--muted); margin: 0 0 24px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        a, button { font: inherit; font-size: 14px; padding: 9px 18px; border-radius: 10px; cursor: pointer; text-decoration: none; border: 1px solid var(--line); background: #fff; color: var(--ink); }
        a.primary, button.primary { background: var(--red); border-color: var(--red); color: #fff; }
        a:hover, button:hover { filter: brightness(.96); }
        .brand { margin-top: 22px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<main class="box">
    <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icons[$icon ?? 'alert'] ?? $icons['alert'] !!}</svg></div>
    <p class="code">Erreur {{ $code }}</p>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    <div class="actions">
        @if (! empty($retry))
            <button class="primary" type="button" onclick="location.reload()">Réessayer</button>
        @endif
        <button type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ $home }}')">Page précédente</button>
        <a class="{{ empty($retry) ? 'primary' : '' }}" href="{{ $home }}">Accueil</a>
    </div>
    <div class="brand">Enquête de couverture vaccinale · GAVI SIRI Round 2</div>
</main>
</body>
</html>
