<x-layouts.app title="Couverture vaccinale" page="vaccine-coverage">
    <div id="vaccine-coverage" data-url="{{ route('coverage.data', 'antigenes') }}">
        <x-page-header title="Couverture vaccinale par antigène" subtitle="Enfants enrôlés (tranche d'âge éligible), base nettoyée : dose reçue d'après la carte, sinon le rappel du caregiver">
            <span class="small text-muted align-self-center">Actualisé à <span data-generated-at>…</span></span>
            <a href="{{ route('coverage.vap') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-check me-1"></i>VAP</a>
            <a href="{{ route('coverage.show', 'cps') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-capsule me-1"></i>CPS</a>
            @can('export data')
                <a href="{{ route('coverage.export', 'antigenes') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
                <a href="{{ route('coverage.pdf', 'antigenes') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            @endcan
        </x-page-header>

        <x-filter-bar storage-key="vaccine-coverage" date-label="Date d'interview" />

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg"><x-kpi-card label="Enfants enrôlés" kpi="children" icon="emoji-smile" color="dark" sub="Carte vue :" sub-kpi="card_seen" /></div>
            <div class="col-6 col-lg"><x-kpi-card label="Complètement vaccinés (BCG, VPO 3, Penta 3, RR 1)" kpi="fully_basic" icon="patch-check" color="success" /></div>
            <div class="col-6 col-lg"><x-kpi-card label="Complètement vaccinés pour l'âge (toutes les doses dues)" kpi="fully_age" icon="patch-check-fill" color="success" /></div>
            <div class="col-6 col-lg"><x-kpi-card label="Zéro dose (sans Penta 1)" kpi="zero_dose" icon="shield-x" color="danger" /></div>
            <div class="col-6 col-lg"><x-kpi-card label="Abandon Penta 1 → Penta 3" kpi="dropout_penta" icon="arrow-down-right" color="secondary" /></div>
            <div class="col-6 col-lg"><x-kpi-card label="Abandon Penta 1 → RR 1" kpi="dropout_penta_rr" icon="arrow-down-right" color="secondary" /></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7"><x-chart-card title="Couverture par dose (carte et rappel)" canvas="chart-doses" size="chart-lg" subtitle="Part des enfants en âge de recevoir la dose : sur la carte, ou déclarée au rappel seulement." /></div>
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header">Détail par dose</div>
                    <div class="card-body p-0 table-responsive" style="max-height: 520px">
                        <table id="dose-table" class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top"><tr><th>Dose</th><th class="text-end">Âge</th><th class="text-end">Dénom.</th><th class="text-end">Carte</th><th class="text-end">Rappel</th><th class="text-end">Couverture</th><th class="text-end">Carte seule</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                <label for="dose-select" class="small fw-semibold mb-0">Dose détaillée dans les graphiques ci-dessous</label>
                <select id="dose-select" class="form-select form-select-sm w-auto">
                    @foreach ($doses as $dose)
                        <option value="{{ $dose['code'] }}" @selected($dose['code'] === 'penta3')>{{ $dose['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-lg-6"><x-chart-card title="Par zone sanitaire" canvas="chart-zones" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par commune" canvas="chart-communes" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par tranche d'âge" canvas="chart-age" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par sexe" canvas="chart-sex" size="chart-sm" /></div>
        </div>

        <x-ppt-tables note="Couverture de chaque dose parmi les enfants qui ont atteint l'âge de la dose (carte, sinon rappel). Estimations brutes, non pondérées." />

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Couverture des principales doses par <span data-level-label>zone sanitaire</span></span>
                <select id="coverage-level" class="form-select form-select-sm w-auto" aria-label="Niveau du tableau">
                    <option value="by_zone">Zone sanitaire</option>
                    <option value="by_commune">Commune</option>
                    <option value="by_village">Village</option>
                    <option value="by_age">Tranche d'âge</option>
                    <option value="by_sex">Sexe</option>
                </select>
            </div>
            <div class="card-body p-0 table-responsive" style="max-height: 520px">
                <table id="level-table" class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"></thead><tbody></tbody></table>
            </div>
            <div class="card-footer small text-muted">
                Chaque dose est rapportée aux enfants qui ont atteint son âge (colonne « Âge » du détail par dose). Estimations brutes, non pondérées.
                Les doses jamais renseignées dans les données (question non posée) ne sont pas affichées. Couleur : vert ≥ 90 %, gris foncé ≥ 60 %, orange ≥ 30 %, rouge en dessous.
            </div>
        </div>
    </div>
    <script type="application/json" id="dose-list">@json($doses)</script>
</x-layouts.app>
