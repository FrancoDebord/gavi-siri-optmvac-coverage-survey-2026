{{--
    Bouton « Factures et rapports » (espace binôme, espace superviseur) : espaces de dépôt ouverts de l'enquête, ouverts
    sans code PIN depuis le compte connecté (TeamSpaceController@documents). Liste déroulante s'il y en a plusieurs.
--}}
@php
    $user = auth()->user();
    $documentSpaces = $user && \App\Http\Controllers\PublicDocumentController::canOpenFromAccount($user)
        ? \App\Models\DocumentSpace::openForTeams()->get(['id', 'name']) : collect();
@endphp
@if ($documentSpaces->count() === 1)
    <a href="{{ route('team.documents', $documentSpaces->first()) }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-folder2-open me-1"></i>Factures et rapports</a>
@elseif ($documentSpaces->count() > 1)
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-folder2-open me-1"></i>Factures et rapports</button>
        <ul class="dropdown-menu">
            @foreach ($documentSpaces as $documentSpace)
                <li><a class="dropdown-item" href="{{ route('team.documents', $documentSpace) }}" target="_blank" rel="noopener">{{ $documentSpace->name }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
