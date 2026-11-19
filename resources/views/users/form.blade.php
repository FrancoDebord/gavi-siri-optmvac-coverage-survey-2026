<x-layouts.app :title="$user->exists ? 'Modifier un utilisateur' : 'Nouvel utilisateur'">
    <x-page-header :title="$user->exists ? $user->name : 'Nouvel utilisateur'">
        <a href="{{ route('users.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Utilisateurs</a>
    </x-page-header>

    <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card" novalidate>
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Nom</label>
                    <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">E-mail</label>
                    <input id="email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="username">Identifiant de connexion (facultatif)</label>
                    <input id="username" name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $user->username) }}" maxlength="100" placeholder="ex. dossou.kpade">
                    <div class="form-text">Utilisable à la place de l'e-mail pour se connecter.</div>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="role">Rôle</label>
                    <select id="role" name="role" class="form-select @error('role') is-invalid @enderror">
                        @foreach (\App\Models\User::ROLES as $key => $label)
                            <option value="{{ $key }}" @selected(old('role', $user->getRoleNames()->first()) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Administrateur : tout · Investigateur principal : vue d'ensemble, rapports, messages · Data manager : imports, règles, queries · Superviseur : sa zone/commune, traite les queries · Lecteur : dashboards · Binôme : sa propre progression.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="health_zone_id">Zone sanitaire (superviseur)</label>
                    <select id="health_zone_id" name="health_zone_id" class="form-select @error('health_zone_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected((string) old('health_zone_id', $user->health_zone_id) === (string) $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                    @error('health_zone_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="commune_id">Commune (superviseur, facultatif)</label>
                    <select id="commune_id" name="commune_id" class="form-select">
                        <option value="">— Toute la zone —</option>
                        @foreach ($communes as $commune)
                            <option value="{{ $commune->id }}" @selected((string) old('commune_id', $user->commune_id) === (string) $commune->id)>{{ $commune->name }} ({{ $commune->healthZone?->name }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="team_id">Binôme (compte binôme, ou superviseur agent d'un binôme)</label>
                    <select id="team_id" name="team_id" class="form-select @error('team_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($teams->groupBy(fn ($t) => $t->healthZone?->name ?? '—') as $zoneName => $items)
                            <optgroup label="{{ $zoneName }}">
                                @foreach ($items as $team)
                                    <option value="{{ $team->id }}" @selected((string) old('team_id', $user->team_id) === (string) $team->id)>{{ $team->label() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('team_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8"></div>
                <div class="col-md-6">
                    <label class="form-label" for="password">Mot de passe @if ($user->exists)<span class="text-muted small">(laisser vide pour conserver)</span>@endif</label>
                    <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password_confirmation">Confirmation</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-12">
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $user->is_active))>
                        <label class="form-check-label" for="is_active">Compte actif</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        </div>
    </form>
</x-layouts.app>
