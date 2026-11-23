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
    document.getElementById('coverage-level')?.addEventListener('change', levelTable);
    filters.ready.then(() => {
        current = filters.values();
        load();
    });
}
