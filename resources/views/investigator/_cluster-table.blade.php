{{-- Grappes par village confrontées à la base de sondage ($report : ClusterSelection::build, $withTeams : colonne binôme). --}}
@php
    $fmt = fn ($n) => $n === null ? '—' : number_format((int) $n, 0, ',', ' ');
    $rateClass = fn (?int $rate) => match (true) { $rate === null => '', $rate >= 100 => 'text-bg-success', $rate >= 50 => 'text-bg-warning', default => 'text-bg-danger' };
@endphp
@forelse ($report['zones'] as $zone)
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2">
            <span class="fw-semibold"><i class="bi bi-hospital me-1"></i>Zone sanitaire de {{ $zone['name'] }}</span>
            <span class="small text-muted">{{ $zone['totals']['villages'] }} village(s) · {{ $zone['totals']['clusters'] }} grappe(s) sélectionnée(s), {{ $zone['totals']['frame_clusters'] }} rapprochée(s) de la base de sondage · {{ $fmt($zone['totals']['visited']) }} ménage(s) visité(s) sur {{ $fmt($zone['totals']['expected']) }} attendus</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 small">
                <thead class="table-light">
                <tr>
                    <th>Village</th>
                    <th class="text-center">N° grappe</th>
                    <th class="text-end">Ménages dénombrés</th>
                    <th class="text-center">Subdivisions prévues</th>
                    <th class="text-center">Sous-grappes créées</th>
                    <th class="text-center">N° sous-grappe</th>
                    <th class="text-end">Ménages attendus</th>
                    <th class="text-end">Ménages visités</th>
                    <th class="text-center">Réalisation</th>
                    <th class="text-end">Enquêtés</th>
                    <th class="text-end">Enfants 12–35 m</th>
                    @if ($withTeams ?? true)<th>Binôme</th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($zone['villages'] as $village)
                    @foreach ($village['rows'] as $i => $row)
                        <tr class="{{ $row['frame_only'] ? 'text-muted' : '' }}">
                            @if ($i === 0)
                                <td rowspan="{{ count($village['rows']) }}" class="fw-semibold text-body">{{ $village['name'] }}</td>
                            @endif
                            <td class="text-center">
                                @if ($row['cluster_id'])
                                    <a href="{{ route('clusters.show', $row['cluster_id']) }}" title="Grappe {{ $row['cluster_code'] }}">{{ $row['cluster_numbers'] ? implode(', ', $row['cluster_numbers']) : $row['cluster_code'] }}</a>
                                    <div class="text-muted">{{ $row['cluster_code'] }}</div>
                                    @if ($row['frame_ambiguous'] ?? null)<div class="text-warning-emphasis" title="Deux grappes du même village avec le même numéro : identifiants ménage à vérifier">≈ {{ $row['frame_ambiguous'] }}</div>@endif
                                @elseif ($row['frame_only'])
                                    {{ implode(', ', $row['cluster_numbers']) }}<div><span class="badge text-bg-light border">non visitée</span></div>
                                @else
                                    {{ implode(', ', $row['cluster_numbers']) }}<div class="text-muted">grappe non rattachée</div>
                                @endif
                            </td>
                            <td class="text-end">{{ $fmt($row['enumerated']) }}</td>
                            <td class="text-center">{{ $row['subdivisions'] ?? '—' }}</td>
                            <td class="text-center {{ $row['sub_mismatch'] ? 'text-danger fw-semibold' : '' }}" @if ($row['sub_mismatch']) title="Différent des {{ $row['subdivisions'] }} subdivisions prévues" @endif>{{ $row['sub_clusters'] ? implode(', ', $row['sub_clusters']) : '—' }}</td>
                            <td class="text-center">{{ $row['selected'] ? implode(', ', $row['selected']) : '—' }}</td>
                            <td class="text-end fw-semibold">{{ $fmt($row['expected']) }}</td>
                            <td class="text-end">{{ $fmt($row['visited']) }}</td>
                            <td class="text-center">
                                @if ($row['rate'] !== null)
                                    <span class="badge {{ $rateClass($row['rate']) }}">{{ $row['rate'] }} %</span>
                                    <div class="text-muted">{{ $row['gap'] > 0 ? '+' : '' }}{{ $row['gap'] }}</div>
                                @else — @endif
                            </td>
                            <td class="text-end">{{ $fmt($row['surveyed']) }}</td>
                            <td class="text-end">{{ $fmt($row['children']) }}</td>
                            @if ($withTeams ?? true)<td>{!! $row['teams'] ? collect($row['teams'])->map(fn ($t) => e($t))->implode('<br>') : '<span class="text-muted">—</span>' !!}</td>@endif
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
                <tfoot>
                <tr class="table-secondary fw-semibold">
                    <td>Total {{ $zone['name'] }}</td>
                    <td class="text-center">{{ $zone['totals']['clusters'] }}</td>
                    <td class="text-end">{{ $fmt($zone['totals']['enumerated']) }}</td>
                    <td></td>
                    <td class="text-center">{{ $zone['totals']['sub_clusters'] }}</td>
                    <td></td>
                    <td class="text-end">{{ $fmt($zone['totals']['expected']) }}</td>
                    <td class="text-end">{{ $fmt($zone['totals']['visited']) }}</td>
                    <td class="text-center">{{ $zone['totals']['expected'] ? round(100 * $zone['totals']['visited_framed'] / $zone['totals']['expected']).' %' : '—' }}</td>
                    <td class="text-end">{{ $fmt($zone['totals']['surveyed']) }}</td>
                    <td class="text-end">{{ $fmt($zone['totals']['children']) }}</td>
                    @if ($withTeams ?? true)<td></td>@endif
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
@empty
    <div class="alert alert-light border">Aucune grappe pour l'instant.</div>
@endforelse
