import { dataTable } from '../lib/datatable';

// Consultation du dictionnaire : recherche plein texte + filtres type / groupe.
export default function dictionaryPage() {
    const table = document.getElementById('dictionary-table');
    if (!table) return;
    const dt = dataTable(table, { pageLength: 50, order: [[0, 'asc']] });

    const bind = (id, column) => {
        document.getElementById(id)?.addEventListener('change', (event) => {
            const value = event.target.value;
            dt.column(column).search(value ? `^${value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$` : '', { regex: true, smart: false }).draw();
        });
    };
    bind('filter-type', 2);
    bind('filter-repeat', 4);
}
