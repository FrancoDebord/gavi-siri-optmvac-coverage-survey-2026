@php
    $value = fn (string $key, $default = null) => old($key, $defaults[$key] ?? $default);
@endphp
<x-layouts.app title="Signaler un incident">
    <x-page-header title="Signaler un incident de terrain" subtitle="Décrivez ce qui s'est passé : l'incident sera suivi jusqu'à sa résolution.">
        <a href="{{ url()->previous() }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Retour</a>
    </x-page-header>

    <form method="POST" action="{{ route('incidents.store') }}" class="card" style="max-width: 860px">
        @csrf
        <div class="card-body">
            <div class="row g-3">
                @unless ($scoped)
                    <div class="col-md-6">
                        <label for="health_zone_id" class="form-label">Zone sanitaire <span class="text-danger">*</span></label>
                        <select id="health_zone_id" name="health_zone_id" class="form-select @error('health_zone_id') is-invalid @enderror" required>
                            <option value="">Choisir…</option>
                            @foreach ($zones as $zone)
                                <option value="{{ $zone->id }}" @selected((int) $value('health_zone_id') === $zone->id)>{{ $zone->name }}</option>
                            @endforeach
                        </select>
                        @error('health_zone_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endunless
                <div class="col-md-6">
                    <label for="occurred_on" class="form-label">Date de l'incident <span class="text-danger">*</span></label>
                    <input type="date" id="occurred_on" name="occurred_on" value="{{ $value('occurred_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                           class="form-control @error('occurred_on') is-invalid @enderror" required>
                    @error('occurred_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label for="category" class="form-label">Type d'incident <span class="text-danger">*</span></label>
                    <select id="category" name="category" class="form-select @error('category') is-invalid @enderror" required>
                        <option value="">Choisir…</option>
                        @foreach (\App\Models\FieldIncident::CATEGORIES as $key => [$label])
                            <option value="{{ $key }}" @selected($value('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <span class="form-label d-block">Gravité <span class="text-danger">*</span></span>
                    <div class="btn-group w-100" role="group" aria-label="Gravité">
                        @foreach (\App\Models\FieldIncident::SEVERITIES as $key => [$label, $color])
                            <input type="radio" class="btn-check" name="severity" id="severity-{{ $key }}" value="{{ $key }}" @checked($value('severity', 'moyenne') === $key) required>
                            <label class="btn btn-outline-{{ $color }}" for="severity-{{ $key }}">{{ $label }}</label>
                        @endforeach
                    </div>
                    @error('severity')<div class="small text-danger">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="title" class="form-label">Titre <span class="text-danger">*</span></label>
                    <input type="text" id="title" name="title" value="{{ $value('title') }}" maxlength="150" placeholder="Ex. : tablette de l'agent 33 (binôme 33-43) en panne"
                           class="form-control @error('title') is-invalid @enderror" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Ce qui s'est passé <span class="text-danger">*</span></label>
                    <textarea id="description" name="description" rows="4" maxlength="5000" class="form-control @error('description') is-invalid @enderror" required>{{ $value('description') }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="impact" class="form-label">Conséquence sur la collecte</label>
                    <textarea id="impact" name="impact" rows="2" maxlength="2000" placeholder="Ex. : 8 ménages de la sous-grappe 2 non visités, visite reportée à demain"
                              class="form-control @error('impact') is-invalid @enderror">{{ $value('impact') }}</textarea>
                    @error('impact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-4">
                    <label for="village_id" class="form-label">Village</label>
                    <select id="village_id" name="village_id" class="form-select @error('village_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($villages as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $village)
                                    <option value="{{ $village->id }}" @selected((int) $value('village_id') === $village->id)>{{ $village->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('village_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="cluster_id" class="form-label">Grappe</label>
                    <select id="cluster_id" name="cluster_id" class="form-select @error('cluster_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($clusters as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $cluster)
                                    <option value="{{ $cluster->id }}" @selected((int) $value('cluster_id') === $cluster->id)>{{ $cluster->code }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('cluster_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="collector_id" class="form-label">Agent concerné</label>
                    <select id="collector_id" name="collector_id" class="form-select @error('collector_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($collectors as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $collector)
                                    <option value="{{ $collector->id }}" @selected((int) $value('collector_id') === $collector->id)>{{ $collector->name }}{{ $collector->team ? ' (binôme '.$collector->team->code.')' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('collector_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ url()->previous() }}" class="btn btn-light">Annuler</a>
            <button class="btn btn-danger"><i class="bi bi-send me-1"></i>Enregistrer l'incident</button>
        </div>
    </form>
</x-layouts.app>
