<x-layouts.public :title="$project->name">
    <div class="mx-auto" style="max-width: 420px">
        <div class="text-center mb-3 auth-brand">
            <i class="bi bi-images display-5"></i>
            <h1 class="h4 mt-2 mb-0">{{ $project->name }}</h1>
            <p class="mb-0 text-muted">Espace photos de terrain</p>
        </div>
        <div class="card auth-card mx-auto">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('photos.public.unlock', $project->token) }}">
                    @csrf
                    <label for="pin" class="form-label fw-semibold">Code PIN de l'espace</label>
                    <input id="pin" name="pin" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
                           class="form-control form-control-lg text-center @error('pin') is-invalid @enderror" placeholder="••••••">
                    @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Le code vous a été communiqué avec le lien. Il n'est demandé qu'une fois sur cet appareil.</div>
                    <button class="btn btn-primary w-100 mt-3"><i class="bi bi-unlock me-1"></i>Entrer</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.public>
