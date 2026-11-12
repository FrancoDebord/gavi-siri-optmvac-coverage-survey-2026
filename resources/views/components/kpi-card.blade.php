@props([
    'label',
    'kpi',
    'icon' => 'bar-chart',
    'color' => 'primary',
    'sub' => null,
    'subKpi' => null,
])
{{-- Carte indicateur ; la valeur est injectée par JS via data-kpi, ou passée dans le slot. --}}
<div {{ $attributes->merge(['class' => 'card kpi-card h-100']) }}>
    <div class="card-body d-flex align-items-center gap-3">
        <div class="kpi-icon bg-{{ $color }}-subtle text-{{ $color }}">
            <i class="bi bi-{{ $icon }}"></i>
        </div>
        <div class="min-w-0">
            <div class="kpi-value" data-kpi="{{ $kpi }}">{{ $slot->isEmpty() ? '…' : $slot }}</div>
            <div class="kpi-label">{{ $label }}</div>
            @if ($sub)
                <div class="small text-muted">{{ $sub }} @if ($subKpi)<span data-kpi="{{ $subKpi }}">…</span>@endif</div>
            @endif
        </div>
    </div>
</div>
