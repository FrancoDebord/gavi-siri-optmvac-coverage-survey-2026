import { renderChart } from '../lib/charts';
import { bindExportLinks, initFilterBar } from '../lib/filters';
import { AIRID, escapeHtml, fmt, pct, rateColor } from '../lib/format';
import { getJson } from '../lib/http';

/** Couverture VAP 1 à 4 : indicateurs, graphiques et tableaux (filtres du tableau de bord). */
const DOSES = [1, 2, 3, 4];
// Une couleur par dose, du plus clair (1re dose) au rouge AIRID (4e dose).
const DOSE_COLORS = [AIRID.greyLight, AIRID.grey, AIRID.black, AIRID.red];
const LEVELS = {
    by_zone: 'Zone sanitaire',
    by_commune: 'Commune',
    by_village: 'Village',
    by_age: "Tranche d'âge",
    by_sex: 'Sexe',
};

let last = null;

const percentAxis = { beginAtZero: true, max: 100, ticks: { callback: (v) => `${v} %` } };
const percentTooltip = { callbacks: { label: (ctx) => `${ctx.dataset.label} : ${pct(ctx.parsed[ctx.chart.options.indexAxis === 'y' ? 'x' : 'y'])}` } };

function setKpis(kpis) {
    const values = {
        children: fmt(kpis.children),
        card_seen: pct(kpis.card_seen),
        zero_dose: pct(kpis.zero_dose),
        dropout_1_3: pct(kpis.dropout_1_3),
        dropout_1_4: pct(kpis.dropout_1_4),
        vap4_due: fmt(kpis.vap4_due),
    };
    DOSES.forEach((k) => {
        values[`vap${k}`] = pct(kpis.doses[k].pct);
        values[`vap${k}_card`] = pct(kpis.doses[k].pct_card);
    });
    Object.entries(values).forEach(([key, value]) => {
        document.querySelectorAll(`[data-kpi="${key}"]`).forEach((el) => {
            el.textContent = value;
        });
    });
}

/** Une barre par dose, empilée : carte puis rappel seul. */
function doseChart(rows) {
    renderChart(document.getElementById('chart-doses'), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.dose),
            datasets: [
                { label: 'Carte', data: rows.map((r) => r.pct_card), backgroundColor: AIRID.success },
                { label: 'Rappel seul', data: rows.map((r) => r.pct_recall), backgroundColor: AIRID.warning },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: percentTooltip },
            scales: { x: { stacked: true }, y: { ...percentAxis, stacked: true } },
        },
    });
}

/** Couverture des 4 doses par groupe : une barre par dose. */
function groupChart(canvasId, rows) {
    renderChart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.label),
            datasets: DOSES.map((k, i) => ({ label: `VAP ${k}`, data: rows.map((r) => r.doses[k].pct), backgroundColor: DOSE_COLORS[i] })),
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: percentTooltip },
            scales: { y: percentAxis },
        },
    });
}

function doseTable(rows) {
    const tbody = document.querySelector('#dose-table tbody');
    if (!tbody) return;
    tbody.innerHTML = rows.map((r) => `<tr>
        <td class="fw-semibold">${escapeHtml(r.dose)}</td>
        <td class="text-end">${fmt(r.denominator)}</td>
        <td class="text-end">${fmt(r.card)}</td>
        <td class="text-end">${fmt(r.recall)}</td>
        <td class="text-end">${fmt(r.received)}</td>
        <td class="text-end fw-semibold">${pct(r.pct)}</td>
        <td class="text-end">${pct(r.pct_card)}</td>
    </tr>`).join('');
}

function cell(dose) {
    if (!dose.denominator) return '<td class="text-end text-muted">—</td>';
    return `<td class="text-end"><span class="badge text-bg-${rateColor(dose.pct)}">${pct(dose.pct)}</span>
        <div class="small text-muted">${fmt(dose.received)} / ${fmt(dose.denominator)}</div></td>`;
}

function levelTable() {
    if (!last) return;
    const level = document.getElementById('coverage-level')?.value || 'by_zone';
    document.querySelectorAll('[data-level-label]').forEach((el) => { el.textContent = LEVELS[level].toLowerCase(); });
    document.querySelectorAll('[data-level-head]').forEach((el) => { el.textContent = LEVELS[level]; });
    const rows = last[level] ?? [];
    const tbody = document.querySelector('#level-table tbody');
    if (!tbody) return;
    tbody.innerHTML = rows.length
        ? rows.map((r) => `<tr><td>${escapeHtml(r.label)}</td><td class="text-end">${fmt(r.children)}</td>${DOSES.map((k) => cell(r.doses[k])).join('')}</tr>`).join('')
        : '<tr><td colspan="6" class="text-center text-muted">Aucun enfant enrôlé pour ces filtres</td></tr>';
}

