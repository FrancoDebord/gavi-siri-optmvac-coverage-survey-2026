@props([
    'note' => null,
])
{{--
    Tableaux simples « Niveau zone / commune / village » (remplis par lib/ppt-tables.js) : bouton « Copier » pour les
    coller comme tableaux natifs dans PowerPoint, Word ou Excel. Le niveau village, long, est replié par défaut.
--}}
<div class="row g-3 mb-3">
    @foreach (['zone' => 'Niveau zone', 'commune' => 'Niveau commune', 'village' => 'Niveau village'] as $level => $title)
        @php($folded = $level === 'village')
        <div class="{{ $folded ? 'col-12' : 'col-xl-6' }}">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    @if ($folded)
                        <button type="button" class="btn btn-link p-0 text-reset text-decoration-none collapsed" data-bs-toggle="collapse" data-bs-target="#ppt-{{ $level }}-body" aria-expanded="false" aria-controls="ppt-{{ $level }}-body">
                            <i class="bi bi-chevron-down me-1"></i>{{ $title }} <span class="small text-muted">— tableau pour PowerPoint (cliquer pour déplier)</span>
                        </button>
                    @else
                        <span>{{ $title }} <span class="small text-muted">— tableau pour PowerPoint</span></span>
                    @endif
                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-copy-table="ppt-{{ $level }}" title="Copier le tableau (à coller dans PowerPoint, Word ou Excel)">
                        <i class="bi bi-clipboard me-1"></i>Copier
                    </button>
                </div>
                <div id="ppt-{{ $level }}-body" @class(['collapse' => $folded])>
                    <div class="card-body table-responsive" @if ($folded) style="max-height: 640px" @endif>
                        <div id="ppt-{{ $level }}"></div>
                    </div>
                    @if ($note)
                        <div class="card-footer small text-muted">{{ $note }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
