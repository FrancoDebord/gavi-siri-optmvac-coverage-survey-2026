import { initResponseTable } from '../lib/response-table';

/** Espace de l'investigateur principal, « Taux de réponse » : récapitulatif calculé côté serveur (zone, période). */
export default function piResponsePage() {
    const root = document.getElementById('pi-response');
    if (!root) return;
    initResponseTable(root, JSON.parse(document.getElementById('response-data')?.textContent || '[]'));
}
