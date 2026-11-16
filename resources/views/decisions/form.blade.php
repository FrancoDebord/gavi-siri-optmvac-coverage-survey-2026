@php
    $editing = $decision->exists;
    $value = fn (string $key) => old($key, $decision->{$key} instanceof \Carbon\CarbonInterface ? $decision->{$key}->toDateString() : $decision->{$key});
@endphp
<x-layouts.app :title="$editing ? 'Modifier '.$decision->code : 'Nouvelle décision'">
    <x-page-header :title="$editing ? 'Modifier la décision '.$decision->code : 'Nouvelle décision de terrain'" subtitle="Consignez la décision telle qu'elle a été prise, sa justification et à qui elle s'applique.">
        <a href="{{ $editing ? route('decisions.show', $decision) : route('decisions.index') }}" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Retour</a>
    </x-page-header>

    <form method="POST" action="{{ $editing ? route('decisions.update', $decision) : route('decisions.store') }}" class="card" style="max-width: 960px"
          @unless ($editing) data-confirm-title="Enregistrer la décision" data-confirm-label="Enregistrer" data-confirm="Enregistrer la décision « {title} » dans le registre ?" @endunless>
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($replaces)
            <input type="hidden" name="replaces_id" value="{{ $replaces->id }}">
            <div class="alert alert-info small m-3 mb-0"><i class="bi bi-arrow-repeat me-1"></i>Cette décision remplacera <strong>{{ $replaces->code }} – {{ $replaces->title }}</strong>, qui passera au statut « Remplacée ».</div>
        @endif
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="decision_category_id" class="form-label">Catégorie <span class="text-danger">*</span></label>
                    <select id="decision_category_id" name="decision_category_id" class="form-select @error('decision_category_id') is-invalid @enderror" required>
                        <option value="">Choisir…</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) $value('decision_category_id') === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('decision_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Catégorie manquante ? Ajoutez-la depuis le registre (« Catégories », bouton +).</div>
                </div>
                <div class="col-6 col-md-3">
                    <label for="decided_on" class="form-label">Date de la décision <span class="text-danger">*</span></label>
                    <input id="decided_on" type="date" name="decided_on" value="{{ $value('decided_on') }}" max="{{ now()->toDateString() }}" class="form-control @error('decided_on') is-invalid @enderror" required>
                    @error('decided_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label for="effective_from" class="form-label">Applicable à partir du</label>
                    <input id="effective_from" type="date" name="effective_from" value="{{ $value('effective_from') }}" class="form-control @error('effective_from') is-invalid @enderror">
                    @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="title" class="form-label">Titre <span class="text-danger">*</span></label>
                    <input id="title" name="title" value="{{ $value('title') }}" maxlength="200" class="form-control @error('title') is-invalid @enderror" required
                           placeholder="Ex. : Remplacement des ménages absents après deux visites">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="context" class="form-label">Contexte / justification</label>
                    <textarea id="context" name="context" rows="3" maxlength="5000" class="form-control @error('context') is-invalid @enderror"
                              placeholder="Situation rencontrée, problème, éléments qui ont motivé la décision…">{{ $value('context') }}</textarea>
                    @error('context')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="decision" class="form-label">Décision prise <span class="text-danger">*</span></label>
                    <textarea id="decision" name="decision" rows="4" maxlength="5000" class="form-control @error('decision') is-invalid @enderror" required
                              placeholder="Ce qui a été décidé, comment l'appliquer, qui est concerné…">{{ $value('decision') }}</textarea>
                    @error('decision')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label for="decided_by" class="form-label">Décidé par</label>
                    <input id="decided_by" name="decided_by" value="{{ $value('decided_by') }}" maxlength="150" class="form-control @error('decided_by') is-invalid @enderror">
                    @error('decided_by')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12"><h2 class="h6 text-muted mb-0 mt-1">Portée <span class="small fw-normal">(vide = toutes les zones)</span></h2></div>
                <div class="col-md-4">
                    <label for="health_zone_id" class="form-label small">Zone sanitaire</label>
                    <select id="health_zone_id" name="health_zone_id" class="form-select form-select-sm @error('health_zone_id') is-invalid @enderror">
                        <option value="">Toutes les zones</option>
                        @foreach ($zones as $zone)
                            <option value="{{ $zone->id }}" @selected((int) $value('health_zone_id') === $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="village_id" class="form-label small">Village</label>
                    <select id="village_id" name="village_id" class="form-select form-select-sm @error('village_id') is-invalid @enderror">
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
                    <label for="cluster_id" class="form-label small">Grappe</label>
                    <select id="cluster_id" name="cluster_id" class="form-select form-select-sm @error('cluster_id') is-invalid @enderror">
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

                <div class="col-12">
                    <input type="hidden" name="in_summary" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="in_summary" name="in_summary" value="1" @checked((bool) $value('in_summary'))>
                        <label class="form-check-label" for="in_summary">Afficher dans le rapport de synthèse du PI <span class="small text-muted">(décochez pour une décision interne ou de détail)</span></label>
                    </div>
                </div>

                @role(\App\Models\User::ROLE_ADMIN)
                    <div class="col-12">
                        <input type="hidden" name="is_confidential" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_confidential" name="is_confidential" value="1" @checked((bool) $value('is_confidential'))>
                            <label class="form-check-label" for="is_confidential"><i class="bi bi-lock me-1"></i>Confidentielle <span class="small text-muted">(visible des seuls administrateurs ; jamais dans le rapport de synthèse ni envoyée aux superviseurs)</span></label>
                        </div>
                    </div>
                @endrole

                @if ($editing)
                    <div class="col-md-4">
                        <label for="status" class="form-label">Statut</label>
                        <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                            @foreach (\App\Models\FieldDecision::STATUSES as $key => [$label])
                                <option value="{{ $key }}" @selected($value('status') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="replaced_by_id" class="form-label">Remplacée par <span class="small text-muted">(si statut « Remplacée »)</span></label>
                        <select id="replaced_by_id" name="replaced_by_id" class="form-select @error('replaced_by_id') is-invalid @enderror">
                            <option value="">—</option>
                            @foreach ($others as $other)
                                <option value="{{ $other->id }}" @selected((int) $value('replaced_by_id') === $other->id)>{{ $other->code }} · {{ $other->decided_on->format('d/m/Y') }} · {{ \Illuminate\Support\Str::limit($other->title, 70) }}</option>
                            @endforeach
                        </select>
                        @error('replaced_by_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @else
                    @can('send messages')
                        <div class="col-12">
                            <input type="hidden" name="notify" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify" name="notify" value="1" @checked(old('notify'))>
                                <label class="form-check-label" for="notify">Informer les superviseurs concernés (message d'information envoyé à la zone choisie, ou à toutes les équipes)</label>
                            </div>
                        </div>
                    @endcan
                @endif
            </div>
        </div>
        <div class="card-footer text-end">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        </div>
    </form>
</x-layouts.app>
