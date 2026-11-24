{{-- Galerie d'un espace photos : vignettes (clic : photo originale), auteur, lieu, date, légende ; suppression pour les gestionnaires. --}}
@if ($photos->isEmpty())
    <p class="text-muted text-center py-4 mb-0">Aucune photo pour l'instant.</p>
@else
    <div class="row g-3">
        @foreach ($photos as $photo)
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card h-100 photo-card">
                    <a href="{{ route('photos.public.file', [$project->token, $photo, 'original']) }}" target="_blank" rel="noopener" class="ratio ratio-4x3 bg-light">
                        <img src="{{ route('photos.public.file', [$project->token, $photo, 'vignette']) }}" alt="{{ $photo->caption ?: 'Photo de terrain' }}" loading="lazy" class="object-fit-cover rounded-top">
                    </a>
                    <div class="card-body p-2 small">
                        <div class="fw-semibold text-truncate" title="{{ $photo->authorLabel() }}">{{ $photo->authorLabel() }}</div>
                        @if ($photo->placeLabel())
                            <div class="text-truncate"><i class="bi bi-geo-alt me-1"></i>{{ $photo->placeLabel() }}</div>
                        @endif
                        <div class="text-muted" title="{{ $photo->taken_at ? 'Prise le '.$photo->taken_at->format('d/m/Y H:i').' · ' : '' }}Déposée le {{ $photo->created_at->format('d/m/Y H:i') }}">
                            <i class="bi bi-calendar3 me-1"></i>{{ ($photo->taken_at ?? $photo->created_at)->format('d/m/Y H:i') }}
                            @if ($photo->latitude !== null)
                                <a href="https://www.openstreetmap.org/?mlat={{ $photo->latitude }}&mlon={{ $photo->longitude }}#map=16/{{ $photo->latitude }}/{{ $photo->longitude }}" target="_blank" rel="noopener noreferrer" class="ms-1" title="Position GPS de la photo"><i class="bi bi-pin-map"></i></a>
                            @endif
                        </div>
                        @if ($photo->caption)
                            <div class="mt-1">{{ $photo->caption }}</div>
                        @endif
                    </div>
                    @if ($canDelete)
                        <div class="card-footer p-2 d-flex justify-content-between align-items-center small">
                            <span class="text-muted">{{ number_format($photo->size / 1048576, 1, ',', ' ') }} Mo</span>
                            <form method="POST" action="{{ route('photo-projects.photos.destroy', [$project, $photo]) }}"
                                  data-confirm="Supprimer définitivement cette photo ?" data-confirm-variant="danger" data-confirm-label="Supprimer" data-confirm-title="Supprimer la photo">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Supprimer la photo"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    <div class="mt-3">{{ $photos->links() }}</div>
@endif
