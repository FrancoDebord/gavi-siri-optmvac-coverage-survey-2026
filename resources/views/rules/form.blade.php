<x-layouts.app :title="$rule->exists ? 'Règle '.$rule->code : 'Nouvelle règle'" page="rules">
    <x-page-header :title="$rule->exists ? 'Règle '.$rule->code : 'Nouvelle règle personnalisée'" :subtitle="\App\Models\QualityRule::CATEGORIES[$rule->category] ?? null">
        <a href="{{ route('rules.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Règles</a>
    </x-page-header>

    <form method="POST" action="{{ $rule->exists ? route('rules.update', $rule) : route('rules.store') }}" class="card" novalidate>
        @csrf
        @if ($rule->exists) @method('PUT') @endif
        <input type="hidden" id="rule-category" value="{{ $rule->category }}">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="code">Code</label>
                    <input id="code" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $rule->code) }}" @disabled($rule->exists) placeholder="EXPR_…" maxlength="40">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="name">Libellé</label>
                    <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}" required maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="severity">Sévérité</label>
                    <select id="severity" name="severity" class="form-select">
                        @foreach (\App\Models\QualityRule::SEVERITIES as $key => $label)
                            <option value="{{ $key }}" @selected(old('severity', $rule->severity) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="kind">Nature</label>
                    <select id="kind" name="kind" class="form-select">
                        @foreach (\App\Models\QualityRule::KINDS as $key => $label)
                            <option value="{{ $key }}" @selected(old('kind', $rule->kind ?? 'query') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Query : valeur à corriger ou confirmer (cycle de résolution). Observation : fait de supervision sur les paradonnées, sans correction.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <textarea id="description" name="description" rows="2" class="form-control">{{ old('description', $rule->description) }}</textarea>
                </div>

                <div class="col-12">
                    <label class="form-label" for="suggestion">Suggestion de résolution</label>
                    <textarea id="suggestion" name="suggestion" rows="2" class="form-control @error('suggestion') is-invalid @enderror" maxlength="2000">{{ old('suggestion', $rule->suggestion) }}</textarea>
                    @error('suggestion')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Recopiée dans chaque query (exports compris) à la prochaine exécution du moteur. Gabarits : <code>{question}</code> libellé de la question, <code>{variable}</code> nom XLSForm, <code>{valeur}</code> valeur observée, <code>{attendu}</code> valeur attendue.</div>
                </div>

                <div class="col-12" data-expression-only>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="scope">Portée</label>
                            <select id="scope" name="scope" class="form-select">
                                @foreach (\App\Models\QualityRule::SCOPES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('scope', $rule->scope) === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Instance dans laquelle l'expression est évaluée (les variables des niveaux parents restent accessibles).</div>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label" for="expression">Expression XLSForm (condition d'erreur)</label>
                            <textarea id="expression" name="expression" rows="2" class="form-control font-monospace @error('expression') is-invalid @enderror" placeholder="${num_bednets} > ${num_slept_last_night}">{{ old('expression', $rule->expression) }}</textarea>
                            @error('expression')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Une query est ouverte quand l'expression est <strong>vraie</strong>. Fonctions : <code>selected()</code>, <code>count-selected()</code>, <code>string-length()</code>, <code>today()</code>, <code>date()</code>, <code>regex()</code>, <code>if()</code>, <code>not()</code>, <code>number()</code>, <code>int()</code>…</div>
                            <button type="button" id="test-expression" class="btn btn-sm btn-outline-secondary mt-2" data-url="{{ route('rules.test') }}"><i class="bi bi-play me-1"></i>Tester sur les données</button>
                            <div id="test-expression-result" class="mt-2"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="variables">Variable(s) concernée(s)</label>
                            <input id="variables" name="variables" class="form-control" value="{{ old('variables', $rule->variables) }}" placeholder="num_bednets,num_slept_last_night">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="message">Message de la query</label>
                            <input id="message" name="message" class="form-control" value="{{ old('message', $rule->message) }}" maxlength="255">
                        </div>
                    </div>
                </div>

                @if ($rule->code === 'OUTLIER')
                    <div class="col-12">
                        <label class="form-label" for="variables-outlier">Variables numériques contrôlées</label>
                        <input id="variables-outlier" name="variables" class="form-control" value="{{ old('variables', $rule->variables) }}">
                    </div>
                @endif

                @if ($defaults)
                    <div class="col-12">
                        <label class="form-label">Paramètres (seuils)</label>
                        <div class="row g-2">
                            @foreach ($defaults as $key => $default)
                                <div class="col-sm-6 col-lg-3">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">{{ $key }}</span>
                                        <input name="parameters[{{ $key }}]" class="form-control" value="{{ old('parameters.'.$key, $rule->parameters[$key] ?? '') }}" placeholder="{{ is_array($default) ? implode(',', $default) : $default }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text">Laisser vide pour utiliser la valeur par défaut (indiquée en gris).</div>
                    </div>
                @endif

                <div class="col-12">
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $rule->is_active))>
                        <label class="form-check-label" for="is_active">Règle active</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            @if ($rule->exists && $rule->category === 'expression')
                <button form="delete-rule" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer</button>
            @else
                <span></span>
            @endif
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        </div>
    </form>
    @if ($rule->exists && $rule->category === 'expression')
        <form id="delete-rule" method="POST" action="{{ route('rules.destroy', $rule) }}" data-confirm="Supprimer cette règle et ses queries ?">@csrf @method('DELETE')</form>
    @endif
</x-layouts.app>
