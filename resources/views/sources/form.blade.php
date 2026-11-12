<x-layouts.app :title="$source->exists ? 'Modifier la source' : 'Nouvelle source'" page="sources">
    <x-page-header :title="$source->exists ? 'Modifier « '.$source->name.' »' : 'Nouvelle source de données'">
        <a href="{{ route('sources.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Retour</a>
    </x-page-header>

    <form method="POST" action="{{ $source->exists ? route('sources.update', $source) : route('sources.store') }}" class="card" novalidate>
        @csrf
        @if ($source->exists) @method('PUT') @endif
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Nom</label>
                    <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $source->name) }}" required maxlength="120">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="source-type">Type</label>
                    <select id="source-type" name="type" class="form-select">
                        @foreach (\App\Models\DataSource::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(old('type', $source->type) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="sync_interval_minutes">Fréquence de synchro (min)</label>
                    <input id="sync_interval_minutes" type="number" min="5" max="1440" name="sync_interval_minutes" class="form-control @error('sync_interval_minutes') is-invalid @enderror" value="{{ old('sync_interval_minutes', $source->sync_interval_minutes) }}">
                    @error('sync_interval_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="server_url">URL du serveur</label>
                    <input id="server_url" type="url" name="server_url" class="form-control @error('server_url') is-invalid @enderror" value="{{ old('server_url', $source->server_url) }}" placeholder="https://odk.mondomaine.org ou https://kf.kobotoolbox.org" required>
                    @error('server_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ODK Central --}}
                <div class="col-12" data-source-type="odk_central">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="email">E-mail du compte ODK Central</label>
                            <input id="email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $source->credential('email')) }}" autocomplete="off">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Mot de passe</label>
                            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" placeholder="{{ $source->exists ? 'Laisser vide pour conserver' : '' }}">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="project_id">Project ID</label>
                            <input id="project_id" name="project_id" class="form-control @error('project_id') is-invalid @enderror" value="{{ old('project_id', $source->project_id) }}">
                            @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="form_id">Form ID (xmlFormId)</label>
                            <input id="form_id" name="form_id" class="form-control @error('form_id') is-invalid @enderror" value="{{ old('form_id', $source->form_id) }}" placeholder="cov26c4v2">
                            @error('form_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                {{-- KoboToolbox --}}
                <div class="col-12" data-source-type="kobotoolbox">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="token">Token API</label>
                            <input id="token" type="password" name="token" class="form-control @error('token') is-invalid @enderror" autocomplete="off" placeholder="{{ $source->exists ? 'Laisser vide pour conserver' : '' }}">
                            @error('token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Compte Kobo › Paramètres du compte › Sécurité › API Key.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="asset_uid">Asset UID</label>
                            <input id="asset_uid" name="asset_uid" class="form-control @error('asset_uid') is-invalid @enderror" value="{{ old('asset_uid', $source->asset_uid) }}" placeholder="aBcD1234…">
                            @error('asset_uid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $source->is_active))>
                        <label class="form-check-label" for="is_active">Synchronisation automatique active</label>
                    </div>
                    <div class="form-text"><i class="bi bi-lock me-1"></i>Les identifiants sont chiffrés en base (clé APP_KEY).</div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            @if ($source->exists)
                <button form="delete-source" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Supprimer</button>
            @else
                <span></span>
            @endif
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        </div>
    </form>
    @if ($source->exists)
        <form id="delete-source" method="POST" action="{{ route('sources.destroy', $source) }}" data-confirm="Supprimer cette source ? Les soumissions déjà importées sont conservées.">
            @csrf @method('DELETE')
        </form>
    @endif
</x-layouts.app>
