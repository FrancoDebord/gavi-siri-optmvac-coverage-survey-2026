/*
 * Visionneuse plein écran des photos de terrain (galeries [data-lightbox-gallery], liens [data-lightbox-item]).
 *
 * - fond noir, photo ajustée à l'écran : vignette affichée tout de suite, photo originale dès qu'elle est chargée ;
 *   photos voisines préchargées ;
 * - flèches, clavier (← → Échap, espace : diaporama, + − : zoom, F : plein écran), balayage sur téléphone
 *   (gauche / droite : photo suivante ou précédente, vers le bas : fermer) ;
 * - zoom : double clic ou double tape (à l'endroit touché), molette, puis glisser pour se déplacer ;
 * - diaporama (4 s), plein écran, bande de vignettes, légende (auteur, lieu, date, carte) ;
 * - interface masquée après 3 s d'inactivité ; bouton « retour » du téléphone : ferme la visionneuse.
 */
const SLIDE_MS = 4000;
const IDLE_MS = 3000;
const MAX_SCALE = 4;

const icon = (name) => `<i class="bi bi-${name}"></i>`;
const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

let box = null;
let items = [];
let index = 0;
let opener = null;
let slideTimer = null;
let idleTimer = null;
let ownHistory = false;
const view = { scale: 1, x: 0, y: 0 };

function build() {
    box = document.createElement('div');
    box.className = 'lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Visionneuse de photos');
    box.innerHTML = `
        <div class="lightbox-bar">
            <span class="lightbox-counter" aria-live="polite"></span>
            <div class="lightbox-actions">
                <button type="button" data-lb="slideshow" title="Diaporama (espace)">${icon('play-fill')}</button>
                <button type="button" data-lb="zoom" title="Zoom (double clic)">${icon('zoom-in')}</button>
                <a data-lb="map" target="_blank" rel="noopener noreferrer" title="Voir sur la carte">${icon('pin-map')}</a>
                <a data-lb="download" title="Télécharger la photo originale">${icon('download')}</a>
                <button type="button" data-lb="fullscreen" title="Plein écran (F)">${icon('arrows-fullscreen')}</button>
                <button type="button" data-lb="close" title="Fermer (Échap)">${icon('x-lg')}</button>
            </div>
        </div>
        <div class="lightbox-progress"><span></span></div>
        <div class="lightbox-stage">
            <img class="lightbox-image" alt="" draggable="false">
            <div class="lightbox-spinner spinner-border text-light" role="status"><span class="visually-hidden">Chargement…</span></div>
        </div>
        <button type="button" class="lightbox-nav lightbox-prev" data-lb="prev" title="Photo précédente (←)">${icon('chevron-left')}</button>
        <button type="button" class="lightbox-nav lightbox-next" data-lb="next" title="Photo suivante (→)">${icon('chevron-right')}</button>
        <div class="lightbox-caption"></div>
        <div class="lightbox-strip"></div>`;
    document.body.appendChild(box);

    box.addEventListener('click', (event) => {
        const action = event.target.closest('[data-lb]')?.dataset.lb;
        const thumb = event.target.closest('[data-lb-index]');
        if (thumb) return show(Number(thumb.dataset.lbIndex));
        if (!action) {
            // Clic sur le fond noir (hors photo) : fermer.
            if (event.target.classList.contains('lightbox-stage')) close();
            return;
        }
        if (action === 'prev') show(index - 1);
        if (action === 'next') show(index + 1);
        if (action === 'close') close();
        if (action === 'slideshow') toggleSlideshow();
        if (action === 'fullscreen') toggleFullscreen();
        if (action === 'zoom') zoomTo(view.scale > 1 ? 1 : 2.5);
    });
    ['mousemove', 'pointerdown', 'keydown'].forEach((type) => box.addEventListener(type, wake));
    bindGestures(box.querySelector('.lightbox-stage'));
    document.addEventListener('fullscreenchange', () => {
        box.querySelector('[data-lb="fullscreen"]').innerHTML = icon(document.fullscreenElement ? 'fullscreen-exit' : 'arrows-fullscreen');
    });
}

function open(list, start, trigger) {
    if (!box) build();
    items = list;
    opener = trigger;
    box.querySelector('.lightbox-strip').innerHTML = items.map((item, i) => `
        <button type="button" data-lb-index="${i}" aria-label="Photo ${i + 1}"><img src="${escapeHtml(item.thumb)}" alt="" loading="lazy"></button>`).join('');
    box.classList.add('is-open');
    document.body.classList.add('lightbox-lock');
    document.addEventListener('keydown', onKey);
    // Bouton « retour » du téléphone : ferme la visionneuse au lieu de quitter la page.
    history.pushState({ lightbox: true }, '');
    ownHistory = true;
    window.addEventListener('popstate', onPopState);
    show(start);
    wake();
    box.querySelector('[data-lb="close"]').focus({ preventScroll: true });
}

