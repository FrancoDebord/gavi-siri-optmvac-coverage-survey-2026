import L from 'leaflet';
import { renderChart } from '../lib/charts';
import { bindExportLinks, initFilterBar } from '../lib/filters';
import { AIRID, color, escapeHtml, fmt, pct, rateColor } from '../lib/format';
import { getJson } from '../lib/http';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker, STATUS_COLORS } from '../lib/map';

let map = null;
let markersLayer = null;
let boundariesLayer = null;
let legend = null;
let lastPoints = [];
let lastBoundaries = [];
let lastProductivity = { teams: [], agents: [] };

/** Productivité par binôme (défaut) ou par agent, selon le sélecteur. */
function renderProductivity() {
    const by = document.getElementById('productivity-by')?.value || 'teams';
    document.querySelectorAll('[data-productivity-label]').forEach((el) => { el.textContent = by === 'teams' ? 'par binôme' : 'par agent'; });
    document.querySelectorAll('[data-productivity-label-head]').forEach((el) => { el.textContent = by === 'teams' ? 'Binôme' : 'Agent'; });
    productivity(lastProductivity[by] ?? []);
}
const collapsed = new Set();

function setKpis(kpis) {
    const values = {
        visited: fmt(kpis.visited),
        surveyed: fmt(kpis.surveyed),
        refused: fmt(kpis.refused),
        absent: fmt(kpis.absent),
        no_children: fmt(kpis.no_children),
        caregivers: fmt(kpis.caregivers),
        children: fmt(kpis.children),
        rate_households: pct(kpis.rate_households),
        rate_children: pct(kpis.rate_children),
        target_households: fmt(kpis.target_households),
        target_children: fmt(kpis.target_children),
        clusters_started: fmt(kpis.clusters.started),
        clusters_completed: fmt(kpis.clusters.completed),
        clusters_not_started: fmt(kpis.clusters.not_started),
        clusters_total: fmt(kpis.clusters.total),
        last_sync: kpis.last_sync ?? 'Jamais',
        last_submission: kpis.last_submission ?? '—',
    };
    Object.entries(values).forEach(([key, value]) => {
        document.querySelectorAll(`[data-kpi="${key}"]`).forEach((el) => {
            el.textContent = value;
        });
    });
}

function dailyChart(rows) {
    renderChart(document.getElementById('chart-daily'), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.date),
            datasets: [
                { type: 'bar', label: 'Ménages enquêtés / jour', data: rows.map((r) => r.households), backgroundColor: 'rgba(112,109,107,.35)', yAxisID: 'y' },
                { type: 'bar', label: 'Enfants enrôlés / jour', data: rows.map((r) => r.children), backgroundColor: 'rgba(193,2,2,.22)', yAxisID: 'y' },
                { type: 'line', label: 'Ménages (cumul)', data: rows.map((r) => r.cum_households), borderColor: AIRID.black, backgroundColor: AIRID.black, tension: 0.25, yAxisID: 'y1' },
                { type: 'line', label: 'Caregivers (cumul)', data: rows.map((r) => r.cum_caregivers), borderColor: AIRID.grey, backgroundColor: AIRID.grey, borderDash: [6, 4], tension: 0.25, yAxisID: 'y1' },
                { type: 'line', label: 'Enfants (cumul)', data: rows.map((r) => r.cum_children), borderColor: AIRID.red, backgroundColor: AIRID.red, borderWidth: 3, tension: 0.25, yAxisID: 'y1' },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Par jour' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Cumul' } },
            },
        },
    });
}

