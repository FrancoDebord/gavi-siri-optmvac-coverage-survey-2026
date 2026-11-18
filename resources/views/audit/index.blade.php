<x-layouts.app title="Piste d'audit">
    <x-page-header title="Piste d'audit" subtitle="Journal système, historique des queries et registre des corrections : qui, quoi, quand, pourquoi. Les entrées ne sont jamais modifiées.">
        <a href="{{ route('audit.export', request()->query()) }}" class="btn btn-sm btn-success"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
    </x-page-header>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="a-user">Utilisateur</label>
                <select id="a-user" name="user_id" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['user_id'] === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="a-source">Source</label>
                <select id="a-source" name="source" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    @foreach (\App\Http\Controllers\AuditLogController::SOURCES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['source'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0" for="a-action">Action (journal)</label>
                <select id="a-action" name="action" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small mb-0" for="a-du">Du</label>
                <input id="a-du" type="date" name="du" value="{{ $filters['du'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small mb-0" for="a-au">Au</label>
                <input id="a-au" type="date" name="au" value="{{ $filters['au'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 small align-middle">
                <thead class="table-light"><tr><th>Date</th><th>Utilisateur</th><th>Source</th><th>Action</th><th>Référence</th><th>Objet</th><th>Détails</th></tr></thead>
                <tbody>
                @forelse ($entries as $entry)
                    @php($row = \App\Http\Controllers\AuditLogController::describe($entry))
                    <tr>
                        <td class="text-nowrap">{{ \Carbon\CarbonImmutable::parse($entry->occurred_at)->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $names[$entry->user_id] ?? 'Système' }}</td>
                        <td><span class="badge text-bg-light border">{{ \App\Http\Controllers\AuditLogController::SOURCES[$entry->source] }}</span></td>
                        <td>{{ $row['action'] }}</td>
                        <td class="text-nowrap">
                            @if ($entry->source === 'query')<a href="{{ route('queries.show', $entry->ref_id) }}">{{ $row['reference'] }}</a>@else{{ $row['reference'] }}@endif
                        </td>
                        <td><code>{{ $row['object'] }}</code></td>
                        <td class="text-wrap">{{ \Illuminate\Support\Str::limit($row['details'], 220) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Aucune entrée.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($entries->hasPages())<div class="card-footer">{{ $entries->links() }}</div>@endif
    </div>
</x-layouts.app>