/*
 * Tableaux simples « Niveau zone » et « Niveau commune », mis en forme par styles en ligne : copiés en HTML, ils se
 * collent comme tableaux natifs (bordures, en-tête) dans PowerPoint, Word ou Excel. Dernière ligne : ensemble.
 */
const PPT = {
    table: 'border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;width:100%',
    th: `border:1px solid ${AIRID.grey};background:${AIRID.black};color:#fff;padding:4px 8px;font-weight:bold`,
    td: `border:1px solid ${AIRID.greyLight};padding:4px 8px;color:${AIRID.black}`,
    total: `border:1px solid ${AIRID.grey};background:#EDEDED;padding:4px 8px;font-weight:bold;color:${AIRID.black}`,
};

function pptDose(dose) {
    return dose.denominator ? `${pct(dose.pct)} (${fmt(dose.received)}/${fmt(dose.denominator)})` : '—';
}

function pptTable(target, rows, withZone) {
    const el = document.getElementById(target);
    if (!el || !last) return;
    const head = [...(withZone ? ['Zone sanitaire', 'Commune'] : ['Zone sanitaire']), 'Enfants', ...DOSES.map((k) => `VAP ${k}`)];
    const left = (i) => i < (withZone ? 2 : 1);
    const line = (cells, style) => `<tr>${cells.map((c, i) => `<td style="${style};text-align:${left(i) ? 'left' : 'right'}">${escapeHtml(String(c))}</td>`).join('')}</tr>`;
    const body = rows.map((r) => line([...(withZone ? [r.zone ?? '', r.label] : [r.label]), fmt(r.children), ...DOSES.map((k) => pptDose(r.doses[k]))], PPT.td));
    const doses = Object.fromEntries(last.by_dose.map((d, i) => [DOSES[i], d]));
    const total = line([...(withZone ? ['Ensemble', ''] : ['Ensemble']), fmt(last.kpis.children), ...DOSES.map((k) => pptDose(doses[k]))], PPT.total);
    el.innerHTML = rows.length
        ? `<table style="${PPT.table}"><thead><tr>${head.map((h, i) => `<th style="${PPT.th};text-align:${left(i) ? 'left' : 'right'}">${escapeHtml(h)}</th>`).join('')}</tr></thead>
            <tbody>${body.join('')}${total}</tbody></table>`
        : '<p class="text-muted text-center mb-0">Aucun enfant enrôlé pour ces filtres</p>';
}

/** Bouton « Copier » : tableau en HTML (mise en forme) et en texte tabulé (repli : sélection du tableau). */
function bindTableCopy(root) {
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-table]');
        if (!button) return;
        const table = document.getElementById(button.dataset.copyTable)?.querySelector('table');
        if (!table) return;
        const html = table.outerHTML;
        const text = [...table.rows].map((row) => [...row.cells].map((c) => c.textContent.trim()).join('\t')).join('\n');
        const icon = button.querySelector('i');
        const done = (ok) => {
            icon.className = `bi bi-${ok ? 'clipboard-check' : 'x-lg'} me-1`;
            setTimeout(() => { icon.className = 'bi bi-clipboard me-1'; }, 1500);
        };
        try {
            await navigator.clipboard.write([new window.ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([text], { type: 'text/plain' }),
            })]);
            done(true);
        } catch {
            // Navigateur sans ClipboardItem (ou copie refusée) : copie de la sélection.
            const range = document.createRange();
            range.selectNode(table);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            const ok = document.execCommand('copy');
            selection.removeAllRanges();
            done(ok);
        }
    });
}

export default function vapCoveragePage() {
    const root = document.getElementById('vap-coverage');
    let current = {};

    async function load() {
        try {
            last = await getJson(root.dataset.url, current);
            setKpis(last.kpis);
            doseChart(last.by_dose);
            groupChart('chart-zones', last.by_zone);
            groupChart('chart-communes', last.by_commune);
            groupChart('chart-age', last.by_age);
            groupChart('chart-sex', last.by_sex);
            doseTable(last.by_dose);
            levelTable();
            pptTable('ppt-zone', last.by_zone, false);
            pptTable('ppt-commune', [...last.by_commune].sort((a, b) => (a.zone ?? '').localeCompare(b.zone ?? '', 'fr') || a.label.localeCompare(b.label, 'fr')), true);
            document.querySelectorAll('[data-generated-at]').forEach((el) => { el.textContent = last.generated_at; });
        } catch (error) {
            console.error(error);
        }
    }

    const filters = initFilterBar(document.getElementById('filter-bar'), (values) => {
        current = values;
        load();
    });
    bindExportLinks(root, () => current);
    bindTableCopy(root);
    document.getElementById('coverage-level')?.addEventListener('change', levelTable);
    filters.ready.then(() => {
        current = filters.values();
        load();
    });
}
