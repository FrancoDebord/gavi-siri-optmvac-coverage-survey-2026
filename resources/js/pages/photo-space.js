import { alertDialog, toast } from '../lib/dialog';
import { escapeHtml } from '../lib/format';

/*
 * Espace photos public : les photos sont envoyées une par une (réseau mobile : un échec n'annule pas les autres),
 * avec une barre de progression. Une photo trop lourde pour le serveur, ou dans un format non accepté (HEIC), est
 * réduite en JPEG (3 000 px au plus) avant l'envoi. Binôme ou nom retenus sur l'appareil pour la fois suivante.
 */
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_SIDE = 3000;

const storage = {
    get(key) { try { return localStorage.getItem(`photo-space.${key}`); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(`photo-space.${key}`, value); } catch { /* stockage indisponible */ } },
};

/** Photo réduite en JPEG (null si le navigateur ne sait pas la lire). */
async function shrink(file) {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob) return null;

        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return null;
    }
}

/** Envoi d'une photo avec progression ; renvoie le message d'erreur, ou null si tout va bien. */
function send(form, file, onProgress) {
    const data = new FormData();
    ['_token', 'team_id', 'village_id', 'author_name', 'place', 'caption'].forEach((name) => {
        if (form.elements[name]) data.append(name, form.elements[name].value);
    });
    data.append('photos[]', file);
    data.append('taken_at[]', String(file.lastModified || ''));

    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); });
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) return resolve(null);
            let message = `Erreur ${xhr.status}`;
            try {
                const body = JSON.parse(xhr.responseText);
                message = Object.values(body.errors || {}).flat()[0] || body.message || message;
            } catch { /* réponse non JSON */ }
            if (xhr.status === 413) message = 'Photo trop lourde pour le serveur.';
            if (xhr.status === 419) message = 'Session expirée : rechargez la page.';
            resolve(message);
        });
        xhr.addEventListener('error', () => resolve('Connexion interrompue.'));
        xhr.send(data);
    });
}

export default function photoSpacePage() {
    const form = document.getElementById('photo-upload');
    if (!form) return;
    const maxBytes = Number(form.dataset.maxBytes || 0);
    const input = form.elements['photos[]'];
    const progress = document.getElementById('upload-progress');
    const bar = progress.querySelector('.progress-bar');
    const list = progress.querySelector('[data-upload-list]');
    const button = form.querySelector('[data-upload-submit]');

    // Binôme / nom de la fois précédente.
    form.querySelectorAll('[data-remember]').forEach((field) => {
        const saved = storage.get(field.dataset.remember);
        if (saved && !field.value) {
            field.value = saved;
            field.dispatchEvent(new Event('change'));
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const files = [...input.files];
        if (files.length > 20) {
            alertDialog({ title: 'Trop de photos', message: 'Envoyez au plus 20 photos à la fois.', variant: 'warning' });
            return;
        }
        form.querySelectorAll('[data-remember]').forEach((field) => storage.set(field.dataset.remember, field.value));

        button.disabled = true;
        progress.classList.remove('d-none');
        list.innerHTML = files.map((f, i) => `<li data-item="${i}"><i class="bi bi-hourglass me-1"></i>${escapeHtml(f.name)}</li>`).join('');
        let done = 0;
        let failed = 0;
        for (const [i, original] of files.entries()) {
            const item = list.querySelector(`[data-item="${i}"]`);
            const set = (icon, text, cls = '') => { item.className = cls; item.innerHTML = `<i class="bi bi-${icon} me-1"></i>${escapeHtml(original.name)}${text ? ` — ${escapeHtml(text)}` : ''}`; };
            let file = original;
            if (!ACCEPTED.includes(file.type) || (maxBytes && file.size > maxBytes * 0.95)) {
                set('arrows-angle-contract', 'réduction…');
                file = (await shrink(original)) ?? original;
            }
            set('arrow-up-circle', 'envoi…');
            const error = await send(form, file, (ratio) => { bar.style.width = `${((done + failed + ratio) / files.length) * 100}%`; });
            if (error) {
                failed++;
                set('x-circle', error, 'text-danger');
            } else {
                done++;
                set('check-circle', '', 'text-success');
            }
            bar.style.width = `${((done + failed) / files.length) * 100}%`;
        }
        button.disabled = false;
        if (!failed) {
            toast(`${done} photo(s) déposée(s). Merci !`);
            setTimeout(() => window.location.reload(), 900);
        } else {
            alertDialog({
                title: 'Envoi incomplet',
                message: `${done} photo(s) déposée(s), ${failed} en échec (détail sous le formulaire). Réessayez avec les photos en échec.`,
                variant: 'warning',
            });
        }
    });
}