/** Statuts de visite par niveau : une barre par statut, côte à côte. */
function statusChart(canvasId, rows) {
    const series = [
        ['surveyed', 'Enquêtés', AIRID.success],
        ['refused', 'Refus', AIRID.red],
        ['absent', 'Absents / pas d\'accès', AIRID.warning],
        ['no_children', 'Sans enfant éligible', AIRID.grey],
        ['other', 'Autres', AIRID.greyLight],
    ];
    renderChart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.label),
            datasets: series.map(([key, label, c]) => ({ label, data: rows.map((r) => r[key]), backgroundColor: c })),
        },
        options: {
            indexAxis: rows.length > 6 ? 'y' : 'x',
            interaction: { mode: 'index', intersect: false },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

function productivity(rows) {
    const top = rows.slice(0, 25);
    renderChart(document.getElementById('chart-productivity'), {
        type: 'bar',
        data: {
            labels: top.map((r) => r.name),
            datasets: [
                { type: 'bar', label: 'Interviews / jour', data: top.map((r) => r.per_day), backgroundColor: AIRID.black, yAxisID: 'y' },
                { type: 'line', label: 'Durée moyenne (min)', data: top.map((r) => r.avg_duration), borderColor: AIRID.red, backgroundColor: AIRID.red, yAxisID: 'y1', showLine: false, pointRadius: 5 },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { ticks: { autoSkip: false, maxRotation: 60, minRotation: 45 } },
                y: { beginAtZero: true, title: { display: true, text: 'Interviews / jour' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Minutes' } },
            },
        },
    });

    const tbody = document.querySelector('#productivity-table tbody');
    if (tbody) {
        tbody.innerHTML = rows.length
            ? rows.map((r) => `<tr><td>${escapeHtml(r.name)}</td><td class="text-end">${fmt(r.interviews)}</td><td class="text-end">${fmt(r.surveyed)}</td><td class="text-end">${fmt(r.days)}</td><td class="text-end">${fmt(r.per_day)}</td><td class="text-end">${r.avg_duration === null ? '—' : `${fmt(r.avg_duration)} min`}</td></tr>`).join('')
            : '<tr><td colspan="6" class="text-center text-muted">Aucune donnée</td></tr>';
    }
}

function hierarchy(rows) {
    const tbody = document.querySelector('#hierarchy-table tbody');
    if (!tbody) return;
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Aucune grappe pour ces filtres</td></tr>';
        return;
    }
    // Par défaut on n'affiche que zones et communes.
    if (!tbody.dataset.initialised) {
        rows.filter((r) => r.level >= 1 && r.has_children).forEach((r) => collapsed.add(r.key));
        tbody.dataset.initialised = '1';
    }
    const hiddenBy = (row) => {
        let parent = row.parent;
        const byKey = Object.fromEntries(rows.map((r) => [r.key, r]));
        while (parent) {
            if (collapsed.has(parent)) return true;
            parent = byKey[parent]?.parent;
        }
        return false;
    };
    tbody.innerHTML = rows.map((r) => {
        const rate = r.rate === null ? '<span class="text-muted">—</span>' : `<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" role="progressbar" aria-valuenow="${r.rate}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-${rateColor(r.rate)}" style="width:${Math.min(100, r.rate)}%"></div></div><small class="text-nowrap">${pct(r.rate)}</small></div>`;
        const toggle = r.has_children ? `<button class="tree-toggle" data-key="${r.key}" aria-label="Déplier"><i class="bi ${collapsed.has(r.key) ? 'bi-caret-right-fill' : 'bi-caret-down-fill'}"></i></button>` : '<span class="d-inline-block" style="width:1.1rem"></span>';
        const queries = r.queries ? `<a href="${document.getElementById('hierarchy-table').dataset.queriesUrl}?${new URLSearchParams({ ...r.filter, status: 'non_resolues' })}" class="badge text-bg-danger text-decoration-none">${fmt(r.queries)}</a>` : '<span class="text-muted">0</span>';
        return `<tr data-level="${r.level}" class="${hiddenBy(r) ? 'd-none' : ''}">
            <td class="tree-label">${toggle}${r.url ? `<a href="${r.url}" class="text-decoration-none">${escapeHtml(r.label)}</a>` : escapeHtml(r.label)}</td>
            <td class="text-end">${fmt(r.visited)}</td>
            <td class="text-end">${fmt(r.households)}</td>
            <td class="text-end">${fmt(r.caregivers)}</td>
            <td class="text-end">${fmt(r.children)}</td>
            <td class="text-end">${r.target ? fmt(r.target) : '—'}</td>
            <td style="min-width:150px">${rate}</td>
            <td class="text-end">${queries}</td>
        </tr>`;
    }).join('');
    tbody.querySelectorAll('.tree-toggle').forEach((button) => button.addEventListener('click', () => {
        const key = button.dataset.key;
        collapsed.has(key) ? collapsed.delete(key) : collapsed.add(key);
        hierarchy(rows);
    }));
}

function renderMap(points, boundaries = lastBoundaries) {
    const container = document.getElementById('map');
    if (!container) return;
    lastPoints = points;
    lastBoundaries = boundaries;
    if (!map) {
        map = baseMap(container);
        boundariesLayer = L.featureGroup().addTo(map);
        markersLayer = L.featureGroup().addTo(map);
    }
    boundariesLayer.clearLayers();
    drawBoundaries(boundariesLayer, boundaries, { teams: false });
    // Par défaut : recrutés en vert, autres en rouge ; sinon une couleur par grappe, binôme ou agent.
    const by = document.getElementById('map-color')?.value || 'status';
    const palette = new Map();
    markersLayer.clearLayers();
    legend?.remove();
    points.forEach((p) => {
        const popup = `<strong>Ménage ${escapeHtml(p.code)}</strong><br>${escapeHtml(p.date ?? '')}<br>Grappe : ${escapeHtml(p.cluster ?? '—')}${p.sub_cluster ? ` · sous-grappe ${escapeHtml(p.sub_cluster)}` : ''}<br>Binôme : ${escapeHtml(p.team ?? '—')} · agent : ${escapeHtml(p.collector ?? '—')}<br><span style="color:${p.consented ? STATUS_COLORS.recruited : STATUS_COLORS.other}">●</span> ${p.consented ? 'Recruté' : 'Non recruté'}`;
        if (by === 'status') {
            householdMarker(p, popup).addTo(markersLayer);
            return;
        }
        const key = p[by] ?? 'none';
        if (!palette.has(key)) palette.set(key, color(palette.size));
        L.circleMarker([p.lat, p.lng], { radius: 5, weight: 1, color: '#fff', fillColor: palette.get(key), fillOpacity: p.consented ? 0.9 : 0.4 })
            .bindPopup(popup).addTo(markersLayer);
    });
    if (by === 'status') legend = addStatusLegend(map, points);
    const bounds = L.featureGroup([boundariesLayer, markersLayer]).getBounds();
    if (bounds.isValid() && !container.dataset.fitted) {
        map.fitBounds(bounds, { padding: [20, 20], maxZoom: 13 });
        container.dataset.fitted = '1';
    }
    setTimeout(() => map.invalidateSize(), 50);
}

export default function progressPage() {
    const root = document.getElementById('progress-dashboard');
    const dataUrl = root.dataset.url;
    const refresh = Number(root.dataset.refresh || 60);
    const status = document.getElementById('refresh-status');
    let current = {};

    async function load() {
        status?.classList.remove('d-none');
        try {
            const data = await getJson(dataUrl, current);
            setKpis(data.kpis);
            dailyChart(data.daily);
            statusChart('chart-zones', data.by_zone);
            statusChart('chart-communes', data.by_commune);
            lastProductivity = { teams: data.productivity_teams, agents: data.productivity };
            renderProductivity();
            hierarchy(data.hierarchy);
            renderMap(data.points, data.boundaries);
            document.querySelectorAll('[data-generated-at]').forEach((el) => {
                el.textContent = data.generated_at;
            });
        } catch (error) {
            console.error(error);
        } finally {
            status?.classList.add('d-none');
        }
    }

    const filters = initFilterBar(document.getElementById('filter-bar'), (values) => {
        current = values;
        if (map) delete document.getElementById('map').dataset.fitted;
        load();
    });
    bindExportLinks(root, () => current);
    document.getElementById('map-color')?.addEventListener('change', () => renderMap(lastPoints));
    document.getElementById('productivity-by')?.addEventListener('change', renderProductivity);

    filters.ready.then(() => {
        current = filters.values();
        load();
    });

    if (refresh > 0) {
        setInterval(() => {
            if (!document.hidden) load();
        }, refresh * 1000);
    }
}
