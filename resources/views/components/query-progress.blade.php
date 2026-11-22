{{-- Progression de la résolution des queries : réponses du binôme, vérifiées par le superviseur, fermées.
     $counts : nombre de queries par statut de résolution (DataQuery::RESOLUTIONS) ; null = rempli par le script de la page
     (liste des queries : mêmes filtres que le tableau, hors statut). --}}
@props(['counts' => null, 'title' => 'Progression de la résolution des erreurs par le binôme'])
@php
    $segments = [
        \App\Models\DataQuery::RESOLUTION_RESOLVED => ['label' => 'Fermées (validées)', 'class' => 'bg-success'],
        \App\Models\DataQuery::RESOLUTION_UNRESOLVABLE => ['label' => 'Irrésolubles', 'class' => 'bg-secondary'],
        \App\Models\DataQuery::RESOLUTION_SUPERVISED => ['label' => 'Vérifiées, en validation finale', 'class' => 'bg-info'],
        \App\Models\DataQuery::RESOLUTION_PENDING => ['label' => 'Répondues, à vérifier', 'class' => 'bg-warning'],
    ];
    $counts = $counts !== null ? collect($counts)->map(fn ($n) => (int) $n) : null;
    $total = $counts?->sum() ?? 0;
    $pct = fn (int $n) => $total ? round($n * 100 / $total, 1) : 0;
    $unresolved = $counts[\App\Models\DataQuery::RESOLUTION_UNRESOLVED] ?? 0;
    $answered = $total - $unresolved;
    $closed = ($counts[\App\Models\DataQuery::RESOLUTION_RESOLVED] ?? 0) + ($counts[\App\Models\DataQuery::RESOLUTION_UNRESOLVABLE] ?? 0);
@endphp
<div {{ $attributes->merge(['class' => 'card mb-3']) }} data-query-progress>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-1">
            <span class="small fw-semibold"><i class="bi bi-graph-up-arrow me-1"></i>{{ $title }}</span>
            <span class="small">
                Répondues : <strong data-progress-answered>{{ $answered }} / {{ $total }} ({{ $pct($answered) }} %)</strong>
                · Fermées : <strong data-progress-closed>{{ $closed }} ({{ $pct($closed) }} %)</strong>
            </span>
        </div>
        <div class="progress-stacked" style="height: 1.1rem" role="img" aria-label="Progression de la résolution des queries" data-progress-bar>
            @foreach ($segments as $key => $segment)
                <div class="progress" role="progressbar" style="width: {{ $counts ? $pct($counts[$key] ?? 0) : 0 }}%" data-segment="{{ $key }}" title="{{ $segment['label'] }}">
                    <div class="progress-bar {{ $segment['class'] }}"></div>
                </div>
            @endforeach
        </div>
        <div class="d-flex flex-wrap gap-3 small text-muted mt-1">
            @foreach ($segments + [\App\Models\DataQuery::RESOLUTION_UNRESOLVED => ['label' => 'Sans réponse', 'class' => 'bg-body-secondary border']] as $key => $segment)
                <span><span class="d-inline-block rounded-circle {{ $segment['class'] }} me-1" style="width: .6rem; height: .6rem"></span>{{ $segment['label'] }} : <span data-count="{{ $key }}">{{ $counts[$key] ?? 0 }}</span></span>
            @endforeach
        </div>
    </div>
</div>
