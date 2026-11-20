<x-layouts.app title="Villages des binômes">
    <x-page-header title="Villages des binômes" subtitle="Ménages enquêtés hors des villages du binôme, ou avec l'agent d'un autre binôme que celui du compte ODK. Chaque cas est une query à traiter avec les binômes.">
        <a href="{{ route('queries.index', ['rule_code' => 'TEAM_VILLAGE']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-check me-1"></i>Traiter dans la liste des queries</a>
    </x-page-header>

    @unless ($listed)
        <div class="alert alert-warning">Aucune liste de villages par binôme n'est chargée : déposez <code>binomes_villages.csv</code> avec les réglages puis rechargez le référentiel.</div>
    @endunless

    <div class="row g-2 mb-3">
        @foreach ($cases as $key => $label)
            <div class="col-6 col-lg">
                <a href="{{ route('team-villages.index', array_filter(['cas' => $key, 'health_zone_id' => $filters['health_zone_id']])) }}" class="card text-decoration-none h-100 {{ $filters['cas'] === $key ? 'border-primary' : '' }}">
                    <div class="card-body py-2">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ $counts[$key] ?? 0 }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
            <label class="form-label small mb-0" for="tv-q">N° query, ID ménage ou texte</label>
            <input id="tv-q" type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="ex. Q-000123, TCH-WAR-04">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0" for="tv-zone">Zone sanitaire</label>
            <select id="tv-zone" name="health_zone_id" class="form-select form-select-sm">
                <option value="">Toutes</option>
                @foreach ($zones as $zone)<option value="{{ $zone->id }}" @selected($filters['health_zone_id'] === $zone->id)>{{ $zone->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0" for="tv-cas">Diagnostic</label>
            <select id="tv-cas" name="cas" class="form-select form-select-sm">
                <option value="">Tous</option>
                @foreach ($cases as $key => $label)<option value="{{ $key }}" @selected($filters['cas'] === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-0" for="tv-res">Résolution</label>
            <select id="tv-res" name="resolution" class="form-select form-select-sm">
                @foreach (\App\Models\DataQuery::RESOLUTIONS as $key => $group)<option value="{{ $key }}" @selected($resolution === $key)>{{ $group['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filtrer</button></div>
    </form>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>{{ $rows->count() }} ménage(s)</span>
            <small class="text-muted fw-normal">Binôme réel = celui du compte ODK s'il est connu, sinon celui de l'agent sélectionné.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light"><tr>
                    <th>Query</th><th>ID ménage</th><th>Date</th><th>Compte ODK</th><th>Binôme du compte</th><th>Agent sélectionné (binôme)</th>
                    <th>Village coché</th><th>Village selon l'ID</th><th>Diagnostic</th><th>Statut</th><th>Assignée à</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    @php($q = $row['query'])
                    @php($d = $row['diagnosis'])
                    <tr>
                        <td class="text-nowrap"><a href="{{ route('queries.show', $q) }}" class="fw-semibold">{{ $q->code }}</a></td>
                        <td class="text-nowrap"><code>{{ $q->household_code }}</code></td>
                        <td class="text-nowrap">{{ $q->collection_date?->format('d/m/Y') }}</td>
                        <td>{{ $q->household?->submitter ?? '—' }}</td>
                        <td>{{ $d ? \App\Services\Quality\TeamVillageCheck::teamLabel($d['account_team']) : '—' }}</td>
                        <td>{{ $d ? \App\Services\Quality\TeamVillageCheck::teamLabel($d['declared_team']) : '—' }}</td>
                        <td>{{ $d['village']?->name ?? $q->household?->village?->name ?? '—' }}</td>
                        <td>{{ $d['id_village']?->name ?? '—' }}</td>
                        <td>
                            @if ($d)
                                <span class="badge text-bg-{{ ['village' => 'warning', 'agent' => 'info', 'agent_village' => 'primary', 'hors_villages' => 'danger'][$d['case']] ?? 'secondary' }}">{{ $d['case_label'] }}</span>
                                @if ($d['propose_village'])<div class="text-muted">proposé : {{ $d['propose_village']->name }}</div>@endif
                            @else
                                <span class="badge text-bg-success">Plus d'incohérence</span>
                            @endif
                        </td>
                        <td><x-status-badge :status="$q->status" /></td>
                        <td>{{ $q->assignee?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">Aucun cas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3 border-warning-subtle" id="villages-restants">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hourglass-split me-1"></i>Villages restants : aucun ménage encore visité</span>
            <span class="badge text-bg-warning">{{ $remaining->flatten(1)->count() }} village(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Village</th><th>Binôme(s) concerné(s)</th><th class="text-end">Grappes prévues</th><th class="text-end">Ménages dénombrés</th></tr></thead>
                <tbody>
                @forelse ($remaining as $zoneName => $items)
                    <tr class="table-light"><th colspan="4">{{ $zoneName }} <span class="fw-normal text-muted">· {{ $items->count() }} village(s)</span></th></tr>
                    @foreach ($items as $item)
                        <tr>
                            <td class="fw-semibold">{{ $item['village'] }}</td>
                            <td>{!! $item['teams'] ? e(implode(' · ', $item['teams'])) : '<span class="text-danger">aucun binôme attribué</span>' !!}</td>
                            <td class="text-end">{{ $item['clusters'] ?: '—' }}</td>
                            <td class="text-end">{{ $item['enumerated'] ? number_format($item['enumerated'], 0, ',', ' ') : '—' }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="4" class="text-center text-success py-3"><i class="bi bi-check-circle me-1"></i>Tous les villages ont au moins un ménage visité.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Villages de chaque binôme</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Binôme</th><th>Compte(s) ODK</th><th>Villages attribués</th></tr></thead>
                <tbody>
                @forelse ($teamLists as $zoneName => $items)
                    <tr class="table-light"><th colspan="3">{{ $zoneName }}</th></tr>
                    @foreach ($items as $item)
                        <tr>
                            <td class="text-nowrap fw-semibold">{{ $item['label'] }}</td>
                            <td class="text-nowrap">{{ $item['accounts'] ? implode(', ', $item['accounts']) : '—' }}</td>
                            <td>
                                @forelse ($item['villages'] as $name)<span class="badge text-bg-light border fw-normal me-1 mb-1">{{ $name }}</span>@empty<span class="text-muted">Aucun village attribué</span>@endforelse
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="3" class="text-center text-muted py-3">Aucun binôme.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Comptes ODK et binômes</div>
        <div class="card-body small text-muted pb-0">Un compte est rattaché au binôme sélectionné dans au moins 80 % de ses soumissions (10 au moins). Liste des villages : <code>{{ basename($file) }}</code>.</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Compte ODK</th><th>Soumissions</th><th>Binôme</th><th>Part</th></tr></thead>
                <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <td>{{ $account['name'] }}</td>
                        <td>{{ $account['total'] }}</td>
                        <td>{!! $account['team'] ? e(\App\Services\Quality\TeamVillageCheck::teamLabel($account['team'])) : '<span class="text-warning-emphasis">non rattaché (plusieurs binômes)</span>' !!}</td>
                        <td>{{ round($account['share'] * 100) }} %</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
