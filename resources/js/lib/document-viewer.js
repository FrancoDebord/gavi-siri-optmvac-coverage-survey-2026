import { toast } from './dialog';

/*
 * Visionneuse plein écran des factures et rapports d'activité (liens [data-doc-item] d'une liste [data-doc-viewer]).
 *
 * - photo du document : ajustée à l'écran, zoom au clic (taille réelle, on fait défiler) ;
 * - PDF : affiché dans la visionneuse (sur téléphone, bouton pour l'ouvrir dans le lecteur PDF) ;
 * - Word, Excel : fiche avec bouton de téléchargement (aperçu impossible dans le navigateur) ;
 * - flèches, clavier (← → Échap), balayage sur téléphone ; bande des documents en bas ;
 * - validation ou demande de correction (motif) sans quitter la visionneuse ; la page est rechargée à la fermeture
 *   si un statut a changé.
 */
const icon = (name) => `<i class="bi bi-${name}"></i>`;
const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const STATUS_CLASS = { valide: 'text-bg-success', a_refaire: 'text-bg-danger', recu: 'text-bg-secondary' };
const touchDevice = () => window.matchMedia('(pointer: coarse)').matches;

let box = null;
let items = [];
let index = 0;
let opener = null;
let changed = false;
let ownHistory = false;

function build() {
    box = document.createElement('div');
    box.className = 'lightbox doc-viewer';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Visionneuse de documents');
    box.innerHTML = `
        <div class="lightbox-bar">
            <span class="doc-viewer-title"><span class="lightbox-counter" aria-live="polite"></span> <span data-dv="name"></span></span>
            <div class="lightbox-actions">
                <a data-dv="open" target="_blank" rel="noopener" title="Ouvrir dans un nouvel onglet">${icon('box-arrow-up-right')}</a>
                <a data-dv="download" title="Télécharger">${icon('download')}</a>
                <button type="button" data-dv="close" title="Fermer (Échap)">${icon('x-lg')}</button>
            </div>
        </div>
        <div class="doc-viewer-stage" data-dv="stage"></div>
        <button type="button" class="lightbox-nav lightbox-prev" data-dv="prev" title="Document précédent (←)">${icon('chevron-left')}</button>
        <button type="button" class="lightbox-nav lightbox-next" data-dv="next" title="Document suivant (→)">${icon('chevron-right')}</button>
        <div class="doc-viewer-panel">
            <div data-dv="meta"></div>
            <div class="doc-viewer-review">
                <button type="button" class="btn btn-sm btn-success" data-dv="validate">${icon('check-lg')} Valider</button>
                <button type="button" class="btn btn-sm btn-warning" data-dv="redo-toggle">${icon('arrow-counterclockwise')} À refaire</button>
                <form class="doc-viewer-redo" data-dv="redo-form" hidden>
                    <input type="text" maxlength="500" required class="form-control form-control-sm" placeholder="Ce qu'il faut corriger" data-dv="note">
                    <button class="btn btn-sm btn-warning">Envoyer</button>
                </form>
            </div>
            <div class="lightbox-strip doc-viewer-strip"></div>
        </div>`;
    document.body.appendChild(box);

    box.addEventListener('click', (event) => {
        const thumb = event.target.closest('[data-dv-index]');
        if (thumb) return show(Number(thumb.dataset.dvIndex));
        const action = event.target.closest('[data-dv]')?.dataset.dv;
        if (action === 'prev') show(index - 1);
        if (action === 'next') show(index + 1);
        if (action === 'close') close();
        if (action === 'validate') review('valide');
        if (action === 'redo-toggle') {
            const form = box.querySelector('[data-dv="redo-form"]');
            form.hidden = !form.hidden;
            if (!form.hidden) form.querySelector('input').focus();
        }
        if (action === 'stage' && event.target.dataset.dv === 'stage') close();
        if (event.target.classList.contains('doc-viewer-image')) event.target.classList.toggle('is-actual');
    });
    box.querySelector('[data-dv="redo-form"]').addEventListener('submit', (event) => {
        event.preventDefault();
        review('a_refaire', box.querySelector('[data-dv="note"]').value.trim());
    });
    bindSwipe(box.querySelector('[data-dv="stage"]'));
}

