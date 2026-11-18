@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $report['totals'];
@endphp
<x-layouts.app title="Grappes par village · Investigateur principal" page="team">
    <x-page-header title="Grappes par village" :subtitle="$scopeLabel.' · situation au '.$generatedAt->format('d/m/Y à H:i')">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
            <label for="cl-zone" class="visually-hidden">Zone sanitaire</label>
            <select id="cl-zone" name="health_zone_id" class="form-select form-select-sm w-auto">
                <option value="">Toutes les zones</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Afficher</button>
            <a href="{{ route('investigator.clusters.pdf', array_filter(['health_zone_id' => $zoneId])) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF à imprimer</a>
            <a href="{{ route('investigator.clusters.xlsx', array_filter(['health_zone_id' => $zoneId])) }}" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        </form>
    </x-page-header>

    @include('investigator._tabs')

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl"><x-kpi-card label="Villages" kpi="villages" icon="geo-alt" color="dark">{{ $fmt($total['villages']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Grappes (base de sondage)" kpi="clusters" icon="bounding-box" color="dark" :sub="$total['clusters'].' visitée(s)'">{{ $fmt($total['frame_clusters']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Ménages attendus (base de sondage)" kpi="expected" icon="clipboard-data" color="dark">{{ $fmt($total['expected']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door" :sub="$total['expected'] ? round(100 * $total['visited_framed'] / $total['expected']).' % des attendus' : null">{{ $fmt($total['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success">{{ $fmt($total['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl"><x-kpi-card label="Enfants éligibles (12–35 mois)" kpi="children" icon="person-hearts" color="info">{{ $fmt($total['children']) }}</x-kpi-card></div>
    </div>

    @include('investigator._cluster-table', ['report' => $report, 'withTeams' => true])

    <p class="small text-muted">
        N° grappe et N° sous-grappe sélectionnée : lus dans l'identifiant ménage (ZONE-VIL-NN-Sx-MMM) ; NA quand l'identifiant ne porte pas de sous-grappe. Sous-grappes créées : réponse 6a du formulaire. Plusieurs valeurs = identifiants ou réponses discordants entre soumissions.
        Ménage enquêté : consentement donné. Les grappes délimitées mais pas encore visitées apparaissent avec 0 ménage et sans réponse.
    </p>
</x-layouts.app>
