{{-- Ligne de correction d'une question de la query : $field (correctionField ou une de ses « related »), $i (index),
     $path (membre), $suggested (valeur lue sur la photo, ligne principale seulement), $paired (choix « quelle valeur est fausse »). --}}
@php
    $value = $suggested ?? $field['current'];
    if ($field['type'] === 'date' && $value !== null) {
        $value = substr($value, 0, 10);
    }
@endphp
<div class="border rounded p-2 mb-2 bg-light-subtle {{ $paired ? 'd-none' : '' }}" data-correction-row @if ($paired) data-pair-row="{{ $i }}" @endif>
    <input type="hidden" name="corrections[{{ $i }}][variable]" value="{{ $field['variable'] }}">
    <input type="hidden" name="corrections[{{ $i }}][instance_path]" value="{{ $path }}">
    @if ($paired)
        {{-- Une ligne inchangée (choix « les deux ») est ignorée ; motif partagé entre les lignes. --}}
        <input type="hidden" name="corrections[{{ $i }}][optional]" value="1">
        <input type="hidden" name="corrections[{{ $i }}][group]" value="paire">
    @endif
    <div class="small mb-1">
        @if ($paired)<strong>{{ $field['short_label'] ?? $field['label'] }}</strong> · @endif<code>{{ $field['variable'] }}</code>{{ $path ? ' · '.\App\Exports\QueriesExport::memberLabel($path) : '' }}
    </div>
    <div class="small text-muted mb-2">Valeur actuelle : <strong>{{ \App\Models\DataCorrection::displayFor($field['variable'], $field['current']) }}</strong>@if ($field['source'] !== $field['current']) · reçue d'ODK : {{ \App\Models\DataCorrection::displayFor($field['variable'], $field['source']) }}@endif</div>
    <label class="form-label small mb-0" for="c{{ $i }}-value">Nouvelle valeur</label>
    @if ($field['choices'] && $field['type'] === 'select_one')
        <select id="c{{ $i }}-value" name="corrections[{{ $i }}][new_value]" class="form-select form-select-sm mb-2">
            <option value="">(vide)</option>
            @foreach ($field['choices'] as $code => $label)
                <option value="{{ $code }}" @selected((string) $code === $value)>{{ $code }} – {{ strip_tags((string) $label) }}</option>
            @endforeach
        </select>
    @else
        <input id="c{{ $i }}-value" name="corrections[{{ $i }}][new_value]" class="form-control form-control-sm mb-2" value="{{ $value }}" @if ($field['type'] === 'date') type="date" @endif>
    @endif
    @if ($suggested !== null)<div class="form-text mt-n1 mb-2">Valeur lue sur la photo.</div>@endif
    @include('queries._correction-reason', ['i' => $i])
</div>
