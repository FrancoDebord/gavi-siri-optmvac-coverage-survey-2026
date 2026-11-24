<x-layouts.public :title="$project->name" page="photo-space">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <i class="bi bi-images fs-2 text-danger"></i>
        <div>
            <h1 class="h4 mb-0">{{ $project->name }}</h1>
            <div class="small text-muted">Espace photos de terrain · {{ $photos->total() }} photo(s)</div>
        </div>
    </div>
    @if ($project->description)
        <p class="text-muted small">{!! nl2br(e($project->description)) !!}</p>
    @endif

    {{-- Dépôt : le navigateur envoie les photos une par une (réduites si trop lourdes) ; sans JS, toutes ensemble. --}}
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Déposer des photos</div>
        <div class="card-body">
            <form id="photo-upload" method="POST" action="{{ route('photos.public.upload', $project->token) }}" enctype="multipart/form-data"
                  data-max-bytes="{{ $maxKb * 1024 }}" data-linked="{{ $project->linked_to_survey ? '1' : '0' }}">
                @csrf
                <div class="row g-3">
                    @if ($project->linked_to_survey)
                        <div class="col-md-6">
                            <label for="team_id" class="form-label">Binôme <span class="text-danger">*</span></label>
                            <select id="team_id" name="team_id" class="form-select" required data-remember="team_id">
                                <option value="">Choisir votre binôme…</option>
                                @foreach ($teams->groupBy(fn ($t) => $t->healthZone?->name ?? 'Autres') as $zone => $group)
                                    <optgroup label="{{ $zone }}">
                                        @foreach ($group as $team)
                                            <option value="{{ $team->id }}" @selected(old('team_id') == $team->id)>{{ $team->label() }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="village_id" class="form-label">Village</label>
                            <select id="village_id" name="village_id" class="form-select" data-search="true">
                                <option value="">— Non précisé —</option>
                                @foreach ($villages->groupBy(fn ($v) => $v->healthZone?->name ?? 'Autres') as $zone => $group)
                                    <optgroup label="{{ $zone }}">
                                        @foreach ($group as $village)
                                            <option value="{{ $village->id }}" @selected(old('village_id') == $village->id)>{{ $village->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div class="col-md-6">
                            <label for="author_name" class="form-label">Votre nom <span class="text-danger">*</span></label>
                            <input id="author_name" name="author_name" type="text" maxlength="120" required class="form-control" value="{{ old('author_name') }}" data-remember="author_name">
                        </div>
                        <div class="col-md-6">
                            <label for="place" class="form-label">Lieu</label>
                            <input id="place" name="place" type="text" maxlength="160" class="form-control" value="{{ old('place') }}" placeholder="Village, quartier, site…">
                        </div>
                    @endif
                    <div class="col-12">
                        <label for="caption" class="form-label">Légende</label>
                        <input id="caption" name="caption" type="text" maxlength="500" class="form-control" value="{{ old('caption') }}" placeholder="Ce que montrent les photos (facultatif)">
                    </div>
                    <div class="col-12">
                        <label for="photos" class="form-label">Photos <span class="text-danger">*</span></label>
                        <input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" multiple required class="form-control">
                        <div class="form-text">Jusqu'à 20 photos par envoi (JPEG, PNG ou WebP). Les photos trop lourdes sont réduites automatiquement avant l'envoi.</div>
                    </div>
                </div>
                <div id="upload-progress" class="mt-3 d-none">
                    <div class="progress mb-2" role="progressbar" aria-label="Progression de l'envoi"><div class="progress-bar" style="width: 0%"></div></div>
                    <ul class="list-unstyled small mb-0" data-upload-list></ul>
                </div>
                <button class="btn btn-primary mt-3" data-upload-submit><i class="bi bi-send me-1"></i>Envoyer les photos</button>
            </form>
        </div>
    </div>

    {{-- Galerie du projet --}}
    <h2 class="h5 mb-3">Photos déposées</h2>
    @include('photos._gallery', ['photos' => $photos, 'project' => $project, 'canDelete' => false])
</x-layouts.public>
