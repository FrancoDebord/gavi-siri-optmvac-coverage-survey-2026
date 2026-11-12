@php($fmt = fn ($n) => number_format((int) $n, 0, ',', ' '))
<x-layouts.app title="Soumissions" page="submissions">
    <x-page-header title="Soumissions" :subtitle="'Détail des ménages visités, sur les contours des grappes et sous-grappes · '.$fmt($totals->n).' ménage(s) pour ces filtres, dont '.$fmt($totals->surveyed).' enquêté(s)'" />

    <form method="GET" class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                @unless ($options['scoped'])
                    <div class="col-6 col-md-2">
                        <label for="f-zone" class="form-label small mb-0">Zone</label>
                        <select id="f-zone" name="health_zone_id" class="form-select form-select-sm">
                            <option value="">Toutes</option>
                            @foreach ($options['zones'] as $zone)
                                <option value="{{ $zone->id }}" @selected((int) request('health_zone_id') === $zone->id)>{{ $zone->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
                @foreach ([
                    ['village_id', 'Village', $options['villages'], fn ($v) => $v->name, 'Tous'],
                    ['cluster_id', 'Grappe', $options['clusters'], fn ($c) => $c->code, 'Toutes'],
                    ['team_id', 'Binôme', $options['teams'], fn ($t) => $t->label(), 'Tous'],
                    ['collector_id', 'Agent', $options['collectors'], fn ($c) => $c->name.' ('.$c->code.')', 'Tous'],
                ] as [$name, $label, $groups, $text, $all])
                    <div class="col-6 col-md-2">
                        <label for="f-{{ $name }}" class="form-label small mb-0">{{ $label }}</label>
                        <select id="f-{{ $name }}" name="{{ $name }}" class="form-select form-select-sm">
                            <option value="">{{ $all }}</option>
                            @foreach ($groups as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected((int) request($name) === $item->id)>{{ $text($item) }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="col-6 col-md-2">
                    <label for="f-statut" class="form-label small mb-0">Statut</label>
                    <select id="f-statut" name="statut" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="enquetes" @selected(request('statut') === 'enquetes')>Enquêtés (recrutés)</option>
                        <option value="non_enquetes" @selected(request('statut') === 'non_enquetes')>Non enquêtés</option>
                        <option value="avec_queries" @selected(request('statut') === 'avec_queries')>Avec queries à corriger</option>
                        <option value="sans_gps" @selected(request('statut') === 'sans_gps')>Sans GPS</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-from" class="form-label small mb-0">Du</label>
                    <input id="f-from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-to" class="form-label small mb-0">Au</label>
                    <input id="f-to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-q" class="form-label small mb-0">ID ménage</label>
                    <input id="f-q" type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Ex. DAG-ASS-01">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtrer</button>
                    <a href="{{ route('submissions.index') }}" class="btn btn-sm btn-light">Effacer</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button class="btn btn-link p-0 text-reset text-decoration-none fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#submissions-map-card" aria-expanded="true" aria-controls="submissions-map-card">
                <i class="bi bi-map me-1"></i>Carte des ménages et contours des grappes <i class="bi bi-chevron-down small"></i>
            </button>
            <small class="text-muted fw-normal">
                <span class="text-success">●</span> recrutés · <span class="text-danger">●</span> non recrutés · <span style="color:#1B3A6B">━</span> grappe · <span style="color:#E8A33D">┅</span> sous-grappes
                · {{ count($mapData['boundaries']) }} grappe(s) avec contour
                @if ($mapLimited) · <span class="text-warning">{{ $fmt(count($mapData['points'])) }} ménages les plus récents affichés : affinez les filtres</span>@endif
            </small>
        </div>
        <div id="submissions-map-card" class="collapse show">
            <div class="card-body">
                <div id="submissions-map" class="map-box" data-map='@json($mapData)'></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Ménage</th><th>Date</th><th class="d-none d-lg-table-cell">Zone</th><th>Village › grappe</th><th class="d-none d-md-table-cell">Binôme · agent</th><th>Statut</th><th class="text-end">Enfants</th><th class="text-end">Queries</th><th>Position / contour</th></tr>
                </thead>
                <tbody>
                @forelse ($households as $household)
                    @php($position = $positions[$household->id] ?? null)
                    <tr>
                        <td><a href="{{ route('households.show', $household) }}" class="fw-semibold">{{ $household->household_code ?? '—' }}</a></td>
                        <td class="small text-nowrap">{{ $household->interview_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="small d-none d-lg-table-cell">{{ $household->healthZone?->name ?? '—' }}</td>
                        <td class="small">
                            {{ $household->village?->name ?? '—' }} ›
                            @if ($household->cluster)<a href="{{ route('clusters.show', $household->cluster) }}">{{ $household->cluster->code }}</a>@else — @endif
                            @if ($household->sub_cluster)<span class="text-muted"> · S{{ $household->sub_cluster }}</span>@endif
                        </td>
                        <td class="small d-none d-md-table-cell">{{ $household->collector?->team ? $household->collector->team->code.' · ' : '' }}{{ $household->collector?->name ?? '—' }}</td>
                        <td><span class="badge {{ $household->consented ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $statusLabel($household->survey_status) }}</span></td>
                        <td class="text-end">{{ $household->eligible_children }}</td>
                        <td class="text-end">{!! $household->open_queries ? '<span class="badge text-bg-danger">'.$household->open_queries.'</span>' : '<span class="text-muted">0</span>' !!}</td>
                        <td class="small">
                            @if ($household->latitude === null)
                                <span class="badge text-bg-danger"><i class="bi bi-geo-alt me-1"></i>sans GPS</span>
                            @elseif ($position)
                                <x-boundary-position :position="$position['cluster']" label="grappe" />
                                @if ($household->sub_cluster && $position['sub_cluster'])<x-boundary-position :position="$position['sub_cluster']" :label="'S'.$household->sub_cluster" />@endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucune soumission pour ces filtres.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($households->hasPages())
            <div class="card-footer">{{ $households->links() }}</div>
        @endif
    </div>
</x-layouts.app>
