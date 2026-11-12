<x-layouts.app :title="$page['title'].' · Documentation'">
    <x-page-header :title="$page['title']" :subtitle="'Mis à jour le '.$updatedAt->timezone(config('app.timezone'))->format('d/m/Y')">
        <div class="d-flex gap-2 doc-actions">
            <a href="{{ route('documentation.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Documentation</a>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimer</button>
        </div>
    </x-page-header>

    @if (count($pages) > 1)
        <ul class="nav nav-tabs mb-3 doc-tabs">
            @foreach ($pages as $key => $other)
                <li class="nav-item">
                    <a class="nav-link {{ $key === $slug ? 'active' : '' }}" href="{{ route('documentation.show', $key) }}" @if ($key === $slug) aria-current="page" @endif>
                        <i class="bi bi-{{ $other['icon'] }} me-1"></i>{{ $other['title'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="row g-3">
        @if (count($toc) > 2)
            <aside class="col-lg-3 order-lg-2 doc-toc-col">
                <div class="card doc-toc">
                    <div class="card-header small fw-semibold"><i class="bi bi-list-ul me-1"></i>Sommaire</div>
                    <nav class="card-body py-2 small" aria-label="Sommaire">
                        @foreach ($toc as $item)
                            <a href="#{{ $item['id'] }}" class="d-block py-1 text-decoration-none {{ $item['level'] === 3 ? 'ps-3 text-muted' : '' }}">{{ $item['text'] }}</a>
                        @endforeach
                    </nav>
                </div>
            </aside>
        @endif
        <div class="{{ count($toc) > 2 ? 'col-lg-9' : 'col-12' }} order-lg-1">
            <div class="card">
                <article class="card-body doc-content">
                    {!! $html !!}
                </article>
            </div>
        </div>
    </div>

    <style>
        .doc-content { line-height: 1.6; max-width: 72rem; }
        .doc-content h2 { font-size: 1.25rem; margin-top: 2rem; padding-bottom: .35rem; border-bottom: 1px solid var(--bs-border-color); scroll-margin-top: 5rem; }
        .doc-content h3 { font-size: 1.05rem; margin-top: 1.5rem; scroll-margin-top: 5rem; }
        .doc-content > h2:first-child, .doc-content > h3:first-child { margin-top: 0; }
        .doc-content .doc-anchor { opacity: 0; font-size: .85em; text-decoration: none; color: var(--bs-secondary-color); }
        .doc-content h2:hover .doc-anchor, .doc-content h3:hover .doc-anchor, .doc-content .doc-anchor:focus { opacity: 1; }
        .doc-content pre { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); border-radius: .375rem; padding: .75rem 1rem; font-size: .825rem; overflow-x: auto; }
        .doc-content :not(pre) > code { background: var(--bs-tertiary-bg); padding: .1em .35em; border-radius: .25rem; font-size: .85em; color: var(--bs-emphasis-color); }
        .doc-content table { font-size: .875rem; }
        .doc-content table th { background: var(--bs-tertiary-bg); white-space: nowrap; }
        .doc-content .doc-note { border-left: 4px solid var(--bs-warning); background: var(--bs-warning-bg-subtle); padding: .75rem 1rem; border-radius: .25rem; }
        .doc-content .doc-note p:last-child { margin-bottom: 0; }
        .doc-content .doc-file { font-family: var(--bs-font-monospace); font-size: .85em; }
        .doc-toc { position: sticky; top: 5rem; max-height: calc(100vh - 6rem); overflow-y: auto; }
        @media print {
            .app-sidebar, .doc-tabs, .doc-toc-col, .doc-actions, header, .doc-anchor { display: none !important; }
            .doc-content { max-width: none; }
            .card { border: 0 !important; }
        }
    </style>
</x-layouts.app>
