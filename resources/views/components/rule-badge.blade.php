{{-- Code de la règle de qualité violée (ex. ROSTER_MEMBERS), avec son nom en info-bulle. --}}
@props(['code', 'name' => null])
@if ($code)
    <span {{ $attributes->merge(['class' => 'badge text-bg-light border font-monospace fw-semibold']) }} title="Règle {{ $code }}{{ $name ? ' : '.$name : '' }}"><i class="bi bi-shield-exclamation me-1"></i>{{ $code }}</span>
@endif
