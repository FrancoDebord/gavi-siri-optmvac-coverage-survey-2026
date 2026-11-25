<x-layouts.app :title="'Photos · binôme '.$team->code" page="team">
    @include('team._header')

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-images me-1"></i>Photos de terrain du binôme · {{ $photos->total() }} photo(s)</span>
            <span class="small text-muted">Cliquez sur une photo pour l'ouvrir en plein écran</span>
        </div>
        <div class="card-body">
            @include('photos._gallery', ['photos' => $photos, 'project' => null, 'canDelete' => false])
        </div>
    </div>
</x-layouts.app>
