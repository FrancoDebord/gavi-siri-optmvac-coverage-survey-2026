<x-layouts.guest title="Connexion">
    <h2 class="h5 mb-3">Connexion</h2>

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Adresse e-mail ou identifiant</label>
            <input id="email" type="text" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Mot de passe</label>
            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label small" for="remember">Se souvenir de moi</label>
            </div>
            <a class="small" href="{{ route('password.request') }}">Mot de passe oublié ?</a>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Se connecter</button>
    </form>

    @if (\App\Http\Controllers\MicrosoftLoginController::enabled())
        <div class="d-flex align-items-center my-3 text-muted small"><hr class="flex-grow-1"><span class="px-2">ou</span><hr class="flex-grow-1"></div>
        <a href="{{ route('auth.microsoft') }}" class="btn btn-outline-secondary w-100"><i class="bi bi-microsoft me-1"></i>Se connecter avec Microsoft 365</a>
        <div class="form-text text-center">Avec l'adresse e-mail de votre compte sur la plateforme.</div>
    @endif
</x-layouts.guest>
