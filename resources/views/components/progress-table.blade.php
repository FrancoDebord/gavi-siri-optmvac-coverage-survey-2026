@props(['queriesUrl'])
{{-- Tableau hiérarchique Zone > Commune > Village > Grappe, rempli et replié/déplié par JS. --}}
<div class="table-responsive">
    <table id="hierarchy-table" class="table table-sm table-hover align-middle tree-table mb-0" data-queries-url="{{ $queriesUrl }}">
        <thead class="table-light">
        <tr>
            <th>Zone sanitaire › Commune › Village › Grappe</th>
            <th class="text-end">Visités</th>
            <th class="text-end">Ménages enquêtés</th>
            <th class="text-end">Caregivers</th>
            <th class="text-end">Enfants</th>
            <th class="text-end">Cible ménages</th>
            <th>% réalisé</th>
            <th class="text-end">Queries ouvertes</th>
        </tr>
        </thead>
        <tbody>
        <tr><td colspan="8" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Chargement…</td></tr>
        </tbody>
    </table>
</div>
