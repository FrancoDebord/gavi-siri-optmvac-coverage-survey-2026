// Photos : rotation par quart de tour (photos de carnet prises de travers) et message si indisponible.
function initPhotos() {
    document.querySelectorAll('.query-photo').forEach((figure) => {
        const img = figure.querySelector('img');
        figure.querySelector('[data-rotate]')?.addEventListener('click', () => {
            const rotation = (Number(img.dataset.rotation) + 90) % 360;
            img.dataset.rotation = rotation;
            img.style.transform = `rotate(${rotation}deg)`;
            img.classList.toggle('rotated', rotation % 180 !== 0);
        });
        img.addEventListener('error', () => {
            img.closest('a').classList.add('d-none');
            figure.querySelector('.query-photo-error')?.classList.remove('d-none');
        });
    });
}

// Fiche d'une query : photos et aperçu des transitions autorisées.
// Lien court de la query copié dans le presse-papiers (repli : sélection manuelle via une invite).
function initShareLink() {
    document.querySelectorAll('[data-copy-link]').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = button.dataset.copyLink;
            const label = button.querySelector('span');
            try {
                await navigator.clipboard.writeText(url);
                label.textContent = 'Lien copié';
                setTimeout(() => { label.textContent = 'Copier le lien'; }, 2000);
            } catch {
                window.prompt('Copiez le lien de la query :', url);
            }
        });
    });
}

