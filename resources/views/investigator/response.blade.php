@php
    $zoneName = $zoneId ? $zones->firstWhere('id', $zoneId)?->name : null;
    $period = $from || $to
        ? 'du '.($from ? \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') : 'début').' au '.($to ? \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') : $today->format('d/m/Y'))
        : 'depuis le début de la collecte';
@endphp
<x-layouts.app title="Taux de réponse · Investigateur principal" page="pi-response">
    <x-page-header title="Taux de réponse de l'enquête" :subtitle="($zoneName ? 'Zone sanitaire de '.$zoneName : 'Toutes les zones sanitaires').' · '.$period.' · situation au '.$today->format('d/m/Y')">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <label for="resp-zone" class="visually-hidden">Zone sanitaire</label>
            <select id="resp-zone" name="health_zone_id" class="form-select form-select-sm w-auto">
                <option value="">Toutes les zones</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
            <label for="resp-from" class="small text-muted mb-0">Du</label>
            <input id="resp-from" type="date" name="du" value="{{ $from }}" class="form-control form-control-sm w-auto" max="{{ $today->toDateString() }}">
            <label for="resp-to" class="small text-muted mb-0">au</label>
            <input id="resp-to" type="date" name="au" value="{{ $to }}" class="form-control form-control-sm w-auto" max="{{ $today->toDateString() }}">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Afficher</button>
        </form>
    </x-page-header>

    @include('investigator._tabs')

    @error('au')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

    <div id="pi-response">
        <x-response-table />
    </div>
    <script type="application/json" id="response-data">@json($response)</script>
</x-layouts.app>
