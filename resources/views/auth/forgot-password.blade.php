<x-layouts.guest title="Mot de passe oublié">
    <h2 class="h5 mb-2">Mot de passe oublié</h2>
    <p class="small text-muted">Indiquez votre adresse e-mail : un lien de réinitialisation vous sera envoyé.</p>

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Adresse e-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-primary w-100">Envoyer le lien</button>
        <div class="text-center mt-3"><a class="small" href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Retour à la connexion</a></div>
    </form>
</x-layouts.guest>
