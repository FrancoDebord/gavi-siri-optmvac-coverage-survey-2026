<x-layouts.app title="Paramètres de l'application" page="settings">
    <x-page-header title="Paramètres de l'application" subtitle="Gestion des queries. Chaque modification est enregistrée dans le journal d'audit." />

    <form method="POST" action="{{ route('settings.update') }}" class="card" style="max-width: 760px">
        @csrf
        @method('PUT')
        <div class="card-header fw-semibold"><i class="bi bi-flag me-1"></i>Queries</div>
        <div class="card-body">
            <div class="mb-4">
                <label class="form-label fw-semibold" for="auto_assign">Affectation automatique des nouvelles queries</label>
                <select id="auto_assign" name="auto_assign" class="form-select @error('auto_assign') is-invalid @enderror">
                    @foreach (\App\Models\AppSetting::AUTO_ASSIGN_OPTIONS as $key => $label)
                        <option value="{{ $key }}" @selected(old('auto_assign', $autoAssign) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="form-text">S'applique aux queries créées par les prochaines exécutions du moteur. Le data manager peut toujours réaffecter.</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" for="due_days">Échéance par défaut (jours après l'affectation)</label>
                <input id="due_days" type="number" name="due_days" min="0" max="60" value="{{ old('due_days', $dueDays) }}" class="form-control @error('due_days') is-invalid @enderror" style="max-width: 140px">
                <div class="form-text">0 = pas d'échéance. Les queries non résolues après l'échéance sont signalées « en retard ».</div>
                @error('due_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <input type="hidden" name="auto_apply" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="auto_apply" value="1" id="auto_apply" @checked(old('auto_apply', $autoApply))>
                    <label class="form-check-label fw-semibold" for="auto_apply">Prendre en compte immédiatement les corrections</label>
                </div>
                <div class="form-text">Une correction est appliquée sans attendre si elle lève l'erreur et ne crée aucune autre query. Le data manager la revoit ensuite dans le registre (« À revoir ») et peut la refuser : la valeur précédente est alors rétablie. Sinon, la correction attend sa validation.</div>
            </div>

            <div class="mb-4">
                <input type="hidden" name="ai_reading" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="ai_reading" value="1" id="ai_reading" @checked(old('ai_reading', $aiReading)) @disabled(! $aiConfigured)>
                    <label class="form-check-label fw-semibold" for="ai_reading">Lecture des photos par l'IA (Claude)</label>
                </div>
                <div class="form-text">
                    Sur une query, le bouton « Lire la valeur avec l'IA » envoie les photos de la soumission (carnet, acte de naissance) à l'API Anthropic,
                    qui propose la valeur lue. Elle est présentée à l'agent comme « lue par l'IA, à vérifier » et n'est jamais appliquée sans correction validée.
                    Les photos contiennent des données personnelles d'enfants : n'activer qu'avec l'accord du responsable de la protection des données.
                    @unless ($aiConfigured)<br><span class="text-danger">Indisponible : renseigner ANTHROPIC_API_KEY dans le fichier .env du serveur.</span>@endunless
                </div>
            </div>

            <fieldset>
                <legend class="form-label fw-semibold fs-6">Double validation (revue indépendante et signature électronique)</legend>
                @foreach (\App\Models\QualityRule::SEVERITIES as $key => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="double_validation[]" value="{{ $key }}" id="dv-{{ $key }}" @checked(in_array($key, old('double_validation', $doubleValidation), true))>
                        <label class="form-check-label" for="dv-{{ $key }}">Queries {{ mb_strtolower($label) }}s</label>
                    </div>
                @endforeach
                <div class="form-text">Pour ces sévérités, la réponse est validée par une autre personne que son auteur, qui signe en ressaisissant son mot de passe (validation ou irrésoluble). Par défaut : queries critiques.</div>
            </fieldset>
        </div>
        <div class="card-footer text-end">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        </div>
    </form>
</x-layouts.app>
