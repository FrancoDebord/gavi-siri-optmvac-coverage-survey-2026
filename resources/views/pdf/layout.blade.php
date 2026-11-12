<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Rapport' }}</title>
    <style>
        @page { margin: 18mm 12mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #010101; }
        h1 { font-size: 15px; margin: 0 0 2px; color: #C10202; }
        h2 { font-size: 12px; margin: 14px 0 6px; color: #010101; border-bottom: 2px solid #C10202; padding-bottom: 2px; }
        h3 { font-size: 10px; margin: 8px 0 4px; }
        .muted { color: #706D6B; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #DDDAD8; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #010101; color: #FFFFFF; }
        td.num, th.num { text-align: right; }
        .kpis td { text-align: center; border: 1px solid #DDDAD8; }
        .kpis .v { font-size: 13px; font-weight: bold; }
        .lvl0 { background: #F1F0EF; font-weight: bold; }
        .lvl1 td:first-child { padding-left: 10px; }
        .lvl2 td:first-child { padding-left: 20px; }
        .lvl3 td:first-child { padding-left: 30px; color: #444; }
        .sev-critique { color: #C10202; font-weight: bold; }
        .page-break { page-break-after: always; }
        footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 8px; color: #706D6B; }
        header.running { position: fixed; top: -12mm; left: 0; right: 0; font-size: 8px; color: #706D6B; border-bottom: 1px solid #DDDAD8; padding-bottom: 2px; }
        header.running .date { float: right; color: #010101; font-weight: bold; }
    </style>
</head>
<body>
@hasSection('header')<header class="running">@yield('header')</header>@endif
<footer>{{ config('app.name') }} · généré le {{ now()->format('d/m/Y H:i') }}</footer>
@yield('content')
</body>
</html>
