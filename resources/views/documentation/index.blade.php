<x-layouts.app title="Documentation">
    <x-page-header title="Documentation" subtitle="Guides de la plateforme, à jour avec la version en ligne" />

    <div class="row g-3">
        @foreach ($pages as $doc)
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('documentation.show', $doc['slug']) }}" class="card h-100 text-decoration-none text-body doc-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-{{ $doc['icon'] }} fs-4 text-primary"></i>
                            <h2 class="h6 mb-0">{{ $doc['title'] }}</h2>
                        </div>
                        <p class="small text-muted mb-0">{{ $doc['description'] }}</p>
                    </div>
                    @if ($doc['updated_at'])
                        <div class="card-footer small text-muted bg-transparent">Mis à jour le {{ $doc['updated_at']->timezone(config('app.timezone'))->format('d/m/Y') }}</div>
                    @endif
                </a>
            </div>
        @endforeach
    </div>

    <style>
        .doc-card { transition: box-shadow .15s ease, transform .15s ease; }
        .doc-card:hover, .doc-card:focus-visible { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08); transform: translateY(-1px); }
    </style>
</x-layouts.app>
