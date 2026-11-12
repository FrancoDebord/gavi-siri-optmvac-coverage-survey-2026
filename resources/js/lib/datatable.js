import DataTable from 'datatables.net-bs5';

export const LANGUAGE_FR = {
    processing: 'Traitement en cours…',
    search: 'Rechercher :',
    lengthMenu: 'Afficher _MENU_ lignes',
    info: 'Lignes _START_ à _END_ sur _TOTAL_',
    infoEmpty: 'Aucune ligne',
    infoFiltered: '(filtré de _MAX_ lignes au total)',
    loadingRecords: 'Chargement…',
    zeroRecords: 'Aucun résultat',
    emptyTable: 'Aucune donnée disponible',
    paginate: { first: 'Premier', previous: 'Précédent', next: 'Suivant', last: 'Dernier' },
    aria: { sortAscending: ': tri croissant', sortDescending: ': tri décroissant' },
};

export function dataTable(element, options = {}) {
    return new DataTable(element, {
        language: LANGUAGE_FR,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        ...options,
    });
}

export { DataTable };
