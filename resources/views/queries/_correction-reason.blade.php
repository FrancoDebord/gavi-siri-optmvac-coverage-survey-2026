{{-- Motif et source d'une ligne de correction ($i : index, ou « __i__ » dans le gabarit). --}}
<div class="row g-2">
    <div class="col-sm-6">
        <label class="form-label small mb-0">Motif</label>
        <select name="corrections[{{ $i }}][reason]" class="form-select form-select-sm">
            <option value="">— Choisir —</option>
            @foreach ($reasons as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-sm-6">
        <label class="form-label small mb-0">Référence de la source</label>
        <input name="corrections[{{ $i }}][source_reference]" class="form-control form-control-sm" maxlength="255" placeholder="ex. photo carnet, visite du 12/11">
    </div>
    <div class="col-12">
        <input name="corrections[{{ $i }}][comment]" class="form-control form-control-sm" maxlength="2000" placeholder="Commentaire (obligatoire si motif « autre »)">
    </div>
</div>
