<x-layouts.app title="Journal d'audit">
    <x-page-header title="Journal d'audit" subtitle="Actions sensibles : connexions, paramètres, imports, exports, règles, utilisateurs">
        <form method="GET" class="d-flex gap-2">
            <select name="action" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrer par action">
                <option value="">Toutes les actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </form>
    </x-page-header>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Détails</th><th>IP</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $log->user?->name ?? 'Système' }}</td>
                        <td><code>{{ $log->action }}</code></td>
                        <td>{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td>
                        <td><code class="small text-wrap">{{ $log->properties ? \Illuminate\Support\Str::limit(json_encode($log->properties, JSON_UNESCAPED_UNICODE), 200) : '' }}</code></td>
                        <td>{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">Aucune entrée.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())<div class="card-footer">{{ $logs->links() }}</div>@endif
    </div>
</x-layouts.app>
