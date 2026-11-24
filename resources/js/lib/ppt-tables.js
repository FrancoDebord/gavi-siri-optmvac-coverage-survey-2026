import { AIRID, escapeHtml, fmt, pct } from './format';

/*
 * Tableaux simples « Niveau zone / commune / village » des pages de couverture, mis en forme par styles en ligne :
 * copiés en HTML, ils se collent comme tableaux natifs (bordures, en-tête) dans PowerPoint, Word ou Excel.
 * Données : all().tables (lignes { labels: [zone, commune?, village?], children, … }) ; dernière ligne : ensemble.
 */
const STYLE = {
    table: 'border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;width:100%',
    th: `border:1px solid ${AIRID.grey};background:${AIRID.black};color:#fff;padding:4px 8px;font-weight:bold`,
    td: `border:1px solid ${AIRID.greyLight};padding:4px 8px;color:${AIRID.black}`,
    total: `border:1px solid ${AIRID.grey};background:#EDEDED;padding:4px 8px;font-weight:bold;color:${AIRID.black}`,
};
const LABEL_HEADS = ['Zone sanitaire', 'Commune', 'Village'];

/** Couverture et « reçues/dénominateur » (ou « — » sans dénominateur). */
export function rateWithCount(item) {
    return item?.denominator ? `${pct(item.pct)} (${fmt(item.received)}/${fmt(item.denominator)})` : '—';
}

/** Couverture seule (« — » si non calculable). */
export function rateOnly(value) {
    return value === null || value === undefined ? '—' : pct(value);
}

function table(rows, labelCount, columns, total) {
    const align = (i) => `text-align:${i < labelCount ? 'left' : 'right'}`;
    const line = (cells, style) => `<tr>${cells.map((c, i) => `<td style="${style};${align(i)}">${escapeHtml(String(c))}</td>`).join('')}</tr>`;
    const head = [...LABEL_HEADS.slice(0, labelCount), 'Enfants', ...columns.map((c) => c.label)];
    const body = rows.map((r) => line([...r.labels, fmt(r.children), ...columns.map((c) => c.value(r))], STYLE.td));
    const totalLabels = ['Ensemble', ...Array(labelCount - 1).fill('')];

    return `<table style="${STYLE.table}"><thead><tr>${head.map((h, i) => `<th style="${STYLE.th};${align(i)}">${escapeHtml(h)}</th>`).join('')}</tr></thead>
        <tbody>${body.join('')}${line([...totalLabels, fmt(total.children), ...columns.map((c) => c.value(total))], STYLE.total)}</tbody></table>`;
}

/**
 * Remplit les conteneurs #ppt-zone, #ppt-commune et #ppt-village.
 * columns : [{ label, value(row) → texte }] ; total : ligne « Ensemble » (mêmes champs qu'une ligne).
 */
export function renderPptTables(tables, columns, total) {
    Object.entries({ zone: 1, commune: 2, village: 3 }).forEach(([level, labelCount]) => {
        const el = document.getElementById(`ppt-${level}`);
        if (!el) return;
        const rows = tables?.[level] ?? [];
        el.innerHTML = rows.length
            ? table(rows, labelCount, columns, total)
            : '<p class="text-muted text-center mb-0">Aucun enfant enrôlé pour ces filtres</p>';
    });
}

/** Boutons « Copier » (data-copy-table) : tableau en HTML (mise en forme) et en texte tabulé ; repli : sélection. */
export function initTableCopy(root) {
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-table]');
        if (!button) return;
        const tableEl = document.getElementById(button.dataset.copyTable)?.querySelector('table');
        if (!tableEl) return;
        const text = [...tableEl.rows].map((row) => [...row.cells].map((c) => c.textContent.trim()).join('\t')).join('\n');
        const icon = button.querySelector('i');
        const done = (ok) => {
            icon.className = `bi bi-${ok ? 'clipboard-check' : 'x-lg'} me-1`;
            setTimeout(() => { icon.className = 'bi bi-clipboard me-1'; }, 1500);
        };
        try {
            await navigator.clipboard.write([new window.ClipboardItem({
                'text/html': new Blob([tableEl.outerHTML], { type: 'text/html' }),
                'text/plain': new Blob([text], { type: 'text/plain' }),
            })]);
            done(true);
        } catch {
            // Navigateur sans ClipboardItem (ou copie refusée) : copie de la sélection.
            const range = document.createRange();
            range.selectNode(tableEl);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            const ok = document.execCommand('copy');
            selection.removeAllRanges();
            done(ok);
        }
    });
}

/*
 * Tableau récapitulatif « à la présentation » (indicateurs en lignes, groupes en colonnes) : filets horizontaux
 * seulement, séparateurs entre sections, valeurs mises en avant en rouge. sections : [[{ label, value(col), strong? }]] ; header(col) : en-tête de colonne.
 */
export function recapTable(columns, sections, header = (c) => c.label) {
    const font = `font-family:Calibri,Arial,sans-serif;font-size:12pt;color:${AIRID.black}`;
    const cell = (style, text, align) => `<td style="${font};padding:3px 10px;text-align:${align};${style}">${text}</td>`;
    const rule = (color, width = 1) => `border-top:${width}px solid ${color}`;
    const head = `<tr>${cell(`${rule(AIRID.black, 2)};border-bottom:1px solid ${AIRID.black}`, '', 'left')}${columns.map((c) => cell(`${rule(AIRID.black, 2)};border-bottom:1px solid ${AIRID.black};font-weight:bold;text-align:center`, escapeHtml(header(c)), 'center')).join('')}</tr>`;
    const body = sections.map((rows, s) => rows.map((row, i) => {
        const sep = i === 0 && s > 0 ? rule(AIRID.black) : '';
        const last = s === sections.length - 1 && i === rows.length - 1 ? `;border-bottom:2px solid ${AIRID.black}` : '';
        const value = row.strong ? `;color:${AIRID.red};font-weight:bold` : '';

        return `<tr>${cell(sep + last, escapeHtml(row.label), 'left')}${columns.map((c) => cell(sep + last + value, escapeHtml(String(row.value(c))), 'center')).join('')}</tr>`;
    }).join('')).join('');

    return `<table style="border-collapse:collapse;background:#fff"><thead>${head}</thead><tbody>${body}</tbody></table>`;
}
