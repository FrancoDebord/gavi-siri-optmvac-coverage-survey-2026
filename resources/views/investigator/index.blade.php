@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $zoneName = $zoneId ? $zones->firstWhere('id', $zoneId)?->name : null;
    $queryFilter = array_filter(['health_zone_id' => $zoneId]);
@endphp
<x-layouts.app title="Vue d'ensemble · Investigateur principal" page="investigator">
    <x-page-header title="Vue d'ensemble de la collecte" :subtitle="($zoneName ? 'Zone sanitaire de '.$zoneName : 'Toutes les zones sanitaires').' · dernière soumission : '.($kpis['last_submission'] ?? 'aucune').' · dernière synchronisation : '.($kpis['last_sync'] ?? 'jamais')">
        <form method="GET" class="d-flex align-items-center gap-2">
            <label for="pi-zone" class="small text-muted mb-0">Zone</label>
            <select id="pi-zone" name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                <option value="">Toutes</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('submissions.index', array_filter(['health_zone_id' => $zoneId])) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-house-door me-1"></i>Soumissions</a>
        <x-photo-spaces-button />
        @can('send messages')
            <a href="{{ route('messages.index') }}#nouveau" class="btn btn-sm btn-outline-primary"><i class="bi bi-chat-left-text me-1"></i>Écrire aux équipes</a>
        @endcan
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#report-modal"><i class="bi bi-file-earmark-pdf me-1"></i>Rapport</button>
    </x-page-header>

    @include('investigator._tabs')

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-2"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door">{{ $fmt($kpis['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Ménages recrutés (enquêtés)" kpi="surveyed" icon="house-check" color="success" :sub="$kpis['target_households'] ? 'Cible : '.$fmt($kpis['target_households']) : null">{{ $fmt($kpis['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Enfants éligibles" kpi="children" icon="emoji-smile" color="warning">{{ $fmt($kpis['children']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Refus / absents" kpi="refused" icon="hand-thumbs-down" color="secondary">{{ $fmt($kpis['refused']) }} / {{ $fmt($kpis['absent']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2">
            <a href="{{ route('queries.dashboard', $queryFilter) }}" class="text-decoration-none text-reset">
                <x-kpi-card label="Queries non résolues" kpi="open_queries" icon="flag" color="danger" :sub="$fmt($queryKpis['critical_open']).' critique(s)'">{{ $fmt($queryKpis['open']) }}</x-kpi-card>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a href="{{ route('incidents.index', $queryFilter) }}" class="text-decoration-none text-reset">
                <x-kpi-card label="Incidents terrain en cours" kpi="incidents" icon="exclamation-triangle" color="danger">{{ $fmt($openIncidents) }}</x-kpi-card>
            </a>
        </div>
    </div>

    {{-- Couverture et activité du jour --}}
    <div class="row g-2 mb-3 text-center small">
        @foreach ([
            ['Villages visités', $fmt($more['villages']), 'geo-alt'],
            ['Grappes visitées', $fmt($more['clusters']), 'pin-map'],
            ['Binômes actifs aujourd\'hui', $fmt($more['teams_today']).' / '.$fmt($more['teams']), 'people'],
            ['Ménages visités aujourd\'hui', $fmt($more['visited_today']), 'house-door'],
            ['Ménages enquêtés aujourd\'hui', $fmt($more['surveyed_today']), 'house-check'],
            ['Enfants éligibles aujourd\'hui', $fmt($more['children_today']), 'emoji-smile'],
        ] as [$label, $value, $icon])
            <div class="col-4 col-lg-2">
                <div class="card h-100"><div class="card-body py-2">
                    <div class="fw-semibold fs-5"><i class="bi bi-{{ $icon }} text-muted me-1 small"></i>{{ $value }}</div>
                    <div class="text-muted">{{ $label }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <script type="application/json" id="pi-charts">@json($charts)</script>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <x-chart-card title="Point journalier par binôme" canvas="chart-pi-teams" subtitle="Ménages par jour d'interview : une barre par binôme, côte à côte.">
                <x-slot:actions>
                    <select id="pi-teams-metric" class="form-select form-select-sm w-auto" aria-label="Indicateur">
                        <option value="surveyed">Ménages enquêtés</option>
                        <option value="visited">Ménages visités</option>
                    </select>
                </x-slot:actions>
            </x-chart-card>
        </div>
        <div class="col-xl-6"><x-chart-card title="Évolution par village" canvas="chart-pi-villages" subtitle="Ménages enquêtés cumulés (12 villages les plus avancés)." /></div>
        <div class="col-xl-6"><x-chart-card title="Évolution par zone sanitaire" canvas="chart-pi-zones" subtitle="Ménages enquêtés cumulés." /></div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo-alt me-1"></i>Distribution spatiale des ménages</span>
            <small class="text-muted fw-normal">{{ $fmt(count($charts['points'])) }} ménage(s) géolocalisé(s) · <span class="text-success">●</span> recrutés · <span class="text-danger">●</span> non recrutés · fond de carte au choix</small>
        </div>
        <div class="card-body">
            <div id="pi-map" class="map-box"></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-clipboard2-pulse me-1"></i>Point général des queries</span>
                    <span class="d-flex gap-2">
                        <a href="{{ route('queries.dashboard', $queryFilter) }}" class="btn btn-sm btn-outline-secondary">Dashboard queries</a>
                        <a href="{{ route('queries.index', $queryFilter + ['status' => 'non_resolues']) }}" class="btn btn-sm btn-outline-danger">Voir le détail</a>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center mb-3">
                        @foreach ([
                            ['Total', $fmt($queryKpis['total']), ''],
                            ['Non résolues', $fmt($queryKpis['open']), 'text-danger'],
                            ['Critiques ouvertes', $fmt($queryKpis['critical_open']), 'text-danger'],
                            ['Taux de résolution', $queryKpis['resolution_rate'] !== null ? number_format($queryKpis['resolution_rate'], 1, ',', ' ').' %' : '—', 'text-success'],
                            ['Pour 100 ménages', $queryKpis['per_100_households'] ?? '—', ''],
                            ['Délai moyen', $queryKpis['avg_resolution_hours'] !== null ? number_format($queryKpis['avg_resolution_hours'], 1, ',', ' ').' h' : '—', ''],
                        ] as [$label, $value, $class])
                            <div class="col-4 col-md-2"><div class="kpi-value {{ $class }}">{{ $value }}</div><div class="small text-muted">{{ $label }}</div></div>
                        @endforeach
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Sévérité</th><th class="text-end">Ouvertes</th><th class="text-end">Résolues</th></tr></thead>
                                <tbody>
                                @foreach ($querySeverity as $row)
                                    <tr><td><x-severity-badge :severity="$row['key']" /></td><td class="text-end">{{ $fmt($row['open']) }}</td><td class="text-end">{{ $fmt($row['resolved']) }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Zone</th><th class="text-end">Ouvertes</th><th class="text-end">/100 mén.</th></tr></thead>
                                <tbody>
                                @forelse ($queryZones as $row)
                                    <tr><td><a href="{{ route('queries.index', ['health_zone_id' => $row['id'], 'status' => 'non_resolues']) }}">{{ $row['name'] }}</a></td><td class="text-end">{{ $fmt($row['open']) }}</td><td class="text-end">{{ $row['rate'] ?? '—' }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Aucune query.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Binôme</th><th class="text-end">Ouvertes</th><th class="text-end">/100 mén.</th></tr></thead>
                                <tbody>
                                @forelse ($queryTeams as $row)
                                    <tr><td><a href="{{ route('queries.index', ['team_id' => $row['id'], 'status' => 'non_resolues']) }}">{{ \Illuminate\Support\Str::before($row['name'], ' · ') }}</a></td><td class="text-end">{{ $fmt($row['open']) }}</td><td class="text-end">{{ $row['rate'] ?? '—' }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Aucune query.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <h3 class="h6 mt-3 mb-2">Queries à suivre <span class="small text-muted fw-normal">(non résolues, les plus graves d'abord)</span></h3>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 small">
                            <thead class="table-light"><tr><th>Query</th><th>Sévérité</th><th>Description</th><th>Ménage</th><th class="d-none d-md-table-cell">Village</th><th class="d-none d-md-table-cell">Binôme</th><th>Statut</th></tr></thead>
                            <tbody>
                            @forelse ($followQueries as $query)
                                <tr>
                                    <td><a href="{{ route('queries.show', $query) }}">{{ $query->code }}</a></td>
                                    <td><x-severity-badge :severity="$query->severity" /></td>
                                    <td>{{ \Illuminate\Support\Str::limit($query->message, 90) }}</td>
                                    <td class="text-nowrap">{{ $query->household_code ?? '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $query->village?->name ?? '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ $query->collector?->team?->code ?? '—' }}</td>
                                    <td><x-status-badge :status="$query->status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">Aucune query non résolue.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-chat-left-text me-1"></i>Derniers messages aux équipes</span>
                    <a href="{{ route('messages.index') }}" class="small">Tous</a>
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($recentMessages as $message)
                        <div class="list-group-item small">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="badge text-bg-{{ $message->kindColor() }}"><i class="bi bi-{{ $message->kindIcon() }} me-1"></i>{{ $message->kindLabel() }}</span>
                                <span class="text-muted">{{ $message->created_at->format('d/m H:i') }}</span>
                            </div>
                            <div class="fw-semibold mt-1">{{ $message->targetLabel() }}</div>
                            <div class="text-muted">{{ \Illuminate\Support\Str::limit($message->body, 120) }}</div>
                        </div>
                    @empty
                        <div class="list-group-item small text-muted">Aucun message envoyé.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Rapport PDF --}}
    <div class="modal fade" id="report-modal" tabindex="-1" aria-labelledby="report-modal-title" aria-hidden="true">
        <div class="modal-dialog">
            <form method="GET" action="{{ route('investigator.report') }}" class="modal-content" id="report-form">
                <div class="modal-header">
                    <h2 class="modal-title h6" id="report-modal-title">Rapports (PDF)</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Rapport</label>
                        @foreach ([
                            'synthese' => ['Rapport de synthèse', 'Chiffres clés par zone, par binôme, par village, par date de collecte, puis décisions de collecte.'],
                            'etat' =>['État de la collecte', 'Indicateurs du jour et cumulés, évolution, villages et grappes, binômes, queries, incidents.'],
                            'villages' => ['Détail de tous les villages', 'Une section par village visité : grappes, binômes, évolution, queries et incidents.'],
                            'binomes' => ['Binômes – synthèse globale', 'Chaque binôme et ses agents : villages, ménages, enfants éligibles, rendement, queries.'],
                            'binomes_jour' => ['Binômes – point journalier détaillé', 'Pour chaque binôme, une ligne par jour : villages et grappes, ménages, enfants, cumuls.'],
                        ] as $key => [$label, $help])
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" id="report-type-{{ $key }}" value="{{ $key }}" @checked($loop->first)>
                                <label class="form-check-label" for="report-type-{{ $key }}"><span class="fw-semibold">{{ $label }}</span><br><span class="small text-muted">{{ $help }}</span></label>
                            </div>
                        @endforeach
                    </div>
                    <div class="mb-2" data-report-show="etat">
                        <label class="form-label small">Niveau de détail</label>
                        <div class="btn-group w-100" role="group">
                            @foreach (['tous' => 'Toutes les zones', 'zone' => 'Une zone', 'village' => 'Un village'] as $key => $label)
                                <input type="radio" class="btn-check" name="niveau" id="niveau-{{ $key }}" value="{{ $key }}" @checked($key === ($zoneId ? 'zone' : 'tous'))>
                                <label class="btn btn-outline-primary btn-sm" for="niveau-{{ $key }}">{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="mb-2" data-report-show="synthese etat:zone villages binomes binomes_jour">
                        <label for="report-zone" class="form-label small">Zone sanitaire</label>
                        <select id="report-zone" name="health_zone_id" class="form-select form-select-sm">
                            <option value="" data-optional>Toutes les zones</option>
                            @foreach ($zones as $zone)
                                <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2 mb-2" data-report-show="synthese binomes binomes_jour">
                        <div class="col-6">
                            <label for="report-from" class="form-label small">Du (facultatif)</label>
                            <input id="report-from" type="date" name="du" class="form-control form-control-sm" max="{{ now()->toDateString() }}">
                        </div>
                        <div class="col-6">
                            <label for="report-to" class="form-label small">Au (facultatif)</label>
                            <input id="report-to" type="date" name="au" class="form-control form-control-sm" max="{{ now()->toDateString() }}">
                        </div>
                    </div>
                    <div class="mb-2" data-report-show="etat:village">
                        <label for="report-village" class="form-label small">Village</label>
                        <select id="report-village" name="village_id" class="form-select form-select-sm">
                            @foreach ($villages->groupBy(fn ($v) => $zones->firstWhere('id', $v->health_zone_id)?->name) as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $village)
                                        <option value="{{ $village->id }}">{{ $village->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Télécharger le rapport</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
