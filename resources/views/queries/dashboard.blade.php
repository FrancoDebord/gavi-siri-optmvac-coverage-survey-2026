<x-layouts.app title="Dashboard des queries" page="queries-dashboard">
    <div id="queries-dashboard" data-url="{{ route('queries.dashboard.data') }}" data-list-url="{{ route('queries.index') }}" data-refresh="{{ $refresh }}">
        <x-page-header title="Dashboard des queries" subtitle="Contrôle qualité : anomalies détectées et suivi de leur résolution">
            <span class="small text-muted align-self-center">Actualisé à <span data-generated-at>…</span></span>
            @can('export data')
                <a href="{{ route('queries.export') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Exporter (filtres actuels)</a>
                <a href="{{ route('queries.export.collectors') }}" data-export class="btn btn-sm btn-outline-primary"><i class="bi bi-people me-1"></i>Excel par binôme</a>
                <a href="{{ route('queries.report.daily') }}" data-export class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="Date de collecte = date de fin du filtre, sinon la veille"><i class="bi bi-file-earmark-pdf me-1"></i>Rapport quotidien</a>
            @endcan
            <a href="{{ route('queries.index') }}" class="btn btn-sm btn-primary"><i class="bi bi-list-check me-1"></i>Liste détaillée</a>
        </x-page-header>

        <x-filter-bar storage-key="queries" date-label="Date de collecte" :sources="false">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-severity">Sévérité</label>
                <select id="f-severity" class="form-select form-select-sm" data-filter="severity">
                    <option value="">Toutes</option>
                    @foreach (\App\Models\QualityRule::SEVERITIES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-rule">Règle</label>
                <select id="f-rule" class="form-select form-select-sm" data-filter="rule_code">
                    <option value="">Toutes</option>
                    @foreach ($rules as $rule)
                        <option value="{{ $rule->code }}">{{ $rule->code }} – {{ \Illuminate\Support\Str::limit($rule->name, 40) }}</option>
                    @endforeach
                </select>
            </div>
        </x-filter-bar>

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><x-kpi-card label="Queries (total)" kpi="total" icon="clipboard2-data" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Non résolues (ouvertes + envoyées)" kpi="open" icon="exclamation-octagon" color="danger" sub="Dont critiques :" sub-kpi="critical_open" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Résolues" kpi="resolved" icon="check2-circle" color="success" sub="Taux de résolution :" sub-kpi="resolution_rate" /></div>
            <div class="col-6 col-lg-3"><x-kpi-card label="Queries pour 100 ménages" kpi="per_100" icon="percent" color="warning" sub="Délai moyen de résolution :" sub-kpi="avg_resolution" /></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-4"><x-chart-card title="Par sévérité" canvas="chart-severity" size="chart-sm" /></div>
            <div class="col-lg-4"><x-chart-card title="Par statut" canvas="chart-status" size="chart-sm" /></div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">Clôtures</div>
                    <div class="card-body d-flex flex-column justify-content-center text-center gap-3">
                        <div>
                            <div class="display-6 fw-bold text-success" data-kpi="auto_closed">…</div>
                            <p class="text-muted small mb-0">fermées automatiquement : donnée corrigée lors d'une synchronisation suivante.</p>
                        </div>
                        <div>
                            <div class="display-6 fw-bold text-primary" data-kpi="unresolvable">…</div>
                            <p class="text-muted small mb-0">irrésolubles : la donnée ne peut être ni corrigée ni confirmée.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6"><x-chart-card title="Top 10 des règles" canvas="chart-rules" /></div>
            <div class="col-lg-6"><x-chart-card title="Variables les plus concernées" canvas="chart-variables" /></div>
        </div>

        <div class="card mb-3">
            <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-tabs px-2 pt-2" role="tablist">
                    @foreach (['zone' => 'Zone sanitaire', 'commune' => 'Commune', 'village' => 'Village', 'cluster' => 'Grappe', 'team' => 'Binôme', 'collector' => 'Agent'] as $key => $label)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-{{ $key }}" type="button" role="tab">{{ $label }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">
                    @foreach (['zone', 'commune', 'village', 'cluster', 'team', 'collector'] as $key)
                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="tab-{{ $key }}" role="tabpanel">
                            <div class="table-responsive" style="max-height: 420px">
                                <table id="ranking-{{ $key }}" class="table table-sm table-hover mb-0">
                                    <thead class="table-light sticky-top"><tr><th>Nom</th><th class="text-end">Total</th><th class="text-end">Non résolues</th><th class="text-end">Critiques ouvertes</th><th class="text-end">Ménages</th><th class="text-end">Queries / 100 ménages</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Heatmap binôme × jour de collecte (nombre de queries)</div>
            <div class="card-body table-responsive">
                <table id="heatmap" class="table table-sm table-bordered heatmap mb-0"></table>
            </div>
        </div>
    </div>
</x-layouts.app>
