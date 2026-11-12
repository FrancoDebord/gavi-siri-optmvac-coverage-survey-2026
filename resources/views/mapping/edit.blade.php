<x-layouts.app title="Mapping des variables">
    <x-page-header title="Mapping des variables" subtitle="Association des champs métier aux variables du XLSForm actif. Les valeurs par défaut sont pré-remplies depuis cov26c4v3.xls." />

    <datalist id="variables-list">
        @foreach ($variables as $variable)
            <option value="{{ $variable['name'] }}">{{ $variable['type'] }} · {{ \Illuminate\Support\Str::limit(strip_tags((string) $variable['label']), 60) }}</option>
        @endforeach
        @foreach ($repeats as $repeat)
            <option value="{{ $repeat }}">repeat</option>
        @endforeach
    </datalist>

    <form method="POST" action="{{ route('mapping.update') }}">
        @csrf
        @method('PUT')
        <div class="row g-3">
            @foreach (\App\Models\VariableMapping::CATEGORIES as $category => $categoryLabel)
                @continue(! isset($mappings[$category]))
                <div class="col-xl-6">
                    <div class="card h-100">
                        <div class="card-header">{{ $categoryLabel }}</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th>Champ métier</th><th>Variable XLSForm</th><th style="width:22%">Valeur</th></tr></thead>
                                <tbody>
                                @foreach ($mappings[$category] as $mapping)
                                    @php($known = ! $mapping->variable_name || $dictionary->has($mapping->variable_name))
                                    <tr>
                                        <td class="small">{{ $mapping->label }}<div class="text-muted"><code>{{ $mapping->key }}</code></div></td>
                                        <td>
                                            <input name="mapping[{{ $mapping->key }}][variable_name]" value="{{ old('mapping.'.$mapping->key.'.variable_name', $mapping->variable_name) }}" list="variables-list" class="form-control form-control-sm {{ $known ? '' : 'is-invalid' }}" aria-label="Variable pour {{ $mapping->label }}">
                                            @unless ($known)<div class="invalid-feedback">Variable absente du dictionnaire actif.</div>@endunless
                                        </td>
                                        <td><input name="mapping[{{ $mapping->key }}][value]" value="{{ old('mapping.'.$mapping->key.'.value', $mapping->value) }}" class="form-control form-control-sm" aria-label="Valeur pour {{ $mapping->label }}"></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer le mapping</button>
        </div>
    </form>
</x-layouts.app>
