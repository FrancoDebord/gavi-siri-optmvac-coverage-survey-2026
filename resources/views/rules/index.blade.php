<x-layouts.app title="Règles qualité">
    <x-page-header title="Règles de contrôle qualité" :subtitle="$lastRun ? 'Dernière exécution : '.$lastRun->started_at->format('d/m/Y H:i').' · '.$lastRun->opened_count.' nouvelle(s) query(s), '.$lastRun->auto_closed_count.' fermée(s) automatiquement' : null">
        @can('run quality checks')
            <form method="POST" action="{{ route('queries.run') }}" data-confirm="Relancer le moteur de queries ?">
                @csrf
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle me-1"></i>Relancer le moteur</button>
            </form>
        @endcan
        <a href="{{ route('rules.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Règle personnalisée</a>
    </x-page-header>

    @if ($lastRun?->error && $lastRun->status === 'success')
        <div class="alert alert-warning small">Certaines règles n'ont pas pu être évaluées lors de la dernière exécution : {{ $lastRun->error }}</div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Code</th><th>Libellé</th><th>Type</th><th>Sévérité</th><th>Paramètres</th><th class="text-end">Queries</th><th class="text-end">Non résolues</th><th>Active</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($rules as $rule)
                    <tr @class(['text-muted' => ! $rule->is_active])>
                        <td><code>{{ $rule->code }}</code></td>
                        <td>{{ $rule->name }}<div class="small text-muted">{{ \Illuminate\Support\Str::limit($rule->description ?? $rule->expression, 110) }}</div></td>
                        <td class="small">{{ \App\Models\QualityRule::CATEGORIES[$rule->category] ?? $rule->category }}</td>
                        <td><x-severity-badge :severity="$rule->severity" /></td>
                        <td class="small">
                            @foreach (array_merge(\App\Services\Quality\RuleCatalog::find($rule->code)['parameters'] ?? [], $rule->parameters ?? []) as $key => $value)
                                <span class="badge text-bg-light border">{{ $key }} = {{ is_array($value) ? implode(',', $value) : $value }}</span>
                            @endforeach
                        </td>
                        <td class="text-end"><a href="{{ route('queries.index', ['rule_code' => $rule->code]) }}">{{ $counts[$rule->code]->total ?? 0 }}</a></td>
                        <td class="text-end">{{ $counts[$rule->code]->open ?? 0 }}</td>
                        <td>
                            <form method="POST" action="{{ route('rules.toggle', $rule) }}">
                                @csrf
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" @checked($rule->is_active) onchange="this.form.submit()" aria-label="Activer {{ $rule->code }}">
                                </div>
                            </form>
                        </td>
                        <td class="text-end"><a href="{{ route('rules.edit', $rule) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">La documentation complète des règles (seuils par défaut, logique) est consultable dans la <a href="{{ route('documentation.show', 'regles-qualite') }}">documentation des règles qualité</a>.</p>
</x-layouts.app>
