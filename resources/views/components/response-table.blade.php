{{--
    Récapitulatif des taux de réponse (rempli par lib/response-table.js) : une colonne par zone sanitaire et son bras,
    en français ou en anglais, bouton « Copier » pour le coller comme tableau natif dans PowerPoint.
--}}
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <span>Taux de réponse de l'enquête <span class="small text-muted">— tableau pour PowerPoint</span></span>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Langue du tableau">
                <input type="radio" class="btn-check" name="response-lang" id="response-lang-fr" value="fr" autocomplete="off" checked>
                <label class="btn btn-outline-secondary" for="response-lang-fr">Français</label>
                <input type="radio" class="btn-check" name="response-lang" id="response-lang-en" value="en" autocomplete="off">
                <label class="btn btn-outline-secondary" for="response-lang-en">English</label>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-copy-table="response-table" title="Copier le tableau (à coller dans PowerPoint, Word ou Excel)">
                <i class="bi bi-clipboard me-1"></i>Copier
            </button>
        </div>
    </div>
    <div class="card-body table-responsive">
        <div id="response-table"></div>
    </div>
    <div class="card-footer small text-muted">
        Taux de réponse des ménages = ménages ayant accepté de participer / ménages avec au moins un enfant de moins de 5 ans.
        Enfants de 12 à 35 mois : enfants éligibles enrôlés. Selon les filtres de la page.
    </div>
</div>
