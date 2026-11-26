/**
 * Listes déroulantes avec recherche.
 *
 * Chaque <select class="form-select"> d'au moins MIN_OPTIONS options est doublé d'un bouton qui ouvre une liste
 * filtrable : on tape quelques lettres (sans se soucier des majuscules ni des accents) et on choisit à la souris ou
 * au clavier (↑ ↓ Entrée, Échap). Le <select> d'origine reste dans la page et reste la source de vérité : envoi des
 * formulaires, événements « change » (filtres en cascade, onchange="this.form.submit()"), validation « required ».
 *
 * - data-search="true"  : recherche toujours proposée, même pour une liste courte ;
 * - data-search="false" : jamais (liste laissée telle quelle).
 * Les listes remplies plus tard (cascade zone › commune › village…) sont équipées dès qu'elles s'allongent.
 */

const MIN_OPTIONS = 8;
const enhanced = new WeakMap();
const nativeValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
const nativeIndex = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');

const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Libellé avec les termes recherchés surlignés (comparaison sans accents). */
function highlight(label, terms) {
    if (!terms.length) return escapeHtml(label);
    const plain = normalize(label);
    // Les caractères décomposés (é → e + accent) gardent la même longueur une fois normalisés, sauf ligatures rares.
    if (plain.length !== label.length) return escapeHtml(label);
    const marks = new Array(label.length).fill(false);
    terms.forEach((term) => {
        let from = 0;
        let at;
        while (term && (at = plain.indexOf(term, from)) !== -1) {
            for (let i = at; i < at + term.length; i++) marks[i] = true;
            from = at + term.length;
        }
    });
    let html = '';
    let open = false;
    [...label].forEach((char, i) => {
        if (marks[i] && !open) { html += '<mark>'; open = true; }
        if (!marks[i] && open) { html += '</mark>'; open = false; }
        html += escapeHtml(char);
    });

    return html + (open ? '</mark>' : '');
}

function wanted(select) {
    if (select.multiple || select.dataset.search === 'false' || select.closest('[data-search="false"]')) return false;
    if (select.dataset.search === 'true') return true;
    return select.classList.contains('form-select') && select.options.length >= MIN_OPTIONS;
}

