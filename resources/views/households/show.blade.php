@php
    $label = fn (string $name) => $dictionary->variable($name)['label'] ?? null;
    $display = function (string $name, $value) use ($dictionary) {
        $variable = $dictionary->variable($name);
        if ($variable && $variable['list_name'] && ! is_array($value)) {
            $labels = collect(preg_split('/\s+/', trim((string) $value)))->map(fn ($v) => $dictionary->choiceLabel($variable['list_name'], $v) ?? $v);

            return $labels->implode(', ').' ('.$value.')';
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    };
@endphp
<x-layouts.app :title="'Ménage '.$household->household_code" page="household">
    <x-page-header :title="'Ménage '.($household->household_code ?? '—')" :subtitle="$household->submission_uuid">
        <a href="{{ url()->previous() }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Retour</a>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header">Visite</div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-6">Date d'interview</dt><dd class="col-6">{{ $household->interview_date?->format('d/m/Y') ?? '—' }}</dd>
                        <dt class="col-6">Début / fin</dt><dd class="col-6">{{ $household->started_at?->format('H:i') ?? '—' }} – {{ $household->ended_at?->format('H:i') ?? '—' }}</dd>
                        <dt class="col-6">Durée</dt><dd class="col-6">{{ $household->duration_minutes !== null ? $household->duration_minutes.' min' : '—' }}</dd>
                        <dt class="col-6">Soumis le</dt><dd class="col-6">{{ $household->submitted_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt class="col-6">Statut</dt><dd class="col-6">{!! $household->consented ? '<span class="badge text-bg-success">Enquêté</span>' : '<span class="badge text-bg-secondary">Non enquêté</span>' !!} {{ $household->survey_status }}</dd>
                        <dt class="col-6">Visite n°</dt><dd class="col-6">{{ $household->visit_number ?? '—' }}</dd>
                        <dt class="col-6">Zone / commune</dt><dd class="col-6">{{ $household->healthZone?->name }} / {{ $household->commune?->name }}</dd>
                        <dt class="col-6">Village / grappe</dt><dd class="col-6">{{ $household->village?->name }} / {{ $household->cluster?->code }}</dd>
                        <dt class="col-6">Agent / binôme</dt><dd class="col-6">{{ $household->collector?->name ?? '—' }}{{ $household->collector?->team ? ' · '.$household->collector->team->label() : '' }}</dd>
                        <dt class="col-6">GPS</dt><dd class="col-6">{{ $household->latitude ? round($household->latitude, 5).', '.round($household->longitude, 5).' (±'.round($household->gps_accuracy ?? 0).' m)' : '—' }}</dd>
                        <dt class="col-6">Source</dt><dd class="col-6">{{ $household->dataSource?->name ?? $household->source_type }}</dd>
                        <dt class="col-6">Version du formulaire</dt><dd class="col-6">{{ $household->form_version ?? '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Queries ({{ $household->queries->count() }})</div>
                <div class="list-group list-group-flush" style="max-height: 420px; overflow:auto">
                    @forelse ($household->queries as $query)
                        <a href="{{ route('queries.show', $query) }}" class="list-group-item list-group-item-action small">
                            <div class="d-flex justify-content-between"><code>{{ $query->variable }}</code><span><x-severity-badge :severity="$query->severity" /> <x-status-badge :status="$query->status" /></span></div>
                            <div class="text-truncate-2">{{ $query->message }}</div>
                        </a>
                    @empty
                        <div class="list-group-item small text-muted">Aucune query.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-geo-alt me-1"></i>Localisation
                        @if ($household->cluster)
                            · <a href="{{ route('clusters.show', $household->cluster) }}">grappe {{ $household->cluster->code }}</a>{{ $household->sub_cluster ? ', sous-grappe '.$household->sub_cluster : '' }}
                        @endif
                    </span>
                    @if ($position)
                        <span class="d-flex flex-wrap gap-1">
                            <x-boundary-position :position="$position['cluster']" label="grappe" />
                            @if ($household->sub_cluster)<x-boundary-position :position="$position['sub_cluster']" :label="'sous-grappe '.$household->sub_cluster" />@endif
                        </span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($mapData['household'])
                        <div id="household-map" class="map-box" style="height: 360px" data-map='@json($mapData)'></div>
                        <p class="small text-muted mt-2 mb-0">
                            <i class="bi bi-record-circle me-1"></i>Ce ménage (gros point cerclé) · <span class="text-success">●</span> / <span class="text-danger">●</span> autres ménages de la grappe, recrutés / non recrutés ·
                            <span style="color:#1B3A6B">━</span> grappe · <span style="color:#E8A33D">┅</span> sous-grappes
                            @if ($household->gps_accuracy) · précision GPS ±{{ round($household->gps_accuracy) }} m (cercle bleu)@endif
                            @if (! $mapData['boundaries'])<br><i class="bi bi-info-circle me-1"></i>Aucun contour relevé pour cette grappe.@endif
                        </p>
                    @else
                        <p class="text-muted small mb-0"><i class="bi bi-geo-alt me-1 text-danger"></i>Pas de coordonnées GPS pour ce ménage : impossible de le placer sur la carte.</p>
                    @endif
                </div>
            </div>

            @foreach ($household->caregivers as $caregiver)
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between">
                        <span>Caregiver n°{{ $caregiver->position }} – {{ $caregiver->initials ?? '—' }}</span>
                        <span class="badge {{ $caregiver->consented ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $caregiver->consented ? 'Participe' : 'Ne participe pas' }}</span>
                    </div>
                    <div class="card-body">
                        @forelse ($caregiver->children as $child)
                            <details class="mb-2">
                                <summary class="small">
                                    Enfant n°{{ $child->position }} · {{ $child->sex === '1' ? 'fille' : 'garçon' }} · {{ $child->date_of_birth?->format('d/m/Y') ?? 'DDN inconnue' }} · {{ $child->age_months ?? '—' }} mois
                                    @if ($child->eligible)<span class="badge text-bg-warning">éligible</span>@endif
                                    @if ($child->card_seen)<span class="badge text-bg-dark">carnet vu</span>@endif
                                </summary>
                                <table class="table table-sm small mt-2">
                                    @foreach ($child->data as $name => $value)
                                        @continue($value === null || $value === '')
                                        <tr><td class="text-muted" style="width:40%"><code>{{ $name }}</code><div>{{ \Illuminate\Support\Str::limit(strip_tags((string) $label($name)), 70) }}</div></td><td>{{ $display($name, $value) }}</td></tr>
                                    @endforeach
                                </table>
                            </details>
                        @empty
                            <p class="small text-muted mb-0">Aucun enfant saisi.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach

            <div class="card">
                <div class="card-header">Variables du ménage</div>
                <div class="card-body p-0 table-responsive" style="max-height: 520px">
                    <table class="table table-sm small mb-0">
                        @foreach ($household->data as $name => $value)
                            @continue($name === '__repeats' || $value === null || $value === '')
                            <tr><td class="text-muted" style="width:40%"><code>{{ $name }}</code><div>{{ \Illuminate\Support\Str::limit(strip_tags((string) $label($name)), 70) }}</div></td><td>{{ $display($name, $value) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