function open(list, start, trigger) {
    if (!box) build();
    items = list;
    opener = trigger;
    changed = false;
    box.querySelector('.doc-viewer-strip').innerHTML = items.map((item, i) => `
        <button type="button" data-dv-index="${i}" title="${escapeHtml(item.author)} · ${escapeHtml(item.type)}">
            ${item.kind === 'image' ? `<img src="${escapeHtml(item.url)}" alt="" loading="lazy">` : `<span class="doc-viewer-chip">${icon(item.icon)}</span>`}
        </button>`).join('');
    box.classList.add('is-open');
    document.body.classList.add('lightbox-lock');
    document.addEventListener('keydown', onKey);
    history.pushState({ docViewer: true }, '');
    ownHistory = true;
    window.addEventListener('popstate', onPopState);
    show(start);
    box.querySelector('[data-dv="close"]').focus({ preventScroll: true });
}

function close() {
    if (!box?.classList.contains('is-open')) return;
    box.classList.remove('is-open');
    box.querySelector('[data-dv="stage"]').innerHTML = ''; // arrête le chargement du PDF
    document.body.classList.remove('lightbox-lock');
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('popstate', onPopState);
    if (ownHistory) {
        ownHistory = false;
        history.back();
    }
    if (changed) {
        // Statuts modifiés : la liste et le suivi par agent sont rechargés.
        setTimeout(() => window.location.reload(), 50);
        return;
    }
    opener?.focus({ preventScroll: true });
}

function onPopState() {
    ownHistory = false;
    close();
}

function show(i) {
    if (!items.length) return;
    index = (i + items.length) % items.length;
    const item = items[index];
    const stage = box.querySelector('[data-dv="stage"]');

    if (item.kind === 'image') {
        stage.innerHTML = `<img class="doc-viewer-image" src="${escapeHtml(item.url)}" alt="${escapeHtml(item.name)}" title="Cliquer pour zoomer">`;
    } else if (item.kind === 'pdf' && !touchDevice()) {
        stage.innerHTML = `<iframe class="doc-viewer-pdf" src="${escapeHtml(item.url)}#view=FitH" title="${escapeHtml(item.name)}"></iframe>`;
    } else {
        // Word, Excel, ou PDF sur téléphone (pas de lecteur PDF intégré fiable) : fiche du fichier.
        stage.innerHTML = `
            <div class="doc-viewer-file">
                <div class="doc-viewer-file-icon">${icon(item.icon)}</div>
                <div class="fw-semibold">${escapeHtml(item.name)}</div>
                <div class="small opacity-75 mb-3">${escapeHtml(item.size)}${item.kind === 'other' ? ' · aperçu impossible dans le navigateur' : ''}</div>
                ${item.kind === 'pdf'
                    ? `<a class="btn btn-light" href="${escapeHtml(item.url)}" target="_blank" rel="noopener">${icon('file-earmark-pdf')} Ouvrir le PDF</a>`
                    : `<a class="btn btn-light" href="${escapeHtml(item.download)}">${icon('download')} Télécharger</a>`}
            </div>`;
    }
    // Images voisines préchargées.
    [index + 1, index - 1].map((n) => items[(n + items.length) % items.length]).filter((n) => n.kind === 'image').forEach((n) => { new Image().src = n.url; });

    box.querySelector('.lightbox-counter').textContent = `${index + 1} / ${items.length}`;
    box.querySelector('[data-dv="name"]').textContent = item.name;
    box.querySelector('[data-dv="open"]').href = item.url;
    box.querySelector('[data-dv="download"]').href = item.download;
    box.querySelectorAll('.lightbox-nav').forEach((b) => { b.hidden = items.length < 2; });
    renderMeta();

    const strip = box.querySelector('.doc-viewer-strip');
    strip.querySelectorAll('[data-dv-index]').forEach((b) => b.classList.toggle('is-active', Number(b.dataset.dvIndex) === index));
    strip.querySelector('.is-active')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
}

