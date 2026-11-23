import { renderChart } from './charts';
import { bindExportLinks, initFilterBar } from './filters';
import { AIRID, escapeHtml, fmt, pct, rateColor } from './format';
import { getJson } from './http';

/** Outils communs aux pages de couverture (antigènes, CPS) : graphiques en %, tableaux, chargement filtré. */
export const LEVELS = {
    by_zone: 'Zone sanitaire',
    by_commune: 'Commune',
    by_village: 'Village',
    by_age: "Tranche d'âge",
    by_sex: 'Sexe',
};

const percentAxis = { beginAtZero: true, max: 100, ticks: { callback: (v) => `${v} %` } };
const tooltip = (axis) => ({ callbacks: { label: (ctx) => `${ctx.dataset.label} : ${pct(ctx.parsed[axis])}` } });

export function setKpis(values) {
    Object.entries(values).forEach(([key, value]) => {
        document.querySelectorAll(`[data-kpi="${key}"]`).forEach((el) => {
            el.textContent = value;
        });
    });
}

/** Barres empilées carte / rappel seul, en % ; horizontales si demandé (nombreuses doses). */
export function sourceChart(canvasId, labels, card, recall, horizontal = false) {
    const value = horizontal ? 'x' : 'y';
    const category = horizontal ? 'y' : 'x';
    renderChart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Carte', data: card, backgroundColor: AIRID.success },
                { label: 'Rappel seul', data: recall, backgroundColor: AIRID.warning },
            ],
        },
        options: {
            indexAxis: horizontal ? 'y' : 'x',
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: tooltip(value) },
            scales: { [category]: { stacked: true, ticks: { autoSkip: false } }, [value]: { ...percentAxis, stacked: true } },
        },
    });
}

/** Une barre par série (dose, cycle…) pour chaque groupe ; series : [{ label, color, value(row) }]. */
export function groupChart(canvasId, rows, series) {
    renderChart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.label),
            datasets: series.map((s) => ({ label: s.label, data: rows.map((r) => s.value(r)), backgroundColor: s.color })),
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: tooltip('y'), legend: { display: series.length > 1 } },
            scales: { y: percentAxis },
        },
    });
}

/** Cellule de tableau : couverture colorée, et « reçus / dénominateur » si fourni. */
export function rateCell(rate, received = null, denominator = null) {
    if (rate === null || rate === undefined) return '<td class="text-end text-muted">—</td>';
    const detail = denominator !== null ? `<div class="small text-muted">${fmt(received)} / ${fmt(denominator)}</div>` : '';

    return `<td class="text-end"><span class="badge text-bg-${rateColor(rate)}">${pct(rate)}</span>${detail}</td>`;
}

/** Tableau par niveau (sélecteur #coverage-level) ; columns : [{ label, cell(row) }]. */
export function levelTable(data, columns) {
    const level = document.getElementById('coverage-level')?.value || 'by_zone';
    document.querySelectorAll('[data-level-label]').forEach((el) => { el.textContent = LEVELS[level].toLowerCase(); });
    const table = document.getElementById('level-table');
    if (!table) return;
    table.querySelector('thead').innerHTML = `<tr><th>${LEVELS[level]}</th><th class="text-end">Enfants</th>${columns.map((c) => `<th class="text-end">${escapeHtml(c.label)}</th>`).join('')}</tr>`;
    const rows = data[level] ?? [];
    table.querySelector('tbody').innerHTML = rows.length
        ? rows.map((r) => `<tr><td>${escapeHtml(r.label)}</td><td class="text-end">${fmt(r.children)}</td>${columns.map((c) => c.cell(r)).join('')}</tr>`).join('')
        : `<tr><td colspan="${columns.length + 2}" class="text-center text-muted">Aucun enfant enrôlé pour ces filtres</td></tr>`;
}

/** Chargement des données selon les filtres du tableau de bord ; render(data) à chaque chargement. */
export function initCoveragePage(root, render) {
    let current = {};
    const load = async () => {
        try {
            const data = await getJson(root.dataset.url, current);
            render(data);
            document.querySelectorAll('[data-generated-at]').forEach((el) => { el.textContent = data.generated_at; });
        } catch (error) {
            console.error(error);
        }
    };
    const filters = initFilterBar(document.getElementById('filter-bar'), (values) => {
        current = values;
        load();
    });
    bindExportLinks(root, () => current);
    filters.ready.then(() => {
        current = filters.values();
        load();
    });
}
