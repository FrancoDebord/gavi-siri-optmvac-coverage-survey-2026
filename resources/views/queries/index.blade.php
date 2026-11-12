<x-layouts.app title="Liste des queries" page="queries-list">
    <div id="queries-list" data-url="{{ route('queries.datatable') }}" data-bulk-url="{{ route('queries.bulk') }}" data-can-process="{{ auth()->user()->can('process queries') ? '1' : '0' }}">
        <x-page-header title="Liste des queries" :subtitle="$lastRun ? 'Dernière exécution du moteur : '.$lastRun->started_at->format('d/m/Y H:i').' ('.($lastRun->status === 'success' ? $lastRun->households_checked.' ménages contrôlés' : 'échec').')' : 'Le moteur n\'a pas encore été exécuté.'">
            @can('run quality checks')
                <form method="POST" action="{{ route('queries.run') }}" data-confirm="Relancer le moteur de queries sur toutes les soumissions ?">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle me-1"></i>Relancer le moteur</button>
                </form>
            @endcan
            @can('export data')
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#export-modal"><i class="bi bi-download me-1"></i>Exporter</button>
            @endcan
        </x-page-header>

        <x-filter-bar storage-key="queries-list" date-label="Date de collecte" :sources="false">
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-resolution">Statut de résolution</label>
                <select id="f-resolution" class="form-select form-select-sm" data-filter="resolution">
                    <option value="">Tous</option>
                    @foreach (\App\Models\DataQuery::RESOLUTIONS as $key => $resolution)
                        <option value="{{ $key }}">{{ $resolution['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-status">Statut détaillé</label>
                <select id="f-status" class="form-select form-select-sm" data-filter="status">
                    <option value="">Tous</option>
                    @foreach (\App\Models\DataQuery::STATUSES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
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
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-variable">Variable</label>
                <input id="f-variable" class="form-control form-control-sm" data-filter="variable" list="variables-list" placeholder="ex. Penta1date">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-code">Code query</label>
                <input id="f-code" class="form-control form-control-sm" data-filter="code" placeholder="Q-000123">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-gen-from">Générée : du</label>
                <input id="f-gen-from" type="date" class="form-control form-control-sm" data-filter="generated_from">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-0" for="f-gen-to">au</label>
                <input id="f-gen-to" type="date" class="form-control form-control-sm" data-filter="generated_to">
            </div>
        </x-filter-bar>
        <datalist id="variables-list">
            @foreach ($variables as $variable)<option value="{{ $variable }}">@endforeach
        </datalist>

        <div id="bulk-bar" class="alert alert-primary d-flex align-items-center justify-content-between py-2 d-none">
            <span><strong data-count>0</strong> query(s) sélectionnée(s)</span>
            <button id="bulk-status" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square me-1"></i>Changer le statut / commenter</button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="queries-table" class="table table-sm table-hover w-100">
                        <thead class="table-light">
                        <tr>
                            <th>@can('process queries')<input type="checkbox" id="select-all" class="form-check-input" aria-label="Tout sélectionner">@endcan</th>
                            <th>Code</th><th>Libellé et description</th><th>Variable</th><th>Ménage</th><th>Zone</th><th>Commune</th><th>Village</th><th>Grappe</th><th>Agent</th>
                            <th>Collecte</th><th>Générée</th><th>Sévérité</th><th>Résolution</th><th></th>
                        </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @can('export data')
        <div class="modal fade" id="export-modal" tabindex="-1" aria-labelledby="export-modal-title" aria-hidden="true">
            <div class="modal-dialog">
                <form id="export-form" class="modal-content" action="{{ route('queries.export') }}">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="export-modal-title">Exporter les queries</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">L'export reprend <strong>tous les filtres affichés</strong> (zone, commune, village, grappe, binôme, agent, dates, statut de résolution, sévérité, règle, variable). La première feuille rappelle les critères appliqués.</p>
                        <div class="mb-3">
                            <label class="form-label" for="export-group">Regrouper par</label>
                            <select id="export-group" name="group_by" class="form-select">
                                @foreach (\App\Exports\QueriesExport::GROUPS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Excel : un récapitulatif puis une feuille par groupe (par exemple une feuille par binôme pour le retour terrain).</div>
                        </div>
                        <div>
                            <span class="form-label d-block">Format</span>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="format" id="format-xlsx" value="xlsx" checked>
                                <label class="form-check-label" for="format-xlsx">Excel (.xlsx)</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="format" id="format-csv" value="csv">
                                <label class="form-check-label" for="format-csv">CSV (; – une seule table)</label>
                            </div>
                        </div>
                        <p class="small text-muted mt-3 mb-0">Colonnes : code, libellé, description de l'erreur, suggestion de résolution, variable et question, valeurs observée/attendue, règle, sévérité, statut de résolution, binôme, agent, ID ménage, zone sanitaire, commune, village, grappe, dates de collecte, de génération et de résolution, dernier commentaire.</p>
                    </div>
                    <div class="modal-footer">
                        <span class="small text-muted me-auto" data-export-count></span>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button class="btn btn-primary"><i class="bi bi-download me-1"></i>Télécharger</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @can('process queries')
        <div class="modal fade" id="status-modal" tabindex="-1" aria-labelledby="status-modal-title" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="status-modal-title">Traiter <span data-selection-count>1</span> query(s)</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="modal-status">Nouveau statut</label>
                            <select id="modal-status" name="status" class="form-select">
                                <option value="">— Ne pas changer (commentaire seul) —</option>
                                @foreach (\App\Models\DataQuery::STATUSES as $key => $label)
                                    <option value="{{ $key }}" data-from='@json(collect(\App\Models\DataQuery::TRANSITIONS)->filter(fn ($to) => in_array($key, $to, true))->keys()->values())'>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">« Justifiée » et « Irrésoluble » exigent un commentaire. Les transitions impossibles sont ignorées.</div>
                        </div>
                        <div>
                            <label class="form-label" for="modal-comment">Commentaire</label>
                            <textarea id="modal-comment" name="comment" rows="3" class="form-control" maxlength="2000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
</x-layouts.app>
