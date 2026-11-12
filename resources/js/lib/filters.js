import { getJson } from './http';

const STORAGE_PREFIX = 'gavi.filters.';
const CASCADE = ['health_zone_id', 'commune_id', 'village_id', 'cluster_id'];

function read(key) {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_PREFIX + key) || '{}');
    } catch {
        return {};
    }
}

function write(key, values) {
    try {
        localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(values));
    } catch {
        /* stockage indisponible : filtres non persistés */
    }
}

/**
 * Barre de filtres en cascade (zone > commune > village > grappe, binôme > agent, période, source…).
 * Les valeurs sont persistées dans le navigateur (par page) et restaurées au chargement.
 * onChange(params) est appelé à chaque modification.
 */
export function initFilterBar(form, onChange) {
    if (!form) return { values: () => ({}) };

    const storageKey = form.dataset.storageKey || 'global';
    const optionsUrl = form.dataset.optionsUrl;
    const fields = () => [...form.querySelectorAll('[data-filter]')];
    const field = (name) => form.querySelector(`[data-filter="${name}"]`);

    const values = () => Object.fromEntries(fields().map((el) => [el.dataset.filter, el.value]).filter(([, v]) => v !== ''));

    // Restauration : l'URL prime sur le stockage local.
    const url = new URLSearchParams(window.location.search);
    const saved = read(storageKey);
    fields().forEach((el) => {
        const value = url.get(el.dataset.filter) ?? saved[el.dataset.filter];
        if (value !== undefined && value !== null) {
            el.dataset.initial = value;
            if (el.tagName !== 'SELECT' || el.querySelector(`option[value="${CSS.escape(value)}"]`)) {
                el.value = value;
            }
        }
    });

    async function loadOptions() {
        if (!optionsUrl) return;
        const current = values();
        fields().forEach((el) => {
            if (el.dataset.initial && !current[el.dataset.filter]) current[el.dataset.filter] = el.dataset.initial;
        });
        const data = await getJson(optionsUrl, current);
        Object.entries(data.options || {}).forEach(([name, options]) => {
            const select = field(name);
            if (!select || select.tagName !== 'SELECT') return;
            const selected = current[name] ?? '';
            const placeholder = select.dataset.placeholder || 'Tous';
            select.innerHTML = `<option value="">${placeholder}</option>`;
            options.forEach((option) => {
                const opt = document.createElement('option');
                opt.value = option.id;
                opt.textContent = option.name;
                if (String(option.id) === String(selected)) opt.selected = true;
                select.appendChild(opt);
            });
            if (data.locked?.includes(name)) {
                select.disabled = true;
            }
            delete select.dataset.initial;
        });
    }

    let timer = null;
    const emit = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            write(storageKey, values());
            onChange(values());
        }, 150);
    };

    form.addEventListener('change', async (event) => {
        const name = event.target.dataset.filter;
        if (!name) return;
        // Cascade : un niveau modifié vide les niveaux inférieurs.
        const index = CASCADE.indexOf(name);
        if (index >= 0) {
            CASCADE.slice(index + 1).forEach((child) => {
                const el = field(child);
                if (el) el.value = '';
            });
            if (name === 'health_zone_id') {
                ['team_id', 'collector_id'].forEach((key) => {
                    if (field(key)) field(key).value = '';
                });
            }
            await loadOptions();
        }
        // Un binôme choisi limite la liste des agents à ses deux membres.
        if (name === 'team_id') {
            if (field('collector_id')) field('collector_id').value = '';
            await loadOptions();
        }
        emit();
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        emit();
    });

    form.querySelector('[data-filter-reset]')?.addEventListener('click', async () => {
        fields().forEach((el) => {
            if (!el.disabled) el.value = '';
        });
        await loadOptions();
        emit();
    });

    const ready = loadOptions().catch(() => null);

    return { values, ready };
}

/** Ajoute les filtres courants à un lien d'export. */
export function bindExportLinks(container, valuesFn) {
    container?.querySelectorAll('[data-export]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const params = new URLSearchParams(valuesFn());
            const base = link.getAttribute('href');
            window.location.href = params.toString() ? `${base}${base.includes('?') ? '&' : '?'}${params}` : base;
        });
    });
}
