{{-- Ce qu'il faut corriger, en clair : $focus (QueryFocus::for), $compact pour les listes. --}}
@if ($compact ?? false)
    <div class="small mt-1 d-flex flex-wrap gap-2 align-items-center">
        @if ($focus['member'])<span class="text-muted"><i class="bi bi-person me-1"></i>{{ $focus['member'] }}@if ($focus['uri']) · <code class="small">{{ \Illuminate\Support\Str::limit($focus['uri'], 24) }}</code>@endif</span>@endif
        @if ($focus['variable'])
            <span>{{ $focus['whole_household'] ? 'Lu' : 'Saisi' }} : <span class="badge text-bg-danger-subtle text-danger-emphasis border text-wrap text-start">{{ $focus['raw'] ?? '(vide)' }}</span></span>
            @if ($focus['has_suggestion'])<span><i class="bi bi-arrow-right"></i> {{ $focus['is_ai'] ? 'IA' : ($focus['is_rule'] ? 'Proposé' : 'Photo') }} : <span class="badge {{ $focus['is_ai'] ? 'text-bg-info-subtle text-info-emphasis' : 'text-bg-success-subtle text-success-emphasis' }} border">{{ $focus['suggested'] }}</span></span>@endif
            @if ($focus['current'])<span class="text-muted">(corrigé : {{ $focus['current'] }})</span>@endif
        @endif
    </div>
@else
    <div class="card mb-3 border-2 border-primary-subtle">
        <div class="card-body">
            <div class="mb-2"><x-rule-badge :code="$query->rule_code" :name="$query->rule?->name" /></div>
            <div class="fw-semibold fs-6 mb-3"><i class="bi bi-exclamation-triangle text-danger me-1"></i>{{ $focus['problem'] }}</div>
            <dl class="row mb-0">
                @if ($focus['variable'])
                    <dt class="col-sm-4">Question</dt>
                    <dd class="col-sm-8">{{ $focus['question'] ?? $focus['variable'] }} <code class="small">{{ $focus['variable'] }}</code></dd>
                @endif
                <dt class="col-sm-4">Concerne</dt>
                <dd class="col-sm-8">
                    {{ $focus['member'] ?? 'Ménage' }} · ménage <strong>{{ $query->household_code ?? '—' }}</strong>
                    @if ($focus['uri'])<div class="small">URI du membre : <code class="user-select-all">{{ $focus['uri'] }}</code></div>@endif
                    @if ($focus['submission'])<div class="small">URI de la soumission : <code class="user-select-all">{{ $focus['submission'] }}</code></div>@endif
                    <div class="small">Compte ODK : @if ($focus['submitter'])<code class="user-select-all">{{ $focus['submitter'] }}</code>@else<span class="text-muted">non renseigné</span>@endif</div>
                </dd>
                @if ($focus['variable'])
                    <dt class="col-sm-4">{{ $focus['whole_household'] ? 'Valeurs lues (ODK)' : 'Valeur saisie (ODK)' }}</dt>
                    <dd class="col-sm-8">
                        @if ($focus['whole_household'])
                            <span class="fw-semibold text-danger-emphasis">{{ $focus['raw'] ?? '—' }}</span>
                            <div class="small text-muted">Pour corriger : « Corriger une autre question », choisir le membre puis la question.</div>
                        @else
                            <span class="badge fs-6 text-bg-danger-subtle text-danger-emphasis border">{{ $focus['raw'] ?? '(vide)' }}</span>
                        @endif
                    </dd>
                    @if ($focus['current'])
                        <dt class="col-sm-4">Valeur actuelle (corrigée)</dt>
                        <dd class="col-sm-8"><span class="badge fs-6 text-bg-light border">{{ $focus['current'] }}</span></dd>
                    @endif
                    @if ($focus['related'])
                        <dt class="col-sm-4">{{ $query->rule_code === 'MAJ_34' ? 'Réponses cochées (VAP 1 à 3, carte puis rappel)' : 'Aussi en cause' }}</dt>
                        <dd class="col-sm-8">
                            @foreach ($focus['related'] as $related)
                                <div>{{ $related['label'] }} <code class="small">{{ $related['variable'] }}</code> : <span class="badge fs-6 text-bg-warning-subtle text-warning-emphasis border">{{ \App\Models\DataCorrection::displayFor($related['variable'], $related['value'] === null ? null : (string) $related['value']) }}</span></div>
                            @endforeach
                            <div class="small text-muted">Vérifiez laquelle est fausse avant de corriger.</div>
                        </dd>
                    @endif
                    <dt class="col-sm-4">Valeur suggérée</dt>
                    <dd class="col-sm-8">
                        @if ($focus['has_suggestion'])
                            <span class="badge fs-6 {{ $focus['is_ai'] ? 'text-bg-info-subtle text-info-emphasis' : 'text-bg-success-subtle text-success-emphasis' }} border">{{ $focus['suggested'] }}</span>
                            <div class="small text-muted">{{ $focus['suggested_source'] }}{{ $focus['suggested_note'] ? ' · '.$focus['suggested_note'] : '' }}</div>
                        @else
                            <span class="text-muted">— à vérifier sur le document</span>
                        @endif
                    </dd>
                @endif
            </dl>
        </div>
    </div>
@endif
