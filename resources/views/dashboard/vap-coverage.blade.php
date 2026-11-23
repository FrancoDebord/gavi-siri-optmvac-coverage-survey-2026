<x-layouts.app title="Couverture VAP" page="vap-coverage">
    <div id="vap-coverage" data-url="{{ route('coverage.vap.data') }}">
        <x-page-header title="Couverture du vaccin antipaludique (VAP 1 à 4)" subtitle="Enfants enrôlés (tranche d'âge éligible), base nettoyée : dose reçue d'après la carte, sinon le rappel du caregiver">
            <span class="small text-muted align-self-center">Actualisé à <span data-generated-at>…</span></span>
            @can('export data')
                <a href="{{ route('coverage.vap.export') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            @endcan
        </x-page-header>

        <x-filter-bar storage-key="vap-coverage" date-label="Date d'interview" />

        {{-- Indicateurs --}}
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-2"><x-kpi-card label="Enfants enrôlés" kpi="children" icon="emoji-smile" color="dark" sub="Carte vue :" sub-kpi="card_seen" /></div>
            @foreach ([1, 2, 3, 4] as $k)
                <div class="col-6 col-lg-2">
                    <x-kpi-card :label="'VAP '.$k.' (carte ou rappel)'" :kpi="'vap'.$k" icon="shield-check" :color="$k === 4 ? 'warning' : 'success'" sub="Carte seule :" :sub-kpi="'vap'.$k.'_card'" />
                </div>
            @endforeach
            <div class="col-6 col-lg-2"><x-kpi-card label="Aucune dose de VAP" kpi="zero_dose" icon="shield-x" color="danger" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Abandon VAP 1 → VAP 3" kpi="dropout_1_3" icon="arrow-down-right" color="secondary" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Abandon VAP 1 → VAP 4" kpi="dropout_1_4" icon="arrow-down-right" color="secondary" /></div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-body small text-muted">
                        <strong>Lecture.</strong> Couverture = enfants ayant reçu la dose / dénominateur. VAP 1 à 3 : tous les enfants enrôlés (12–35 mois).
                        VAP 4 : enfants qui ont atteint l'âge de la 4e dose (<span data-kpi="vap4_due">…</span> enfants ;
                        @foreach ($vap4Ages as $zone => $months){{ $zone }} : {{ $months }} mois{{ $loop->last ? '' : ', ' }}@endforeach).
                        Abandon = enfants ayant reçu VAP 1 sans la dose suivante, rapportés à ceux qui ont reçu VAP 1. Estimations brutes, non pondérées.
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6"><x-chart-card title="Couverture par dose (carte et rappel)" canvas="chart-doses" subtitle="Part des enfants : dose sur la carte, dose déclarée au rappel seulement." /></div>
            <div class="col-lg-6"><x-chart-card title="Couverture par zone sanitaire" canvas="chart-zones" /></div>
            <div class="col-lg-6"><x-chart-card title="Couverture par tranche d'âge" canvas="chart-age" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Couverture par sexe" canvas="chart-sex" size="chart-sm" /></div>
            <div class="col-12"><x-chart-card title="Couverture par commune" canvas="chart-communes" /></div>
        </div>

        {{-- Tableau par dose --}}
        <div class="card mb-3">
            <div class="card-header">Détail par dose</div>
            <div class="card-body p-0 table-responsive">
                <table id="dose-table" class="table table-sm table-striped mb-0">
                    <thead class="table-light"><tr><th>Dose</th><th class="text-end">Dénominateur</th><th class="text-end">Reçue (carte)</th><th class="text-end">Reçue (rappel seul)</th><th class="text-end">Total reçues</th><th class="text-end">Couverture</th><th class="text-end">Carte seule</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        {{-- Tableaux par niveau --}}
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Couverture par <span data-level-label>zone sanitaire</span></span>
                <select id="coverage-level" class="form-select form-select-sm w-auto" aria-label="Niveau du tableau">
                    <option value="by_zone">Zone sanitaire</option>
                    <option value="by_commune">Commune</option>
                    <option value="by_village">Village</option>
                    <option value="by_age">Tranche d'âge</option>
                    <option value="by_sex">Sexe</option>
                </select>
            </div>
            <div class="card-body p-0 table-responsive" style="max-height: 520px">
                <table id="level-table" class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr><th data-level-head>Zone sanitaire</th><th class="text-end">Enfants</th><th class="text-end">VAP 1</th><th class="text-end">VAP 2</th><th class="text-end">VAP 3</th><th class="text-end">VAP 4</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">Chaque cellule : couverture (reçues / dénominateur). Couleur : vert ≥ 90 %, gris foncé ≥ 60 %, orange ≥ 30 %, rouge en dessous.</div>
        </div>
    </div>
</x-layouts.app>
