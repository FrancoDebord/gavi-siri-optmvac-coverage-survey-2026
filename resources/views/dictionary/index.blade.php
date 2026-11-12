<x-layouts.app title="Data dictionary" page="dictionary">
    <x-page-header title="Data dictionary" :subtitle="$selected ? $selected->title.' · '.$selected->form_id.' v'.$selected->version.' · '.$selected->variables_count.' variables' : 'Aucun dictionnaire chargé'">
        <a href="{{ route('mapping.edit') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-diagram-3 me-1"></i>Mapping des variables</a>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">Charger un XLSForm</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('dictionary.upload') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="file" name="file" class="form-control mb-2" accept=".xls,.xlsx" required>
                        <p class="small text-muted">Feuilles <code>survey</code>, <code>choices</code> et <code>settings</code>. Une nouvelle version (form_id + version) est créée puis activée ; les listes <code>select_one_from_file</code> sont complétées depuis les CSV du dossier de paramètres.</p>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Charger</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">Versions</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <thead class="table-light"><tr><th>Formulaire</th><th>Version</th><th>Origine</th><th>Variables</th><th>Chargé le</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($versions as $version)
                            <tr @class(['table-primary' => $selected?->is($version)])>
                                <td>{{ $version->form_id }}</td>
                                <td>{{ $version->version }}</td>
                                <td>{{ ['file' => 'Upload', 'api' => 'API', 'default' => 'Fichier par défaut'][$version->source] ?? $version->source }}</td>
                                <td>{{ $version->variables_count }}</td>
                                <td>{{ $version->updated_at->format('d/m/Y H:i') }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('dictionary.index', ['version' => $version->id]) }}" class="btn btn-sm btn-light">Voir</a>
                                    @if ($version->is_active)
                                        <span class="badge text-bg-success">Active</span>
                                    @else
                                        <form method="POST" action="{{ route('dictionary.activate', $version) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">Activer</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if ($selected)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <span>Variables</span>
                <div class="d-flex gap-2">
                    <select id="filter-type" class="form-select form-select-sm w-auto" aria-label="Filtrer par type">
                        <option value="">Tous les types</option>
                        @foreach ($types as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                    </select>
                    <select id="filter-repeat" class="form-select form-select-sm w-auto" aria-label="Filtrer par repeat">
                        <option value="">Toutes les portées</option>
                        @foreach ($repeats as $repeat)<option value="{{ $repeat }}">{{ $repeat }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="dictionary-table" class="table table-sm table-hover small w-100">
                        <thead class="table-light"><tr><th>#</th><th>Variable</th><th>Type</th><th>Libellé</th><th>Repeat</th><th>Groupe</th><th>Obl.</th><th>Relevant</th><th>Constraint</th><th>Calcul</th></tr></thead>
                        <tbody>
                        @foreach ($variables as $variable)
                            <tr>
                                <td>{{ $variable->position }}</td>
                                <td><code>{{ $variable->name }}</code></td>
                                <td>{{ $variable->base_type }}</td>
                                <td>{{ \Illuminate\Support\Str::limit(strip_tags((string) $variable->label), 90) }}@if ($variable->list_name)<div class="text-muted">liste : {{ $variable->list_name }}</div>@endif</td>
                                <td>{{ $variable->repeat_name }}</td>
                                <td class="text-muted">{{ \Illuminate\Support\Str::afterLast((string) $variable->group_path, '/') }}</td>
                                <td>{!! $variable->required ? '<i class="bi bi-check-lg text-success"></i>' : '' !!}</td>
                                <td><code class="small">{{ $variable->relevant }}</code></td>
                                <td><code class="small">{{ $variable->constraint }}</code>@if ($variable->constraint_message)<div class="text-muted">{{ $variable->constraint_message }}</div>@endif</td>
                                <td><code class="small">{{ \Illuminate\Support\Str::limit((string) $variable->calculation, 60) }}</code></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Listes de choix ({{ $choices->count() }})</div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($choices as $list => $items)
                        <div class="col-md-6 col-xl-4">
                            <details>
                                <summary><code>{{ $list }}</code> <span class="text-muted small">({{ $items->count() }})</span></summary>
                                <table class="table table-sm small mt-1 mb-0">
                                    @foreach ($items->take(60) as $choice)<tr><td style="width:30%"><code>{{ $choice->name }}</code></td><td>{{ $choice->label }}</td></tr>@endforeach
                                    @if ($items->count() > 60)<tr><td colspan="2" class="text-muted">… {{ $items->count() - 60 }} autres</td></tr>@endif
                                </table>
                            </details>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