function renderMeta() {
    const item = items[index];
    box.querySelector('[data-dv="meta"]').innerHTML = `
        <div><strong>${escapeHtml(item.type)}</strong> · ${escapeHtml(item.author)}${item.team ? ` · ${escapeHtml(item.team)}` : ''}
            <span class="badge ${STATUS_CLASS[item.status] || 'text-bg-secondary'} ms-1">${escapeHtml(item.statusLabel)}</span></div>
        <div class="small opacity-75">Déposé le ${escapeHtml(item.date)} · ${escapeHtml(item.size)}${item.comment ? ` · « ${escapeHtml(item.comment)} »` : ''}</div>
        ${item.note ? `<div class="small text-warning">${icon('exclamation-circle')} ${escapeHtml(item.note)}</div>` : ''}`;
    box.querySelector('[data-dv="validate"]').hidden = item.status === 'valide';
    const form = box.querySelector('[data-dv="redo-form"]');
    form.hidden = true;
    form.querySelector('input').value = item.status === 'a_refaire' ? item.note : '';
}

/** Validation ou demande de correction (route de revue, réponse JSON). */
async function review(status, note = '') {
    const item = items[index];
    const data = new FormData();
    data.append('_token', document.querySelector('meta[name="csrf-token"]').content);
    data.append('_method', 'PUT');
    data.append('status', status);
    if (note) data.append('review_note', note);
    try {
        const response = await fetch(item.review, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            toast(Object.values(body.errors || {}).flat()[0] || body.message || `Erreur ${response.status}`, 'danger');
            return;
        }
        Object.assign(item, { status: body.status, statusLabel: body.label, note: body.note || '' });
        changed = true;
        renderMeta();
        toast(`${item.type} de ${item.author} : ${body.label.toLowerCase()}.`);
        // Validé : document suivant encore à traiter, s'il y en a.
        if (status === 'valide') {
            const next = items.findIndex((d, i) => i > index && d.status !== 'valide');
            if (next !== -1) setTimeout(() => show(next), 400);
        }
    } catch {
        toast('Connexion interrompue.', 'danger');
    }
}

function onKey(event) {
    if (event.target.closest('input, textarea')) {
        if (event.key === 'Escape') event.target.blur();
        return;
    }
    const keys = { ArrowLeft: () => show(index - 1), ArrowRight: () => show(index + 1), Escape: () => close() };
    if (keys[event.key]) {
        event.preventDefault();
        keys[event.key]();
    }
}

/** Balayage gauche / droite : document suivant ou précédent (sauf image zoomée). */
function bindSwipe(stage) {
    let start = null;
    stage.addEventListener('touchstart', (e) => { start = e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null; }, { passive: true });
    stage.addEventListener('touchend', (e) => {
        if (!start || stage.querySelector('.is-actual')) return;
        const dx = e.changedTouches[0].clientX - start.x;
        const dy = e.changedTouches[0].clientY - start.y;
        start = null;
        if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) show(index + (dx < 0 ? 1 : -1));
    });
}

/** Listes de documents : clic sur un lien [data-doc-item] = visionneuse (sauf clic du milieu, Ctrl / Cmd). */
export function initDocumentViewer() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-doc-item], [data-doc-open-all]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
        const list = document.querySelector('[data-doc-viewer]');
        const links = list ? [...list.querySelectorAll('[data-doc-item]')] : [];
        if (!links.length) return;
        event.preventDefault();
        const start = link.hasAttribute('data-doc-item') ? links.indexOf(link) : 0;
        open(links.map((a) => ({
            url: a.href,
            download: a.dataset.download,
            review: a.dataset.review,
            kind: a.dataset.kind,
            icon: a.dataset.icon,
            name: a.dataset.name,
            type: a.dataset.type,
            author: a.dataset.author,
            team: a.dataset.team,
            date: a.dataset.date,
            size: a.dataset.size,
            comment: a.dataset.comment,
            status: a.dataset.status,
            statusLabel: a.dataset.statusLabel,
            note: a.dataset.note || '',
        })), Math.max(0, start), link);
    });
}