export default function queryShowPage() {
    initPhotos();
    initShareLink();
    const select = document.querySelector('#query-status-form [name="status"]');
    const help = document.getElementById('status-help');
    if (!select || !help) return;
    const signature = document.getElementById('signature-field');
    const update = () => {
        const option = select.selectedOptions[0];
        help.textContent = option?.dataset.help ?? '';
        // Signature électronique (mot de passe) pour valider ou déclarer irrésoluble une query en double validation.
        if (signature) {
            const required = option?.dataset.signature === '1';
            signature.classList.toggle('d-none', !required);
            signature.querySelector('input').required = required;
        }
        // Correction proposée : saisie des nouvelles valeurs (désactivées sinon, pour ne pas être envoyées).
        if (corrections) {
            const active = select.value === 'corrigee';
            corrections.classList.toggle('d-none', !active);
            corrections.querySelectorAll('[data-correction-rows] input, [data-correction-rows] select').forEach((el) => {
                el.disabled = !active;
            });
            syncExclusion();
            syncPair();
        }
    };
    const corrections = document.getElementById('corrections-block');
    // Exclusion d'un doublon : champs envoyés seulement si la case est cochée.
    const excludeToggle = corrections?.querySelector('[data-exclude-toggle]');
    function syncExclusion() {
        if (!excludeToggle) return;
        const fields = corrections.querySelector('[data-exclude-fields]');
        const on = excludeToggle.checked && select.value === 'corrigee';
        fields.classList.toggle('d-none', !excludeToggle.checked);
        fields.querySelectorAll('input, select').forEach((el) => {
            el.disabled = !on;
        });
    }
    excludeToggle?.addEventListener('change', syncExclusion);
    // Deux valeurs comparées (ex. VPO 3 avant VPO 2) : seule(s) la ou les valeurs déclarées fausses sont saisies et envoyées.
    const pairChoice = corrections?.querySelector('[data-pair-choice]');
    function syncPair() {
        if (!pairChoice) return;
        const checked = pairChoice.querySelector('input[name="pair_choice"]:checked');
        const choice = checked?.value ?? null;
        const active = select.value === 'corrigee';
        // « Âge confirmé » fermé tout de suite par le data manager (irrésoluble) : signature si la query l'exige.
        if (signature && active) {
            const required = checked?.dataset.signature === '1';
            signature.classList.toggle('d-none', !required);
            signature.querySelector('input').required = required;
        }
        corrections.querySelectorAll('[data-pair-row]').forEach((row) => {
            const shown = choice === 'all' || choice === row.dataset.pairRow;
            row.classList.toggle('d-none', !shown);
            row.querySelectorAll('input, select').forEach((el) => {
                el.disabled = !(active && shown);
            });
        });
    }
    pairChoice?.addEventListener('change', syncPair);
    // Questions du formulaire : libellé (nom de variable), filtrées selon le membre choisi ; saisie adaptée au type.
    const variableOptions = JSON.parse(document.getElementById('variable-options')?.textContent || '[]');
    const fillVariables = (row) => {
        const repeat = row.querySelector('[data-member]').selectedOptions[0]?.dataset.repeat ?? '';
        const select = row.querySelector('[data-variable]');
        const keep = select.value;
        select.innerHTML = '<option value="">— Choisir la question —</option>';
        variableOptions.filter((v) => v.repeat === repeat).forEach((v) => {
            const option = new Option(`${v.label} (${v.name})`, v.name);
            select.add(option);
        });
        select.value = keep;
        fillValue(row);
    };
    // Valeurs de la soumission (actuelle et saisie dans ODK) : affichées et reprises dans la saisie.
    const memberValues = JSON.parse(document.getElementById('member-values')?.textContent || '{}');
    const fillValue = (row) => {
        const index = row.dataset.index;
        const variable = variableOptions.find((v) => v.name === row.querySelector('[data-variable]').value);
        const path = row.querySelector('[data-member]').value;
        const [current, raw] = (variable && memberValues[path]?.[variable.name]) || [null, null];
        const label = (value) => {
            if (value === null || value === undefined || value === '') return '(vide)';
            const choice = variable?.choices?.find((c) => c.code === value);
            return choice ? `${value} – ${choice.label}` : value;
        };
        const info = row.querySelector('[data-current-value]');
        if (info) {
            info.classList.toggle('d-none', !variable);
            info.replaceChildren();
            if (variable) {
                info.append('Valeur saisie : ');
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-danger-subtle text-danger-emphasis border';
                badge.textContent = label(current);
                info.append(badge);
                if (raw !== current) info.append(` (dans ODK : ${label(raw)})`);
            }
        }
        const slot = row.querySelector('[data-value-slot]');
        const name = `corrections[${index}][new_value]`;
        const compact = (code) => String(code).trim().replace(/\s+-\s+/g, '-');
        if (variable?.choices?.length && variable.type.startsWith('select_multiple')) {
            // Choix multiple : une ou plusieurs cases (rien de coché = vide).
            const checked = compact(current ?? '').split(/\s+/).filter(Boolean);
            const box = document.createElement('div');
            box.className = 'border rounded bg-body px-2 py-1 mb-2';
            const empty = document.createElement('input');
            Object.assign(empty, { type: 'hidden', name: `${name}[]`, value: '' });
            box.append(empty);
            variable.choices.forEach((c, k) => {
                const wrap = document.createElement('div');
                wrap.className = 'form-check';
                const input = document.createElement('input');
                Object.assign(input, { type: 'checkbox', name: `${name}[]`, value: c.code, id: `c${index}-${k}`, className: 'form-check-input', checked: checked.includes(compact(c.code)) });
                const lbl = document.createElement('label');
                lbl.className = 'form-check-label small';
                lbl.htmlFor = input.id;
                lbl.textContent = `${c.label} (${c.code})`;
                wrap.append(input, lbl);
                box.append(wrap);
            });
            slot.replaceChildren(box);
        } else if (variable?.choices?.length) {
            const select = document.createElement('select');
            select.name = name;
            select.className = 'form-select form-select-sm mb-2';
            select.add(new Option('(vide)', ''));
            variable.choices.forEach((c) => select.add(new Option(`${c.code} – ${c.label}`, c.code)));
            select.value = current ?? '';
            slot.replaceChildren(select);
        } else {
            const input = document.createElement('input');
            input.name = name;
            input.className = 'form-control form-control-sm mb-2';
            if (variable?.type === 'date') input.type = 'date';
            if (variable?.type === 'integer' || variable?.type === 'decimal') input.inputMode = 'decimal';
            // Valeur actuelle pré-remplie, à modifier (pour une date : AAAA-MM-JJ).
            input.value = variable?.type === 'date' ? (current ?? '').slice(0, 10) : (current ?? '');
            slot.replaceChildren(input);
        }
    };
    corrections?.querySelector('[data-add-correction]')?.addEventListener('click', () => {
        const index = Number(corrections.dataset.nextIndex);
        corrections.dataset.nextIndex = index + 1;
        const html = document.getElementById('correction-template').innerHTML.replaceAll('__i__', index);
        corrections.querySelector('[data-correction-rows]').insertAdjacentHTML('beforeend', html);
        const row = corrections.querySelector('[data-correction-rows]').lastElementChild;
        row.dataset.index = index;
        fillVariables(row);
        row.querySelector('[data-member]').addEventListener('change', () => fillVariables(row));
        row.querySelector('[data-variable]').addEventListener('change', () => fillValue(row));
    });

    // Arrondissement / village à cocher : villages de l'arrondissement coché, recherche par nom.
    const geo = corrections?.querySelector('[data-geo]');
    if (geo) {
        const filterVillages = () => {
            const arr = geo.querySelector('[data-geo-arr]:checked')?.value ?? '';
            const term = (geo.querySelector('[data-geo-search]').value || '').trim().toLowerCase();
            geo.querySelectorAll('[data-parent]').forEach((item) => {
                item.classList.toggle('d-none', item.dataset.parent !== arr || (term !== '' && !item.dataset.name.includes(term)));
            });
        };
        geo.querySelectorAll('[data-geo-arr]').forEach((radio) => radio.addEventListener('change', () => {
            geo.querySelectorAll('[data-parent] input:checked').forEach((r) => {
                if (r.closest('[data-parent]').dataset.parent !== radio.value) r.checked = false;
            });
            filterVillages();
        }));
        geo.querySelector('[data-geo-search]').addEventListener('input', filterVillages);
        filterVillages();
    }
    corrections?.addEventListener('click', (event) => {
        event.target.closest('[data-remove-correction]')?.closest('[data-correction-row]')?.remove();
    });
    select.addEventListener('change', update);
    update();
}
