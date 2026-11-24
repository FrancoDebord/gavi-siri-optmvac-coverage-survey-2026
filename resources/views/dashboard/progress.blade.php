<x-layouts.app title="Progression de la collecte" page="progress">
    <div id="progress-dashboard" data-url="{{ route('dashboard.data') }}" data-refresh="{{ $refresh }}">
        <x-page-header title="Progression de la collecte" subtitle="Ménages visités, caregivers interviewés et enfants enrôlés">
            <span class="small text-muted align-self-center">
                <span id="refresh-status" class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                Actualisé à <span data-generated-at>…</span> · toutes les {{ $refresh }} s
            </span>
            @can('export data')
                <div class="btn-group">
                    <a href="{{ route('dashboard.export', 'xlsx') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
                    <a href="{{ route('dashboard.export', 'pdf') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                </div>
            @endcan
        </x-page-header>

        @can('manage sources')<x-queue-warning />@endcan

        <x-filter-bar storage-key="progress" date-label="Date d'interview" />

        {{-- KPI --}}
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Ménages enquêtés (consentement oui)" kpi="surveyed" icon="house-check" color="success" sub="Cible :" sub-kpi="target_households" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Caregivers interviewés" kpi="caregivers" icon="person-hearts" color="dark" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Enfants enrôlés (tranche d'âge éligible)" kpi="children" icon="emoji-smile" color="warning" sub="Cible :" sub-kpi="target_children" /></div>

            <div class="col-6 col-lg-2"><x-kpi-card label="Refus" kpi="refused" icon="hand-thumbs-down" color="danger" /></div>
            <div class="col-6 col-lg-2"><x-kpi-card label="Absents / pas d'accès" kpi="absent" icon="door-closed" color="secondary" /></div>
            <div class="col-6 col-lg-2"><x-kpi-card label="Réalisation ménages" kpi="rate_households" icon="bullseye" color="success" /></div>
            <div class="col-6 col-lg-2"><x-kpi-card label="Réalisation enfants" kpi="rate_children" icon="graph-up-arrow" color="warning" /></div>
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>Grappes (<span data-kpi="clusters_total">…</span>)</span><span><i class="bi bi-arrow-repeat me-1"></i>Dernière synchro : <span data-kpi="last_sync">…</span></span></div>
                        <div class="d-flex justify-content-around text-center">
                            <div><div class="kpi-value text-success" data-kpi="clusters_completed">…</div><div class="small text-muted">terminées</div></div>
                            <div><div class="kpi-value text-primary" data-kpi="clusters_started">…</div><div class="small text-muted">démarrées</div></div>
                            <div><div class="kpi-value text-secondary" data-kpi="clusters_not_started">…</div><div class="small text-muted">non démarrées</div></div>
                        </div>
                        <div class="small text-muted mt-1 text-end">Dernière soumission : <span data-kpi="last_submission">…</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12"><x-chart-card title="Évolution journalière et cumulée" canvas="chart-daily" /></div>
            <div class="col-lg-6"><x-chart-card title="Statut des visites par zone sanitaire" canvas="chart-zones" size="chart-sm" /></div>
            <div class="col-lg-6"><x-chart-card title="Statut des visites par commune" canvas="chart-communes" size="chart-sm" /></div>
        </div>

        {{-- Récapitulatif des taux de réponse : tableau à copier et coller dans PowerPoint (tableau natif). --}}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <span>Taux de réponse de l'enquête <span class="small text-muted">— tableau pour PowerPoint</span></span>
                <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-copy-table="response-table" title="Copier le tableau (à coller dans PowerPoint, Word ou Excel)">
                    <i class="bi bi-clipboard me-1"></i>Copier
                </button>
            </div>
            <div class="card-body table-responsive">
                <div id="response-table"></div>
            </div>
            <div class="card-footer small text-muted">
                Taux de réponse des ménages = ménages ayant accepté de participer / ménages avec au moins un enfant de moins de 5 ans.
                Enfants de 12 à 35 mois : enfants éligibles enrôlés. Selon les filtres du tableau de bord.
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Réalisation par zone sanitaire › commune › village › grappe</span>
                <small class="text-muted fw-normal">Cliquez sur <i class="bi bi-caret-right-fill"></i> pour déplier</small>
            </div>
            <div class="card-body p-0">
                <x-progress-table :queries-url="route('queries.index')" />
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <x-chart-card title="Productivité" canvas="chart-productivity" subtitle="Nombre moyen d'interviews par jour travaillé et durée moyenne des interviews (ménages ayant accepté).">
                    <x-slot:actions>
                        <select id="productivity-by" class="form-select form-select-sm w-auto" aria-label="Productivité par">
                            <option value="teams">Par binôme</option>
                            <option value="agents">Par agent</option>
                        </select>
                    </x-slot:actions>
                </x-chart-card>
            </div>
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header">Détail <span data-productivity-label>par binôme</span></div>
                    <div class="card-body p-0 table-responsive" style="max-height: 360px">
                        <table id="productivity-table" class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top"><tr><th data-productivity-label-head>Binôme</th><th class="text-end">Visites</th><th class="text-end">Enquêtés</th><th class="text-end">Jours</th><th class="text-end">/ jour</th><th class="text-end">Durée moy.</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Carte des ménages</span>
                <div class="d-flex align-items-center gap-2">
                    <label for="map-color" class="small text-muted mb-0">Couleur par</label>
                    <select id="map-color" class="form-select form-select-sm w-auto">
                        <option value="status">Statut (recrutés en vert)</option>
                        <option value="cluster_id">Grappe</option>
                        <option value="team_id">Binôme</option>
                        <option value="collector_id">Agent</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div id="map" class="map-box"></div>
                <p class="small text-muted mt-2 mb-0">Vert : ménages recrutés (ayant accepté l'enquête) ; rouge : autres ménages visités (refus, absents, sans enfant éligible…). Avec une couleur par grappe, binôme ou agent : points pleins = recrutés, pâles = non recrutés. Fond de carte au choix en haut à droite. 5 000 points au plus.</p>
            </div>
        </div>
    </div>
</x-layouts.app>
