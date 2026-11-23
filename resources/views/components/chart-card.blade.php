@props([
    'title',
    'canvas',
    'size' => '',
    'subtitle' => null,
])
<div {{ $attributes->merge(['class' => 'card h-100']) }}>
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <span>{{ $title }}</span>
        <div class="d-flex align-items-center gap-1">
            @isset($actions)
                <div>{{ $actions }}</div>
            @endisset
            {{-- Image du graphique (PNG haute résolution, fond blanc, titre) pour PowerPoint : téléchargée ou copiée. --}}
            <div class="btn-group btn-group-sm" role="group" aria-label="Exporter le graphique">
                <button type="button" class="btn btn-outline-secondary" data-chart-image="download" data-chart-canvas="{{ $canvas }}" data-chart-title="{{ $title }}" title="Télécharger l'image (PNG)"><i class="bi bi-download"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-chart-image="copy" data-chart-canvas="{{ $canvas }}" data-chart-title="{{ $title }}" title="Copier l'image (à coller dans PowerPoint)"><i class="bi bi-clipboard"></i></button>
            </div>
        </div>
    </div>
    <div class="card-body">
        @if ($subtitle)
            <p class="small text-muted mb-2">{{ $subtitle }}</p>
        @endif
        <div class="chart-box {{ $size }}">
            <canvas id="{{ $canvas }}" role="img" aria-label="{{ $title }}"></canvas>
        </div>
    </div>
</div>
