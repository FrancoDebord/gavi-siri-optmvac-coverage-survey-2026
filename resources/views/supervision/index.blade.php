@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $queriesUrl = fn (array $filter) => route('queries.index', $filter + ['status' => 'non_resolues']);
    $pairs = $teams->whereNotNull('id');
    $activeTeams = $pairs->where('visited', '>', 0)->count();
@endphp
<x-layouts.app :title="($scoped ? 'Ma zone · ' : 'Supervision · ').$title" page="supervision">
    <x-page-header :title="($scoped ? 'Ma zone : ' : 'Supervision : ').$title"
                   :subtitle="'Situation au '.$today->format('d/m/Y').' · dernière soumission reçue : '.($kpis['last_submission'] ?? 'aucune')">
        @unless ($scoped)
            <form method="GET" class="d-flex align-items-center gap-2">
                <label for="zone" class="small text-muted mb-0">Zone</label>
                <select id="zone" name="health_zone_id" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    @foreach ($zones as $zone)
                        <option value="{{ $zone->id }}" @selected($zone->id === $zoneId)>{{ $zone->name }}</option>
                    @endforeach
                </select>
            </form>
        @endunless
        <x-photo-spaces-button />
        <x-document-spaces-button />
        @can('report incidents')
            <a href="{{ route('incidents.create') }}" class="btn btn-sm btn-danger"><i class="bi bi-exclamation-triangle me-1"></i>Signaler un incident</a>
        @endcan
    </x-page-header>

    @can('manage sources')<x-queue-warning />@endcan

    @foreach ($messages as $message)
        <div class="alert alert-{{ $message->kindColor() }} d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-{{ $message->kindIcon() }} fs-5"></i>
            <div class="flex-grow-1">
                <div class="small"><strong>{{ $message->kindLabel() }} de {{ $message->sender?->name ?? 'l\'investigateur principal' }}</strong> · {{ $message->targetLabel() }} · {{ $message->created_at->format('d/m H:i') }}</div>
                <div style="white-space: pre-line">{{ $message->body }}</div>
            </div>
            <form method="POST" action="{{ route('messages.read', $message) }}">
                @csrf
                <button class="btn btn-sm btn-light" title="Marquer comme lu"><i class="bi bi-check2"></i><span class="d-none d-md-inline ms-1">Lu</span></button>
            </form>
        </div>
    @endforeach

    {{-- Aujourd'hui / cumul --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-2"><x-kpi-card label="Ménages visités aujourd'hui" kpi="visited_today" icon="house-door" :sub="'Cumul : '.$fmt($kpis['visited'])">{{ $fmt($kpis['visited_today']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Ménages enquêtés aujourd'hui" kpi="surveyed_today" icon="house-check" color="success" :sub="'Cumul : '.$fmt($kpis['surveyed'])">{{ $fmt($kpis['surveyed_today']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Enfants éligibles aujourd'hui" kpi="children_today" icon="emoji-smile" color="warning" :sub="'Cumul : '.$fmt($kpis['children'])">{{ $fmt($kpis['children_today']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2"><x-kpi-card label="Binômes actifs aujourd'hui" kpi="teams_today" icon="people" color="dark" :sub="'Sur '.$pairs->count().' binôme(s) · '.$fmt($kpis['collectors_today']).' agent(s) actif(s)'">{{ $fmt($kpis['teams_today']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-2">
            <a href="{{ $queriesUrl($incidentFilter) }}" class="text-decoration-none text-reset">
                <x-kpi-card label="Queries à traiter" kpi="open_queries" icon="flag" color="danger" sub="Voir la liste">{{ $fmt($kpis['open_queries']) }}</x-kpi-card>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a href="{{ route('incidents.index') }}" class="text-decoration-none text-reset">
                <x-kpi-card label="Incidents non résolus" kpi="open_incidents" icon="exclamation-triangle" color="danger" sub="Voir le suivi">{{ $fmt($kpis['open_incidents']) }}</x-kpi-card>
            </a>
        </div>
    </div>

    @if ($ruleCounts->isNotEmpty())
        {{-- Queries à traiter de la zone par code de règle : ouvre la liste filtrée sur ce code. --}}
        <form method="GET" action="{{ route('queries.index') }}" class="card mb-3">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                @foreach ($incidentFilter + ['status' => 'non_resolues'] as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label class="small fw-semibold mb-0" for="sup-rule"><i class="bi bi-funnel me-1"></i>Queries à traiter par code</label>
                <select id="sup-rule" name="rule_code" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    <option value="">Tous les codes ({{ $fmt($ruleCounts->sum()) }})</option>
                    @foreach ($ruleCounts as $code => $n)
                        <option value="{{ $code }}">{{ $code }} – {{ \Illuminate\Support\Str::limit($ruleNames[$code] ?? $code, 40) }} ({{ $fmt($n) }})</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-outline-primary">Voir</button>
            </div>
        </form>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <x-chart-card title="Évolution de la collecte" canvas="chart-supervision-daily" size="chart-sm"
                          :subtitle="'Ménages visités et enquêtés par jour ; courbe : ménages enquêtés en cumul. Refus : '.$fmt($kpis['refused']).' · absents / pas d\'accès : '.$fmt($kpis['absent']).' · sans enfant éligible : '.$fmt($kpis['no_children']).'.'" />
            <script type="application/json" id="supervision-daily">@json($daily)</script>
        </div>
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle me-1"></i>Incidents en cours</span>
                    <a href="{{ route('incidents.index') }}" class="small">Tout le suivi</a>
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($incidents as $incident)
                        <a href="{{ route('incidents.show', $incident) }}" class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-semibold"><i class="bi bi-{{ $incident->categoryIcon() }} me-1"></i>{{ $incident->title }}</span>
                                <span class="badge text-bg-{{ $incident->severityColor() }} align-self-start">{{ $incident->severityLabel() }}</span>
                            </div>
                            <div class="small text-muted">
                                {{ $incident->code }} · {{ $incident->occurred_on->format('d/m') }}
                                @if ($incident->village) · {{ $incident->village->name }}@endif
                                @if ($incident->cluster) · {{ $incident->cluster->code }}@endif
                                · <span class="text-{{ $incident->statusColor() }}">{{ $incident->statusLabel() }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="list-group-item text-muted small">Aucun incident en cours.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Binômes --}}
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-people me-1"></i>Performance des binômes</span>
            <small class="text-muted fw-normal">{{ $activeTeams }} binôme(s) ayant envoyé des données sur {{ $pairs->count() }} · total du binôme, puis détail par agent</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Binôme › agent</th>
                    <th class="text-end">Aujourd'hui</th>
                    <th class="text-end">Visités</th>
                    <th class="text-end">Enquêtés</th>
                    <th class="text-end d-none d-md-table-cell">Sans enfant</th>
                    <th class="text-end d-none d-md-table-cell">Refus / absents</th>
                    <th class="text-end d-none d-lg-table-cell">Jours</th>
                    <th class="text-end">Visites / jour</th>
                    <th class="text-end d-none d-lg-table-cell">Durée moy.</th>
                    <th class="text-end">Queries</th>
                    <th class="d-none d-md-table-cell">Dernier envoi</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($teams as $team)
                    <tr @class(['fw-semibold', 'table-light', 'text-muted' => $team->visited === 0])>
                        <td>
                            <i class="bi bi-people-fill me-1"></i>{{ $team->code ? 'Binôme '.$team->code : $team->name }}
                            @if ($team->code)<span class="fw-normal small text-muted">· {{ $team->name }}</span>@endif
                        </td>
                        @include('supervision._performance-cells', ['row' => $team, 'queriesHref' => null])
                    </tr>
                    @foreach ($team->agents as $agent)
                        <tr @class(['small', 'text-muted' => $agent->visited === 0])>
                            <td class="ps-4">{{ $agent->name }} <span class="text-muted">({{ $agent->code }})</span></td>
                            @include('supervision._performance-cells', ['row' => $agent, 'queriesHref' => $queriesUrl(['collector_id' => $agent->id])])
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="11" class="text-center text-muted">Aucun binôme dans cette zone.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Villages et grappes --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-pin-map me-1"></i>Villages et grappes</span>
            <small class="text-muted fw-normal">Cliquez sur une grappe pour voir sa carte et ses ménages</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Village › grappe</th>
                    <th class="text-end d-none d-md-table-cell">Sous-grappes</th>
                    <th class="text-end">Visités</th>
                    <th class="text-end">Enquêtés</th>
                    <th class="text-end d-none d-md-table-cell">Sans enfant</th>
                    <th class="text-end d-none d-md-table-cell">Refus / absents</th>
                    <th class="text-end">Enfants</th>
                    <th class="text-end">Queries</th>
                    <th class="d-none d-md-table-cell">Dernière visite</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($villages as $village)
                    <tr class="table-light fw-semibold">
                        <td><i class="bi bi-geo-alt me-1"></i>{{ $village->name }}</td>
                        <td class="d-none d-md-table-cell"></td>
                        <td class="text-end">{{ $fmt($village->totals['visited']) }}</td>
                        <td class="text-end">{{ $fmt($village->totals['surveyed']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($village->totals['no_children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($village->totals['refused']) }}</td>
                        <td class="text-end">{{ $fmt($village->totals['children']) }}</td>
                        <td class="text-end">{{ $fmt($village->totals['open_queries']) }}</td>
                        <td class="d-none d-md-table-cell"></td>
                    </tr>
                    @foreach ($village->clusters as $cluster)
                        <tr>
                            <td class="ps-4"><a href="{{ route('clusters.show', $cluster->id) }}">{{ $cluster->label }}</a> <span class="small text-muted">{{ $cluster->code }}</span></td>
                            <td class="text-end d-none d-md-table-cell">{{ $cluster->sub_clusters ?: '—' }}</td>
                            <td class="text-end">{{ $fmt($cluster->visited) }}</td>
                            <td class="text-end">
                                {{ $fmt($cluster->surveyed) }}
                                @if ($cluster->target)<span class="small text-muted">/ {{ $cluster->target }} ({{ $cluster->rate }} %)</span>@endif
                            </td>
                            <td class="text-end d-none d-md-table-cell">{{ $fmt($cluster->no_children) }}</td>
                            <td class="text-end d-none d-md-table-cell">{{ $fmt($cluster->refused) }}</td>
                            <td class="text-end">{{ $fmt($cluster->children) }}</td>
                            <td class="text-end">
                                @if ($cluster->open_queries)
                                    <a href="{{ $queriesUrl(['cluster_id' => $cluster->id]) }}" class="badge text-bg-danger text-decoration-none">{{ $cluster->open_queries }}</a>
                                @else
                                    <span class="text-muted">0</span>
                                @endif
                            </td>
                            <td class="small d-none d-md-table-cell">{{ $cluster->last_visit?->format('d/m/Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="9" class="text-center text-muted">Aucune grappe visitée pour le moment.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
