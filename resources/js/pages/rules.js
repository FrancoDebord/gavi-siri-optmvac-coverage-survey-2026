import { escapeHtml } from '../lib/format';
import { postJson } from '../lib/http';

// Formulaire de règle : champs propres aux expressions + test de l'expression sur les données.
export default function rulesPage() {
    const category = document.getElementById('rule-category');
    if (category) {
        const toggle = () => document.querySelectorAll('[data-expression-only]').forEach((el) => {
            el.classList.toggle('d-none', category.value !== 'expression');
        });
        category.addEventListener('change', toggle);
        toggle();
    }

    const button = document.getElementById('test-expression');
    button?.addEventListener('click', async () => {
        const output = document.getElementById('test-expression-result');
        const form = button.closest('form');
        button.disabled = true;
        try {
            const result = await postJson(button.dataset.url, {
                expression: form.querySelector('[name="expression"]').value,
                scope: form.querySelector('[name="scope"]').value,
            });
            output.innerHTML = `<div class="alert alert-${result.ok ? 'info' : 'danger'} small mb-0">${escapeHtml(result.message)}
                ${(result.examples || []).length ? `<ul class="mb-0 mt-1">${result.examples.map((e) => `<li>${escapeHtml(e)}</li>`).join('')}</ul>` : ''}</div>`;
        } catch (error) {
            output.innerHTML = `<div class="alert alert-danger small mb-0">${escapeHtml(error.message)}</div>`;
        } finally {
            button.disabled = false;
        }
    });
}