function close() {
    if (!box?.classList.contains('is-open')) return;
    stopSlideshow();
    if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
    box.classList.remove('is-open', 'is-idle');
    document.body.classList.remove('lightbox-lock');
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('popstate', onPopState);
    if (ownHistory) {
        ownHistory = false;
        history.back();
    }
    opener?.focus({ preventScroll: true });
}

function onPopState() {
    ownHistory = false;
    close();
}

function show(i) {
    if (!items.length) return;
    const direction = i > index ? 1 : -1;
    index = (i + items.length) % items.length;
    const item = items[index];
    const img = box.querySelector('.lightbox-image');
    resetZoom();

    // Vignette tout de suite (floue), photo originale dès qu'elle est chargée.
    box.classList.add('is-loading');
    img.classList.remove('is-entering-left', 'is-entering-right');
    void img.offsetWidth; // relance l'animation d'entrée
    img.classList.add(direction > 0 ? 'is-entering-right' : 'is-entering-left');
    img.src = item.thumb;
    img.alt = item.caption || 'Photo de terrain';
    const full = new Image();
    full.onload = () => {
        if (items[index] !== item) return;
        img.src = item.full;
        box.classList.remove('is-loading');
    };
    full.onerror = () => box.classList.remove('is-loading');
    full.src = item.full;
    [index + 1, index - 1].forEach((n) => { new Image().src = items[(n + items.length) % items.length].full; });

    box.querySelector('.lightbox-counter').textContent = `${index + 1} / ${items.length}`;
    const download = box.querySelector('[data-lb="download"]');
    download.href = item.full;
    download.setAttribute('download', item.download || '');
    const map = box.querySelector('[data-lb="map"]');
    map.hidden = !item.map;
    if (item.map) map.href = item.map;
    box.querySelector('.lightbox-caption').innerHTML = `
        <div class="lightbox-caption-meta"><strong>${escapeHtml(item.author)}</strong>${item.place ? ` · ${icon('geo-alt')} ${escapeHtml(item.place)}` : ''} · ${escapeHtml(item.date)}</div>
        ${item.caption ? `<div class="lightbox-caption-text">${escapeHtml(item.caption)}</div>` : ''}`;
    box.querySelectorAll('.lightbox-nav').forEach((b) => { b.hidden = items.length < 2; });

    const strip = box.querySelector('.lightbox-strip');
    strip.querySelectorAll('[data-lb-index]').forEach((b) => b.classList.toggle('is-active', Number(b.dataset.lbIndex) === index));
    strip.querySelector('.is-active')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
    if (slideTimer) restartProgress();
}

function onKey(event) {
    const keys = {
        ArrowLeft: () => show(index - 1),
        ArrowRight: () => show(index + 1),
        Escape: () => close(),
        ' ': () => toggleSlideshow(),
        f: () => toggleFullscreen(),
        F: () => toggleFullscreen(),
        '+': () => zoomTo(view.scale * 1.5),
        '=': () => zoomTo(view.scale * 1.5),
        '-': () => zoomTo(view.scale / 1.5),
    };
    if (keys[event.key] && !event.target.closest('a')) {
        event.preventDefault();
        keys[event.key]();
    }
}

/* Interface masquée après quelques secondes d'inactivité. */
function wake() {
    box.classList.remove('is-idle');
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => box.classList.add('is-idle'), IDLE_MS);
}

/* Diaporama */
function toggleSlideshow() {
    if (slideTimer) {
        stopSlideshow();
    } else {
        slideTimer = setInterval(() => show(index + 1), SLIDE_MS);
        box.classList.add('is-playing');
        box.querySelector('[data-lb="slideshow"]').innerHTML = icon('pause-fill');
        restartProgress();
    }
}

function stopSlideshow() {
    clearInterval(slideTimer);
    slideTimer = null;
    box?.classList.remove('is-playing');
    if (box) box.querySelector('[data-lb="slideshow"]').innerHTML = icon('play-fill');
}

function restartProgress() {
    clearInterval(slideTimer);
    slideTimer = setInterval(() => show(index + 1), SLIDE_MS);
    const bar = box.querySelector('.lightbox-progress span');
    bar.style.transition = 'none';
    bar.style.width = '0%';
    void bar.offsetWidth;
    bar.style.transition = `width ${SLIDE_MS}ms linear`;
    bar.style.width = '100%';
}

function toggleFullscreen() {
    if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
    else box.requestFullscreen?.().catch(() => {});
}

/* Zoom et déplacement */
function apply(animate = true) {
    const img = box.querySelector('.lightbox-image');
    img.style.transition = animate ? 'transform .25s ease' : 'none';
    img.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    box.classList.toggle('is-zoomed', view.scale > 1);
    box.querySelector('[data-lb="zoom"]').innerHTML = icon(view.scale > 1 ? 'zoom-out' : 'zoom-in');
}

