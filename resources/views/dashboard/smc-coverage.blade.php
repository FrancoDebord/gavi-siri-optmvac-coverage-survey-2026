<x-layouts.app title="Couverture CPS" page="smc-coverage">
    <div id="smc-coverage" data-url="{{ route('coverage.data', 'cps') }}" data-max-cycles="{{ $maxCycles }}">
        <x-page-header title="Couverture de la chimioprévention du paludisme saisonnier (CPS)" subtitle="Enfants enrôlés (tranche d'âge éligible), base nettoyée : cycle reçu d'après la carte CPS présentée, sinon le rappel du caregiver">
            <span class="small text-muted align-self-center">Actualisé à <span data-generated-at>…</span></span>
            <a href="{{ route('coverage.show', 'antigenes') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-plus me-1"></i>Antigènes</a>
            <a href="{{ route('coverage.vap') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-check me-1"></i>VAP</a>
            @can('export data')
                <a href="{{ route('coverage.export', 'cps') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            @endcan
        </x-page-header>

        <x-filter-bar storage-key="smc-coverage" date-label="Date d'interview" />

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-2"><x-kpi-card label="Enfants enrôlés" kpi="children" icon="emoji-smile" color="dark" /></div>
            <div class="col-6 col-lg-2"><x-kpi-card label="CPS déclarée (6 derniers mois)" kpi="declared" icon="chat-quote" color="secondary" /></div>
            <div class="col-6 col-lg-2"><x-kpi-card label="Carte CPS reçue" kpi="card_given" icon="card-text" color="secondary" sub="Présentée :" sub-kpi="card_present" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Au moins un cycle reçu" kpi="at_least_one" icon="capsule" color="success" /></div>
            <div class="col-12 col-lg-3"><x-kpi-card label="Tous les cycles prévus reçus" kpi="all_cycles" icon="check2-all" color="success" /></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6"><x-chart-card title="Couverture par cycle (carte et rappel)" canvas="chart-cycles" subtitle="Part des enfants des communes où le cycle est prévu : sur la carte CPS, ou au rappel seulement." /></div>
            <div class="col-lg-6"><x-chart-card title="Nombre de cycles reçus" canvas="chart-distribution" subtitle="Répartition des enfants selon le nombre de cycles reçus (carte, sinon rappel)." /></div>
            <div class="col-lg-6"><x-chart-card title="Par zone sanitaire" canvas="chart-zones" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par commune" canvas="chart-communes" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par tranche d'âge" canvas="chart-age" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Par sexe" canvas="chart-sex" size="chart-sm" /></div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Détail par cycle</div>
            <div class="card-body p-0 table-responsive">
                <table id="cycle-table" class="table table-sm table-striped mb-0">
                    <thead class="table-light"><tr><th>Cycle</th><th class="text-end">Dénominateur</th><th class="text-end">Carte</th><th class="text-end">Rappel seul</th><th class="text-end">Total reçus</th><th class="text-end">Couverture</th><th class="text-end">Carte seule</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Couverture CPS par <span data-level-label>zone sanitaire</span></span>
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
                Cycles prévus par commune (fichier de paramètres) : 4 à Tchaourou, 5 à Dassa et Glazoué ; un cycle non prévu n'est pas compté.
                Estimations brutes, non pondérées. Couleur : vert ≥ 90 %, gris foncé ≥ 60 %, orange ≥ 30 %, rouge en dessous.
            </div>
        </div>
    </div>
</x-layouts.app>
