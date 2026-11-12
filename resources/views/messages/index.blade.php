@php
    $recipient = old('recipient', isset($defaults['target_type']) ? $defaults['target_type'].':'.($defaults['target_id'] ?? '') : '');
    $scopeLabel = fn ($user) => $user->commune?->name ?? $user->healthZone?->name ?? 'sans zone';
@endphp
<x-layouts.app title="Messages aux équipes">
    <x-page-header title="Messages aux équipes"
                   :subtitle="$canSend ? 'Encouragements, avertissements et informations aux superviseurs, binômes et agents.' : 'Messages de l\'investigateur principal pour votre zone : relayez-les aux binômes et agents concernés.'">
        @if (! $canSend && $unread)
            <form method="POST" action="{{ route('messages.read-all') }}" data-confirm="Marquer les {{ $unread }} message(s) non lus comme lus ?">
                @csrf
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-all me-1"></i>Tout marquer comme lu ({{ $unread }})</button>
            </form>
        @endif
    </x-page-header>

    <div class="row g-3">
        @if ($canSend)
            <div class="col-xl-5" id="nouveau">
                <form method="POST" action="{{ route('messages.store') }}" class="card" data-confirm-title="Envoyer le message" data-confirm="Envoyer ce message à : {recipient} ?" data-confirm-label="Envoyer">
                    @csrf
                    <div class="card-header"><i class="bi bi-send me-1"></i>Nouveau message</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="form-label d-block">Type <span class="text-danger">*</span></span>
                            <div class="btn-group w-100" role="group" aria-label="Type de message">
                                @foreach (\App\Models\TeamMessage::KINDS as $key => [$label, $color, $icon])
                                    <input type="radio" class="btn-check" name="kind" id="kind-{{ $key }}" value="{{ $key }}" @checked(old('kind', $defaults['kind'] ?? 'encouragement') === $key) required>
                                    <label class="btn btn-outline-{{ $color }} btn-sm" for="kind-{{ $key }}"><i class="bi bi-{{ $icon }} me-1"></i>{{ $label }}</label>
                                @endforeach
                            </div>
                            @error('kind')<div class="small text-danger">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="recipient" class="form-label">Destinataire <span class="text-danger">*</span></label>
                            <select id="recipient" name="recipient" class="form-select @error('target_id') is-invalid @enderror @error('target_type') is-invalid @enderror" required>
                                <option value="">Choisir…</option>
                                <option value="all:" @selected($recipient === 'all:')>Toutes les équipes</option>
                                <optgroup label="Tous les superviseurs d'une zone">
                                    @foreach ($zones as $zone)
                                        <option value="zone:{{ $zone->id }}" @selected($recipient === 'zone:'.$zone->id)>Superviseurs · {{ $zone->name }}</option>
                                    @endforeach
                                </optgroup>
                                @if ($supervisors->isNotEmpty())
                                    <optgroup label="Un superviseur">
                                        @foreach ($supervisors as $supervisor)
                                            <option value="supervisor:{{ $supervisor->id }}" @selected($recipient === 'supervisor:'.$supervisor->id)>{{ $supervisor->name }} ({{ $scopeLabel($supervisor) }})</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @foreach ($teams->groupBy(fn ($t) => $t->healthZone?->name ?? '—') as $zoneName => $items)
                                    <optgroup label="Binômes · {{ $zoneName }}">
                                        @foreach ($items as $team)
                                            <option value="team:{{ $team->id }}" @selected($recipient === 'team:'.$team->id)>{{ $team->label() }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                                @foreach ($collectors->groupBy(fn ($c) => $c->healthZone?->name ?? '—') as $zoneName => $items)
                                    <optgroup label="Agents · {{ $zoneName }}">
                                        @foreach ($items as $collector)
                                            <option value="collector:{{ $collector->id }}" @selected($recipient === 'collector:'.$collector->id)>{{ $collector->name }}{{ $collector->team ? ' (binôme '.$collector->team->code.')' : '' }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('target_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @error('target_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Les agents n'ont pas de compte : leurs messages et ceux de leur binôme sont transmis par les superviseurs de la zone.</div>
                        </div>
                        <div class="mb-1">
                            <label for="body" class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea id="body" name="body" rows="5" maxlength="2000" class="form-control @error('body') is-invalid @enderror" required
                                      placeholder="Ex. : Bravo au binôme 46-47 pour ses 18 ménages visités ! Continuez ainsi.">{{ old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Envoyer</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="{{ $canSend ? 'col-xl-7' : 'col-12' }}">
            <div class="card">
                <div class="card-header">{{ $canSend ? 'Messages envoyés' : 'Messages reçus' }}</div>
                <div class="list-group list-group-flush">
                    @forelse ($messages as $message)
                        <div @class(['list-group-item', 'list-group-item-light border-start border-4 border-primary' => ! $canSend && ! $message->read_by_me])>
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span class="badge text-bg-{{ $message->kindColor() }}"><i class="bi bi-{{ $message->kindIcon() }} me-1"></i>{{ $message->kindLabel() }}</span>
                                    <span class="fw-semibold ms-1">{{ $message->targetLabel() }}</span>
                                    @if (! $canSend && ! $message->read_by_me)<span class="badge text-bg-primary ms-1">Nouveau</span>@endif
                                </div>
                                <span class="small text-muted">{{ $message->sender?->name ?? '—' }} · {{ $message->created_at->format('d/m/Y H:i') }}</span>
                            </div>
                            <div class="mt-2" style="white-space: pre-line">{{ $message->body }}</div>
                            <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                                <span>
                                    @if ($canSend)
                                        <i class="bi bi-eye me-1"></i>{{ $message->readers_count ? 'Lu par '.$message->readers_count.' superviseur(s)' : 'Pas encore lu' }}
                                    @endif
                                </span>
                                @if (! $canSend && ! $message->read_by_me)
                                    <form method="POST" action="{{ route('messages.read', $message) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-link p-0"><i class="bi bi-check2 me-1"></i>Marquer comme lu</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">Aucun message pour le moment.</div>
                    @endforelse
                </div>
                @if ($messages->hasPages())
                    <div class="card-footer">{{ $messages->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