function resetZoom() {
    Object.assign(view, { scale: 1, x: 0, y: 0 });
    if (box) apply(false);
}

/** Zoom vers un point de l'écran (centre par défaut), en gardant ce point sous le doigt. */
function zoomTo(scale, clientX = window.innerWidth / 2, clientY = window.innerHeight / 2) {
    const next = Math.min(MAX_SCALE, Math.max(1, scale));
    if (next === 1) {
        Object.assign(view, { scale: 1, x: 0, y: 0 });
        apply();
        return;
    }
    const stage = box.querySelector('.lightbox-stage').getBoundingClientRect();
    const cx = clientX - (stage.left + stage.width / 2);
    const cy = clientY - (stage.top + stage.height / 2);
    view.x = cx - ((cx - view.x) * next) / view.scale;
    view.y = cy - ((cy - view.y) * next) / view.scale;
    view.scale = next;
    if (slideTimer) stopSlideshow();
    apply();
}

function bindGestures(stage) {
    let start = null;
    let lastTap = 0;
    let lastPointer = 'mouse';
    const img = stage.querySelector('.lightbox-image');

    // Double clic à la souris (le double tape est géré à part pour le tactile).
    stage.addEventListener('dblclick', (e) => {
        if (lastPointer !== 'touch') zoomTo(view.scale > 1 ? 1 : 2.5, e.clientX, e.clientY);
    });
    // Le fond noir ferme la visionneuse, mais pas un clic sur la photo ni la fin d'un glissement (pendant un geste,
    // le pointeur est capturé par la scène : le clic semble toujours venir du fond).
    stage.addEventListener('click', (e) => {
        if (stage.dataset.moved === '1' || stage.dataset.downOn !== 'stage') {
            e.stopPropagation();
            stage.dataset.moved = '0';
        }
    }, true);
    stage.addEventListener('wheel', (e) => {
        e.preventDefault();
        zoomTo(view.scale * (e.deltaY < 0 ? 1.25 : 0.8), e.clientX, e.clientY);
    }, { passive: false });

    stage.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        e.preventDefault(); // pas de sélection de texte ni de glisser-déposer de l'image
        lastPointer = e.pointerType;
        stage.dataset.moved = '0';
        stage.dataset.downOn = e.target === stage ? 'stage' : 'image';
        // Double tape (téléphone) : zoom.
        if (e.pointerType === 'touch') {
            const now = Date.now();
            if (now - lastTap < 300) {
                zoomTo(view.scale > 1 ? 1 : 2.5, e.clientX, e.clientY);
                lastTap = 0;
                return;
            }
            lastTap = now;
        }
        start = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y, t: Date.now() };
        stage.setPointerCapture(e.pointerId);
    });
    stage.addEventListener('pointermove', (e) => {
        if (!start) return;
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;
        if (Math.abs(dx) + Math.abs(dy) > 6) stage.dataset.moved = '1';
        if (view.scale > 1) {
            view.x = start.vx + dx;
            view.y = start.vy + dy;
            apply(false);
        } else {
            // Photo qui suit le doigt : horizontal (changer de photo) ou vers le bas (fermer).
            const down = Math.abs(dy) > Math.abs(dx) && dy > 0 ? dy : 0;
            img.style.transition = 'none';
            img.style.transform = down ? `translateY(${down}px) scale(${1 - Math.min(down, 300) / 1200})` : `translateX(${dx}px)`;
            box.style.backgroundColor = down ? `rgba(6, 6, 6, ${Math.max(0.35, 1 - down / 400)})` : '';
        }
    });
    const end = (e) => {
        if (!start) return;
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;
        const fast = Date.now() - start.t < 300;
        start = null;
        box.style.backgroundColor = '';
        if (view.scale > 1) return;
        if (dy > 120 && Math.abs(dy) > Math.abs(dx)) return close();
        if (Math.abs(dx) > (fast ? 40 : 90) && Math.abs(dx) > Math.abs(dy)) return show(index + (dx < 0 ? 1 : -1));
        apply();
    };
    stage.addEventListener('pointerup', end);
    stage.addEventListener('pointercancel', end);
}

/** Galeries de la page : clic sur une vignette = visionneuse (sauf clic du milieu, Ctrl / Cmd). */
export function initLightbox() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-lightbox-item]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
        const gallery = link.closest('[data-lightbox-gallery]') ?? document;
        const links = [...gallery.querySelectorAll('[data-lightbox-item]')];
        event.preventDefault();
        open(links.map((a) => ({
            full: a.href,
            thumb: a.dataset.thumb || a.href,
            author: a.dataset.author,
            place: a.dataset.place,
            date: a.dataset.date,
            caption: a.dataset.caption,
            map: a.dataset.map,
            download: a.dataset.download,
        })), links.indexOf(link), link);
    });
}