function enhance(select) {
    if (enhanced.has(select)) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'ss';
    // Les classes de mise en page du select (w-auto, w-100, me-2…) passent au conteneur ; le style au bouton.
    const layout = [...select.classList].filter((c) => /^(w-|mw-|flex-|ms-|me-|mx-|mt-|mb-|my-|col)/.test(c));
    wrapper.classList.add(...layout);
    if (select.style.width) wrapper.style.width = select.style.width;
    if (select.style.minWidth) wrapper.style.minWidth = select.style.minWidth;
    if (select.style.maxWidth) wrapper.style.maxWidth = select.style.maxWidth;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = [...select.classList].filter((c) => !layout.includes(c)).join(' ') + ' ss-toggle text-start';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');

    const panel = document.createElement('div');
    panel.className = 'ss-panel shadow';
    panel.hidden = true;
    panel.innerHTML = `
        <div class="ss-search p-2 border-bottom">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" placeholder="Rechercher…" autocomplete="off" spellcheck="false" aria-label="Rechercher dans la liste">
            </div>
        </div>
        <ul class="ss-list list-unstyled mb-0" role="listbox"></ul>
        <div class="ss-empty small text-muted px-3 py-2" hidden>Aucun résultat</div>`;
    const input = panel.querySelector('input');
    const list = panel.querySelector('.ss-list');
    const empty = panel.querySelector('.ss-empty');

    // Le select est masqué sans être retiré : formulaires, validation et lecteurs d'écran (via le bouton) inchangés.
    select.parentNode.insertBefore(wrapper, select);
    wrapper.append(select, button, panel);
    select.classList.add('ss-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    const label = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
    if (label) {
        button.id = `${select.id}-ss`;
        label.htmlFor = button.id;
    } else if (select.getAttribute('aria-label')) {
        button.setAttribute('aria-label', select.getAttribute('aria-label'));
    }

    let items = [];
    let active = -1;

    const refresh = () => {
        const option = select.options[nativeIndex.get.call(select)];
        const text = option ? option.textContent.trim() : '';
        button.textContent = text || ' ';
        button.title = text;
        button.disabled = select.disabled;
        button.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
    };

    const render = () => {
        const terms = normalize(input.value).split(/\s+/).filter(Boolean);
        const current = nativeValue.get.call(select);
        list.innerHTML = '';
        items = [];
        let group = null;
        [...select.options].forEach((option) => {
            if (option.hidden) return;
            const text = option.textContent.trim();
            const parent = option.parentElement.tagName === 'OPTGROUP' ? option.parentElement.label : null;
            const haystack = normalize(`${text} ${parent ?? ''}`);
            if (terms.length && !terms.every((t) => haystack.includes(t))) return;
            if (parent && parent !== group) {
                const header = document.createElement('li');
                header.className = 'ss-group';
                header.textContent = parent;
                list.append(header);
            }
            group = parent;
            const li = document.createElement('li');
            li.className = 'ss-option';
            li.setAttribute('role', 'option');
            li.dataset.value = option.value;
            li.innerHTML = highlight(text, terms) || ' ';
            if (option.value === '' && !text) li.classList.add('text-muted');
            if (option.disabled) li.setAttribute('aria-disabled', 'true');
            if (option.value === current && option.selected) li.setAttribute('aria-selected', 'true');
            list.append(li);
            if (!option.disabled) items.push(li);
        });
        empty.hidden = items.length > 0;
        const selected = items.findIndex((li) => li.getAttribute('aria-selected') === 'true');
        setActive(terms.length ? 0 : Math.max(0, selected));
    };

    const setActive = (index) => {
        items[active]?.classList.remove('active');
        active = items.length ? Math.max(0, Math.min(index, items.length - 1)) : -1;
        const li = items[active];
        if (li) {
            li.classList.add('active');
            li.scrollIntoView({ block: 'nearest' });
        }
    };

    const open = (initial = '') => {
        if (select.disabled || !panel.hidden) return;
        document.querySelectorAll('.ss-panel:not([hidden])').forEach((p) => p.dispatchEvent(new Event('ss:close')));
        panel.hidden = false;
        wrapper.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
        // Panneau ouvert vers le haut s'il manque de place en bas de l'écran.
        const rect = button.getBoundingClientRect();
        wrapper.classList.toggle('drop-up', window.innerHeight - rect.bottom < 300 && rect.top > window.innerHeight - rect.bottom);
        input.value = initial;
        render();
        // Panneau qui dépasserait le bord droit de la page (sous la barre de défilement, ex. liste des règles aux
        // libellés longs) : aligné sur le bord droit du champ, et pas plus large que la place disponible à gauche.
        wrapper.classList.remove('align-end');
        panel.style.maxWidth = '';
        if (panel.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
            wrapper.classList.add('align-end');
            panel.style.maxWidth = `${Math.max(rect.width, Math.min(512, rect.right - 8))}px`;
        }
        input.focus();
    };

    const close = (focus = true) => {
        if (panel.hidden) return;
        panel.hidden = true;
        wrapper.classList.remove('open', 'drop-up', 'align-end');
        button.setAttribute('aria-expanded', 'false');
        if (focus) button.focus();
    };

    const choose = (li) => {
        if (!li || li.getAttribute('aria-disabled') === 'true') return;
        const changed = nativeValue.get.call(select) !== li.dataset.value;
        nativeValue.set.call(select, li.dataset.value);
        refresh();
        close();
        if (changed) {
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    button.addEventListener('click', () => (panel.hidden ? open() : close()));
    button.addEventListener('keydown', (event) => {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' ', 'F4'].includes(event.key) || (event.altKey && event.key === 'ArrowDown')) {
            event.preventDefault();
            open();
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            // Taper directement sur la liste fermée lance la recherche.
            event.preventDefault();
            open(event.key);
        }
    });
    input.addEventListener('input', render);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') { event.preventDefault(); setActive(active + 1); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(active - 1); }
        else if (event.key === 'PageDown') { event.preventDefault(); setActive(active + 8); }
        else if (event.key === 'PageUp') { event.preventDefault(); setActive(active - 8); }
        else if (event.key === 'Enter') { event.preventDefault(); choose(items[active]); }
        else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
        else if (event.key === 'Tab') close(false);
    });
    list.addEventListener('mousedown', (event) => event.preventDefault()); // garde le focus dans la recherche
    list.addEventListener('click', (event) => choose(event.target.closest('.ss-option')));
    list.addEventListener('mousemove', (event) => {
        const index = items.indexOf(event.target.closest('.ss-option'));
        if (index >= 0 && index !== active) setActive(index);
    });
    panel.addEventListener('ss:close', () => close(false));
    document.addEventListener('mousedown', (event) => {
        if (!wrapper.contains(event.target)) close(false);
    });

    // Le bouton suit le select : choix au clavier sur le select, valeur posée par script, options remplacées,
    // liste désactivée, formulaire réinitialisé, champ signalé invalide.
    select.addEventListener('change', refresh);
    Object.defineProperty(select, 'value', {
        configurable: true,
        get: () => nativeValue.get.call(select),
        set: (v) => { nativeValue.set.call(select, v); refresh(); },
    });
    Object.defineProperty(select, 'selectedIndex', {
        configurable: true,
        get: () => nativeIndex.get.call(select),
        set: (v) => { nativeIndex.set.call(select, v); refresh(); },
    });
    new MutationObserver(() => {
        refresh();
        if (!panel.hidden) render();
    }).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'class', 'selected', 'label'] });
    select.form?.addEventListener('reset', () => setTimeout(refresh));
    // Champ obligatoire vide : le navigateur place le focus sur le select masqué, on le renvoie au bouton.
    select.addEventListener('invalid', () => button.classList.add('is-invalid'));
    select.addEventListener('focus', () => button.focus());

    enhanced.set(select, { refresh, open, close });
    refresh();
}

function consider(select) {
    if (enhanced.has(select) || select.dataset.ssWatched) {
        return;
    }
    if (wanted(select)) {
        enhance(select);

        return;
    }
    // Liste encore courte (ex. cascade en attente) : équipée dès qu'elle atteint la taille voulue.
    if (select.multiple || select.dataset.search === 'false') return;
    select.dataset.ssWatched = '1';
    const observer = new MutationObserver(() => {
        if (wanted(select)) {
            observer.disconnect();
            delete select.dataset.ssWatched;
            enhance(select);
        }
    });
    observer.observe(select, { childList: true, subtree: true });
}

/** Équipe les listes présentes et celles ajoutées plus tard (fenêtres, tableaux…). */
export function initSearchableSelects(root = document) {
    root.querySelectorAll('select').forEach(consider);
    new MutationObserver((mutations) => {
        mutations.forEach((m) => m.addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            if (node.tagName === 'SELECT') consider(node);
            else node.querySelectorAll?.('select').forEach(consider);
        }));
    }).observe(document.body, { childList: true, subtree: true });
}
