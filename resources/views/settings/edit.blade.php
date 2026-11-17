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
