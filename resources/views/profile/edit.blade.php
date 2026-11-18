<x-layouts.app title="Mon compte">
    <x-page-header title="Mon compte" :subtitle="auth()->user()->email.' · '.auth()->user()->roleLabel()" />

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Changer de mot de passe</div>
                <div class="card-body">
                    @if (session('status') === 'password-updated')
                        <div class="alert alert-success small">Mot de passe mis à jour. <a href="{{ auth()->user()->homeRoute() }}">Continuer</a></div>
                    @elseif (auth()->user()->must_change_password)
                        <div class="alert alert-warning small"><i class="bi bi-shield-lock me-1"></i>Première connexion : choisissez votre propre mot de passe (au moins 8 caractères, différent de l'actuel) pour accéder à la plateforme.</div>
                    @endif
                    <form method="POST" action="{{ route('user-password.update') }}">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Mot de passe actuel</label>
                            <input id="current_password" type="password" name="current_password" class="form-control @error('current_password', 'updatePassword') is-invalid @enderror" required autocomplete="current-password">
                            @error('current_password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Nouveau mot de passe</label>
                            <input id="password" type="password" name="password" class="form-control @error('password', 'updatePassword') is-invalid @enderror" required autocomplete="new-password">
                            @error('password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password_confirmation">Confirmation</label>
                            <input id="password_confirmation" type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                        </div>
                        <button class="btn btn-primary">Enregistrer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
