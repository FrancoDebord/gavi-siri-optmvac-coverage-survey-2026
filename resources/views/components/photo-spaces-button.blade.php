{{--
    Bouton « Photos de terrain » (espace binôme, espace superviseur) : espaces photos ouverts de l'enquête, ouverts
    sans code PIN depuis le compte connecté (TeamSpaceController@photos). Liste déroulante s'il y en a plusieurs.
--}}
@php
    // Binômes, superviseurs et gestionnaires des espaces photos (mêmes droits que TeamSpaceController@photos).
    $user = auth()->user();
    $allowed = $user && ($user->hasAnyRole([\App\Models\User::ROLE_TEAM, \App\Models\User::ROLE_SUPERVISOR]) || $user->hasOwnTeam() || $user->can('manage field photos'));
    $photoSpaces = $allowed ? \App\Models\PhotoProject::openForTeams()->get(['id', 'name']) : collect();
@endphp
@if ($photoSpaces->count() === 1)
    <a href="{{ route('team.photos', $photoSpaces->first()) }}" class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"><i class="bi bi-camera me-1"></i>Photos de terrain</a>
@elseif ($photoSpaces->count() > 1)
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-camera me-1"></i>Photos de terrain</button>
        <ul class="dropdown-menu">
            @foreach ($photoSpaces as $space)
                <li><a class="dropdown-item" href="{{ route('team.photos', $space) }}" target="_blank" rel="noopener">{{ $space->name }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
