import { escapeHtml } from '../lib/format';
import { postJson } from '../lib/http';

// Bouton « Tester la connexion » : affiche le résultat détaillé sans quitter la page.
export default function sourcesPage() {
    document.querySelectorAll('[data-test-connection]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.querySelector(button.dataset.target);
            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Test…';
            try {
                const result = await postJson(button.dataset.testConnection);
                renderResult(target, result);
            } catch (error) {
                renderResult(target, { ok: false, message: error.message, details: {} });
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    });

    // Formulaire : affiche les champs propres au type de source.
    const type = document.getElementById('source-type');
    if (type) {
        const toggle = () => document.querySelectorAll('[data-source-type]').forEach((el) => {
            el.classList.toggle('d-none', el.dataset.sourceType !== type.value);
            el.querySelectorAll('input').forEach((input) => {
                input.disabled = el.dataset.sourceType !== type.value;
            });
        });
        type.addEventListener('change', toggle);
        toggle();
    }
}

function renderResult(target, result) {
    if (!target) return;
    const details = Object.entries(result.details || {})
        .filter(([, v]) => v !== null && v !== undefined)
        .map(([k, v]) => `<li><strong>${escapeHtml(k)}</strong> : ${escapeHtml(v)}</li>`)
        .join('');
    target.innerHTML = `<div class="alert alert-${result.ok ? 'success' : 'danger'} mb-0">
        <i class="bi ${result.ok ? 'bi-check-circle' : 'bi-x-circle'} me-1"></i>${escapeHtml(result.message)}
        ${details ? `<ul class="mb-0 mt-2 small">${details}</ul>` : ''}
    </div>`;
}
