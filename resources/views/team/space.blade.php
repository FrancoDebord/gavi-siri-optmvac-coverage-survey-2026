@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $total = $kpis['total'];
    $day = $kpis['today'];
    $unread = $messages->where('read_by_me', false);
@endphp
<x-layouts.app :title="'Binôme '.$team->code" page="team">
    @include('team._header')

    {{-- Messages de l'investigateur principal --}}
    @foreach ($unread as $message)
        <div class="alert alert-{{ $message->kindColor() }} d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-{{ $message->kindIcon() }} fs-5"></i>
            <div class="flex-grow-1">
                <div class="small"><strong>{{ $message->kindLabel() }}</strong> de {{ $message->sender?->name ?? 'l\'investigateur principal' }} · {{ $message->created_at->format('d/m H:i') }}</div>
                <div style="white-space: pre-line">{{ $message->body }}</div>
            </div>
            <form method="POST" action="{{ route('messages.read', $message) }}">
                @csrf
                <button class="btn btn-sm btn-light" title="Marquer comme lu"><i class="bi bi-check2"></i><span class="d-none d-md-inline ms-1">Lu</span></button>
            </form>
        </div>
    @endforeach

    {{-- KPIs : aujourd'hui et depuis le début --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-kpi-card label="Villages visités" kpi="villages" icon="geo-alt" color="dark" :sub="'Aujourd\'hui : '.$fmt($day['villages']).' · '.$fmt($total['clusters']).' grappe(s)'">{{ $fmt($total['villages']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages visités" kpi="visited" icon="house-door" :sub="'Aujourd\'hui : '.$fmt($day['visited'])">{{ $fmt($total['visited']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Ménages enquêtés" kpi="surveyed" icon="house-check" color="success" :sub="'Aujourd\'hui : '.$fmt($day['surveyed'])">{{ $fmt($total['surveyed']) }}</x-kpi-card></div>
        <div class="col-6 col-xl-3"><x-kpi-card label="Enfants éligibles (12 à 35 mois)" kpi="children" icon="emoji-smile" color="warning" :sub="'Aujourd\'hui : '.$fmt($day['children'])">{{ $fmt($total['children']) }}</x-kpi-card></div>
    </div>
    <div class="row g-2 mb-3 text-center small">
        @foreach ([
            ['Jours de collecte', $fmt($kpis['days'])],
            ['Ménages visités / jour', $kpis['per_day'] !== null ? number_format($kpis['per_day'], 1, ',', ' ') : '—'],
            ['Durée moyenne d\'interview', $total['avg_duration'] !== null ? $total['avg_duration'].' min' : '—'],
            ['Sans enfant éligible', $fmt($total['no_children'])],
            ['Refus / absents', $fmt($total['refused'])],
            ['Queries à corriger', $fmt($kpis['open_queries'])],
        ] as [$label, $value])
            <div class="col-4 col-lg-2"><div class="card h-100"><div class="card-body py-2"><div class="fw-semibold fs-5 {{ $label === 'Queries à corriger' && $kpis['open_queries'] ? 'text-danger' : '' }}">{{ $value }}</div><div class="text-muted">{{ $label }}</div></div></div></div>
        @endforeach
    </div>

    <div class="mb-3">
        <x-chart-card title="Ma progression journalière" canvas="chart-team-daily" subtitle="Ménages visités et enquêtés par jour ; courbe : ménages enquêtés en cumul." />
        <script type="application/json" id="team-daily">@json($daily)</script>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-calendar3 me-1"></i>Point journalier</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Date</th><th class="d-none d-md-table-cell">Villages (grappes)</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants</th><th class="text-end d-none d-md-table-cell">Sans enfant</th><th class="text-end d-none d-md-table-cell">Refus / abs.</th><th class="text-end d-none d-lg-table-cell">Durée moy.</th><th class="text-end">Queries</th></tr>
                </thead>
                <tbody>
                @forelse (array_reverse($daily) as $row)
                    <tr @class(['table-success' => $row['date'] === $today->toDateString()])>
                        <td class="text-nowrap">{{ $row['label'] }}</td>
                        <td class="small d-none d-md-table-cell">{{ $row['places'] }}</td>
                        <td class="text-end">{{ $fmt($row['visited']) }}</td>
                        <td class="text-end">{{ $fmt($row['surveyed']) }}</td>
                        <td class="text-end">{{ $fmt($row['children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($row['no_children']) }}</td>
                        <td class="text-end d-none d-md-table-cell">{{ $fmt($row['refused']) }}</td>
                        <td class="text-end d-none d-lg-table-cell">{{ $row['avg_duration'] !== null ? $row['avg_duration'].' min' : '—' }}</td>
                        <td class="text-end">{!! $row['open_queries'] ? '<span class="badge text-bg-danger">'.$row['open_queries'].'</span>' : '<span class="text-muted">'.$row['queries'].'</span>' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">Aucune donnée reçue pour le moment.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person me-1"></i>Par agent</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Agent</th><th class="text-end">Jours</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants</th></tr></thead>
                        <tbody>
                        @foreach ($agents as $agent)
                            <tr><td>{{ $agent['name'] }} <span class="small text-muted">({{ $agent['code'] }})</span></td><td class="text-end">{{ $agent['days'] }}</td><td class="text-end">{{ $fmt($agent['visited']) }}</td><td class="text-end">{{ $fmt($agent['surveyed']) }}</td><td class="text-end">{{ $fmt($agent['children']) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pin-map me-1"></i>Villages et grappes</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Village › grappe</th><th>Période</th><th class="text-end">Visités</th><th class="text-end">Enquêtés</th><th class="text-end">Enfants</th></tr></thead>
                        <tbody>
                        @forelse ($places as $place)
                            <tr>
                                <td>{{ $place['village'] }} › @if ($place['cluster_id'])<a href="{{ route('clusters.show', $place['cluster_id']) }}">{{ $place['cluster'] }}</a>@else — @endif</td>
                                <td class="small text-nowrap">{{ $place['first_visit'] }}{{ $place['last_visit'] !== $place['first_visit'] ? ' – '.$place['last_visit'] : '' }}</td>
                                <td class="text-end">{{ $fmt($place['visited']) }}</td><td class="text-end">{{ $fmt($place['surveyed']) }}</td><td class="text-end">{{ $fmt($place['children']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">Aucun village visité.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-flag me-1"></i>Queries à corriger ({{ $kpis['open_queries'] }})</span>
            <small class="text-muted fw-normal">Corrigez la soumission dans ODK Collect / ODK Central, ou signalez la justification à votre superviseur.</small>
        </div>
        <div class="list-group list-group-flush">
            @forelse ($queries as $query)
                <div class="list-group-item">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span><x-severity-badge :severity="$query->severity" /> <a href="{{ route('queries.show', $query) }}" class="fw-semibold">{{ $query->code }}</a> · ménage {{ $query->household_code ?? '—' }}</span>
                        <span class="small text-muted">{{ $query->collection_date?->format('d/m/Y') }} · {{ $query->collector?->name }}</span>
                    </div>
                    <div class="mt-1">{{ $query->message }}</div>
                    @if ($query->suggestion)<div class="small text-muted mt-1"><i class="bi bi-lightbulb me-1"></i>{{ $query->suggestion }}</div>@endif
                </div>
            @empty
                <div class="list-group-item text-muted"><i class="bi bi-check-circle text-success me-1"></i>Aucune query à corriger. Bravo !</div>
            @endforelse
        </div>
    </div>

    @if ($messages->where('read_by_me', true)->isNotEmpty())
        <div class="card">
            <div class="card-header"><i class="bi bi-chat-left-text me-1"></i>Messages déjà lus</div>
            <div class="list-group list-group-flush small">
                @foreach ($messages->where('read_by_me', true) as $message)
                    <div class="list-group-item"><span class="badge text-bg-{{ $message->kindColor() }} me-1">{{ $message->kindLabel() }}</span>{{ $message->created_at->format('d/m H:i') }} · {{ $message->body }}</div>
                @endforeach
            </div>
        </div>
    @endif
</x-layouts.app>
