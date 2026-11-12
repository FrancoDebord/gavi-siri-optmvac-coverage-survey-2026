@props([
    'storageKey' => 'global',
    'dateLabel' => 'Période',
    'sources' => true,
])
{{--
    Filtres globaux en cascade. Les options sont chargées par JS depuis filters.options
    (en tenant compte du périmètre de l'utilisateur) ; les valeurs sont mémorisées par page.
--}}
<form id="filter-bar" class="card filter-bar mb-3" data-storage-key="{{ $storageKey }}" data-options-url="{{ route('filters.options') }}">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-zone">Zone sanitaire</label>
                <select id="f-zone" class="form-select form-select-sm" data-filter="health_zone_id" data-placeholder="Toutes"><option value="">Toutes</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-commune">Commune</label>
                <select id="f-commune" class="form-select form-select-sm" data-filter="commune_id" data-placeholder="Toutes"><option value="">Toutes</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-village">Village</label>
                <select id="f-village" class="form-select form-select-sm" data-filter="village_id"><option value="">Tous</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-cluster">Grappe</label>
                <select id="f-cluster" class="form-select form-select-sm" data-filter="cluster_id" data-placeholder="Toutes"><option value="">Toutes</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-team">Binôme</label>
                <select id="f-team" class="form-select form-select-sm" data-filter="team_id"><option value="">Tous</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-0" for="f-collector">Agent</label>
                <select id="f-collector" class="form-select form-select-sm" data-filter="collector_id"><option value="">Tous</option></select>
            </div>
            @if ($sources)
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label small mb-0" for="f-source">Source</label>
                    <select id="f-source" class="form-select form-select-sm" data-filter="source" data-placeholder="Toutes"><option value="">Toutes</option></select>
                </div>
            @endif
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-from">{{ $dateLabel }} : du</label>
                <input id="f-from" type="date" class="form-control form-control-sm" data-filter="from">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-to">au</label>
                <input id="f-to" type="date" class="form-control form-control-sm" data-filter="to">
            </div>
            {{ $slot }}
            <div class="col-12 col-md-auto ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-filter-reset><i class="bi bi-x-circle me-1"></i>Réinitialiser</button>
            </div>
        </div>
    </div>
</form>
