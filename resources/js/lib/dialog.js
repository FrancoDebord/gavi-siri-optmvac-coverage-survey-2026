import { escapeHtml } from './format';

/**
 * Boîtes de dialogue et notifications (Bootstrap) à la place de window.confirm / alert :
 * confirmDialog() renvoie une promesse (true = confirmé), alertDialog() informe, toast() notifie.
 */
const VARIANTS = {
    primary: { icon: 'question-circle', button: 'btn-primary' },
    danger: { icon: 'exclamation-octagon', button: 'btn-danger' },
    warning: { icon: 'exclamation-triangle', button: 'btn-warning' },
    success: { icon: 'check-circle', button: 'btn-success' },
    info: { icon: 'info-circle', button: 'btn-primary' },
};

function modal({ title, message, html = null, variant = 'primary', confirmLabel = 'Confirmer', cancelLabel = 'Annuler', cancel = true }) {
    const { icon, button } = VARIANTS[variant] ?? VARIANTS.primary;
    const element = document.createElement('div');
    element.className = 'modal fade';
    element.tabIndex = -1;
    element.setAttribute('aria-hidden', 'true');
    element.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-dialog app-dialog-${variant}">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h6 d-flex align-items-center gap-2"><i class="bi bi-${icon} fs-4 text-${variant === 'primary' || variant === 'info' ? 'primary' : variant}"></i>${escapeHtml(title)}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">${html ?? `<p class="mb-0" style="white-space: pre-line">${escapeHtml(message)}</p>`}</div>
                <div class="modal-footer border-0 pt-0">
                    ${cancel ? `<button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">${escapeHtml(cancelLabel)}</button>` : ''}
                    <button type="button" class="btn ${button} btn-sm" data-dialog-ok>${escapeHtml(confirmLabel)}</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(element);
    const instance = new window.bootstrap.Modal(element);

    return new Promise((resolve) => {
        let result = false;
        element.querySelector('[data-dialog-ok]').addEventListener('click', () => {
            result = true;
            instance.hide();
        });
        element.addEventListener('shown.bs.modal', () => element.querySelector('[data-dialog-ok]').focus());
        element.addEventListener('hidden.bs.modal', () => {
            instance.dispose();
            element.remove();
            resolve(result);
        });
        instance.show();
    });
}

export function confirmDialog(options) {
    return modal({ title: 'Confirmation', ...options });
}

export function alertDialog(options) {
    return modal({ title: 'Information', confirmLabel: 'OK', cancel: false, ...options });
}

/** Notification discrète en haut à droite, fermée automatiquement. */
export function toast(message, variant = 'success') {
    let container = document.getElementById('app-toasts');
    if (!container) {
        container = document.createElement('div');
        container.id = 'app-toasts';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = 1090;
        document.body.appendChild(container);
    }
    const { icon } = VARIANTS[variant] ?? VARIANTS.success;
    const element = document.createElement('div');
    element.className = `toast align-items-center text-bg-${variant} border-0`;
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-${icon} me-2"></i>${escapeHtml(message)}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button></div>`;
    container.appendChild(element);
    const instance = new window.bootstrap.Toast(element, { delay: 5000 });
    element.addEventListener('hidden.bs.toast', () => element.remove());
    instance.show();
}

/**
 * Message de confirmation d'un formulaire : data-confirm, avec {nom} remplacé par le libellé
 * du choix sélectionné dans le champ « nom » (ex. {recipient}). data-confirm-when="status:resolu"
 * ne demande confirmation que pour cette valeur.
 */
function confirmationFor(form) {
    const template = form.dataset.confirm;
    if (!template) return null;
    const when = form.dataset.confirmWhen;
    if (when) {
        const [field, value] = when.split(':');
        if (form.elements[field]?.value !== value) return null;
    }

    return template.replace(/\{(\w+)\}/g, (match, name) => {
        const field = form.elements[name];
        if (!field) return match;
        return field.tagName === 'SELECT' ? (field.selectedOptions[0]?.textContent.trim() ?? '') : field.value;
    });
}

/** Confirmation des formulaires (data-confirm) et affichage des messages flash en boîte de dialogue. */
export function initDialogs() {
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (form.dataset.confirmed === '1') return;
        const message = confirmationFor(form);
        if (!message) return;
        event.preventDefault();
        const ok = await confirmDialog({
            title: form.dataset.confirmTitle || 'Confirmation',
            message,
            variant: form.dataset.confirmVariant || 'primary',
            confirmLabel: form.dataset.confirmLabel || 'Confirmer',
        });
        if (ok) {
            form.dataset.confirmed = '1';
            // requestSubmit conserve le bouton cliqué et la validation HTML.
            if (event.submitter && form.requestSubmit) form.requestSubmit(event.submitter);
            else form.submit();
        }
    });

    // Messages flash : succès en notification, avertissements et erreurs en boîte de dialogue.
    document.querySelectorAll('[data-flash]').forEach((alert) => {
        const variant = alert.dataset.flash;
        const message = alert.dataset.message;
        alert.remove();
        if (variant === 'success') {
            toast(message, 'success');
        } else {
            alertDialog({
                title: { warning: 'Attention', danger: 'Action impossible', info: 'Information' }[variant] ?? 'Information',
                message,
                variant,
            });
        }
    });
}
