<x-layouts.app title="Rapport des binômes">
    @php($query = array_filter($filters))
    <x-page-header title="Rapport d'activité des binômes"
                   :subtitle="$from ? 'Du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y').' · '.$reports->count().' binôme(s) · d\'après la date d\'entretien des ménages' : 'Aucune donnée de collecte'">
        @if ($from && $reports->isNotEmpty())
            <a href="{{ route('team-activity.pdf', $query) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-file-earmark-pdf me-1"></i>{{ $filters['team_id'] ? 'PDF de ce binôme' : 'PDF de tous les binômes'.($filters['health_zone_id'] ? ' de la zone' : '') }}
            </a>
        @endif
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="health_zone_id" class="form-label small mb-1">Zone sanitaire</label>
                    <select id="health_zone_id" name="health_zone_id" class="form-select form-select-sm" onchange="this.form.team_id.value = ''; this.form.submit()">
                        <option value="">Toutes les zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected($zone->id === $filters['health_zone_id'])>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="team_id" class="form-label small mb-1">Binôme</label>
                    <select id="team_id" name="team_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tous les binômes</option>
                        @foreach ($teamOptions->groupBy(fn ($t) => $t->healthZone?->name ?? 'Autres') as $zoneName => $group)
                            <optgroup label="{{ $zoneName }}">
                                @foreach ($group as $team)
                                    <option value="{{ $team->id }}" @selected($team->id === $filters['team_id'])>{{ $team->label() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label for="from" class="form-label small mb-1">Du</label>
                    <input id="from" name="from" type="date" class="form-control form-control-sm @error('from') is-invalid @enderror" value="{{ $filters['from'] }}">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="to" class="form-label small mb-1">Au</label>
                    <input id="to" name="to" type="date" class="form-control form-control-sm @error('to') is-invalid @enderror" value="{{ $filters['to'] }}">
                    @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel me-1"></i>Afficher</button>
                    @if ($query)<a href="{{ route('team-activity.index') }}" class="btn btn-sm btn-link">Réinitialiser</a>@endif
                </div>
            </form>
            <div class="form-text mt-2">Mères : caregivers interrogés · enfants éligibles : 12 à 35 mois · un jour sans données du binôme apparaît comme « {{ \App\Services\Dashboard\TeamActivityReport::OFF_DAY }} ».</div>
        </div>
    </div>

    @forelse ($reports as $report)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-semibold">{{ $report['team']->label() }}</span>
                    <span class="small text-muted">· {{ $report['team']->healthZone?->name ?? '—' }} · {{ $report['team']->collectors->map(fn ($a) => $a->name.' ('.$a->code.')')->implode(' et ') ?: 'agents non renseignés' }}</span>
                </div>
                @unless ($filters['team_id'])
                    <div class="d-flex gap-2">
                        <a href="{{ route('team-activity.index', ['team_id' => $report['team']->id] + \Illuminate\Support\Arr::only($query, ['from', 'to'])) }}" class="btn btn-sm btn-outline-secondary">Voir seul</a>
                        <a href="{{ route('team-activity.pdf', ['team_id' => $report['team']->id] + \Illuminate\Support\Arr::only($query, ['from', 'to'])) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                    </div>
                @endunless
            </div>
            <div class="card-body p-0 table-responsive">
                @include('team-activity._table', ['report' => $report])
            </div>
        </div>
    @empty
        <div class="alert alert-info">Aucun binôme pour ces filtres{{ $from ? '' : ' (aucune donnée de collecte pour le moment)' }}.</div>
    @endforelse
</x-layouts.app>
