@props([
    'title',
    'canvas',
    'size' => '',
    'subtitle' => null,
])
<div {{ $attributes->merge(['class' => 'card h-100']) }}>
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>{{ $title }}</span>
        @isset($actions)
            <div>{{ $actions }}</div>
        @endisset
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
