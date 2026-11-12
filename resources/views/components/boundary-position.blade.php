@props(['position' => null, 'label' => 'grappe'])
{{-- Position d'un ménage par rapport à un contour (résultat de App\Support\Geo::locate). --}}
@if ($position === null)
    <span class="badge text-bg-light border text-muted" title="Pas de contour relevé pour la {{ $label }}">pas de contour</span>
@elseif ($position['inside'])
    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>dans la {{ $label }}</span>
@else
    <span class="badge text-bg-warning" title="Distance au bord du contour"><i class="bi bi-exclamation-triangle me-1"></i>hors {{ $label }} · {{ number_format($position['distance_m'], 0, ',', ' ') }} m</span>
@endif
