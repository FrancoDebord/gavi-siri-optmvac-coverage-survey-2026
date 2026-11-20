<x-layouts.app title="Fiches de corrections">
    <x-page-header title="Fiches de corrections" subtitle="Queries non résolues, par binôme : critiques puis majeures, observations de supervision à part.">
        <a href="{{ route('correction-sheets.excel', array_filter($filters)) }}" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <a href="{{ route('correction-sheets.pdf', array_filter($filters)) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    </x-page-header>

    <form method="GET" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        @unless ($locked)
            <div>
                <label class="form-label small mb-0" for="cs-zone">Zone sanitaire</label>
                <select id="cs-zone" name="health_zone_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Toutes</option>
                    @foreach ($zones as $zone)<option value="{{ $zone->id }}" @selected($filters['health_zone_id'] === $zone->id)>{{ $zone->name }}</option>@endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="form-label small mb-0" for="cs-team">Binôme</label>
            <select id="cs-team" name="team_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Tous</option>
                @foreach ($teams as $team)<option value="{{ $team->id }}" @selected($filters['team_id'] === $team->id)>{{ $team->label() }}</option>@endforeach
            </select>
        </div>
    </form>

    {{-- 1. Synthèse --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">1. Synthèse par zone sanitaire et par binôme</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Zone sanitaire</th><th>Commune</th><th>Binôme</th><th class="text-end">Ménages contrôlés</th><th class="text-end">Critiques</th><th class="text-end">Majeures</th><th class="text-end">Observations</th></tr></thead>
                <tbody>
                @forelse ($synthesis as $i => $row)
                    <tr>
                        <td>{{ $row['zone'] }}</td><td>{{ $row['commune'] }}</td>
                        <td><a href="#binome-{{ $i }}">{{ $row['team'] }}</a></td>
                        <td class="text-end">{{ $row['households'] }}</td>
                        <td class="text-end {{ $row['critique'] ? 'text-danger fw-semibold' : '' }}">{{ $row['critique'] }}</td>
                        <td class="text-end">{{ $row['majeure'] }}</td>
                        <td class="text-end">{{ $row['observation'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-success py-3"><i class="bi bi-check-circle me-1"></i>Aucune query à corriger.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2 et 3. Fiches par binôme --}}
    @foreach ($sheets as $i => $sheet)
        <div class="card mb-3" id="binome-{{ $i }}">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>{{ $sheet['title'] }}</span>
                @if ($sheet['team'])<a href="{{ route('team.corrections.pdf', ['team_id' => $sheet['team']->id]) }}" class="btn btn-sm btn-light"><i class="bi bi-file-earmark-pdf me-1"></i>Fiche du binôme</a>@endif
            </div>
            <div class="card-body p-0">
                <div class="px-3 pt-2 small fw-semibold">2. Corrections ({{ count($sheet['corrections']) }})</div>
                @if ($sheet['corrections'])
                    @include('correction-sheets._table', ['rows' => $sheet['corrections']])
                @else
                    <div class="px-3 pb-2 small text-muted">Aucune correction critique ou majeure.</div>
                @endif
                @if ($sheet['observations'])
                    <div class="px-3 pt-3 small fw-semibold">3. Observations de supervision ({{ count($sheet['observations']) }}) — vérifiées par le superviseur, elles ne bloquent pas la validation</div>
                    @include('correction-sheets._table', ['rows' => $sheet['observations']])
                @endif
            </div>
        </div>
    @endforeach

    {{-- 4. Avertissements --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">4. Avertissements</div>
        <div class="card-body small text-muted">Aucun avertissement : AVT-01 n'est pas appliqué (voir ci-dessous).</div>
    </div>

    {{-- 5. Règles non appliquées --}}
    <div class="card">
        <div class="card-header fw-semibold">5. Règles non appliquées</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th>Code</th><th>Raison</th></tr></thead>
                <tbody>
                @foreach ($not_applied as $rule)
                    <tr><td class="font-monospace text-nowrap">{{ $rule['code'] }}</td><td>{{ $rule['reason'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
