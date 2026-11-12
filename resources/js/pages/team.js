import L from 'leaflet';
import { renderChart } from '../lib/charts';
import { AIRID, escapeHtml } from '../lib/format';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker } from '../lib/map';

/** Onglet « Progression » : barres côte à côte par jour et cumul des ménages enquêtés. */
function dailyChart() {
    const source = document.getElementById('team-daily');
    if (!source) return;
    const rows = JSON.parse(source.textContent);
    const label = (r) => r.label.slice(0, 5);

    renderChart(document.getElementById('chart-team-daily'), {
        type: 'bar',
        data: {
            labels: rows.map(label),
            datasets: [
                { type: 'bar', label: 'Ménages visités', data: rows.map((r) => r.visited), backgroundColor: AIRID.grey, yAxisID: 'y', maxBarThickness: 32 },
                { type: 'bar', label: 'Ménages enquêtés', data: rows.map((r) => r.surveyed), backgroundColor: AIRID.success, yAxisID: 'y', maxBarThickness: 32 },
                { type: 'bar', label: 'Enfants éligibles', data: rows.map((r) => r.children), backgroundColor: AIRID.warning, yAxisID: 'y', maxBarThickness: 32 },
                { type: 'line', label: 'Enquêtés (cumul)', data: rows.map((r) => r.cum_surveyed), borderColor: AIRID.red, backgroundColor: AIRID.red, tension: 0.25, yAxisID: 'y1' },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Par jour' }, ticks: { precision: 0 } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Cumul' }, ticks: { precision: 0 } },
            },
        },
    });
}

/** Onglet « Grappes » : contours relevés et ménages du binôme (vert : enquêtés, rouge : autres). */
function clustersMap() {
    const container = document.getElementById('team-map');
    if (!container) return;
    const { boundaries, points } = JSON.parse(container.dataset.map);
    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries);
    points.forEach((p) => {
        householdMarker(p, `<strong><a href="${p.url}">Ménage ${escapeHtml(p.code)}</a></strong><br>Grappe ${escapeHtml(p.cluster ?? '—')}${p.sub_cluster ? ` · S${escapeHtml(p.sub_cluster)}` : ''}<br>${escapeHtml(p.date ?? '')} · ${escapeHtml(p.status)}`)
            .addTo(layer);
    });
    addStatusLegend(map, points);
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20], maxZoom: 17 });
}

export default function teamPage() {
    dailyChart();
    clustersMap();
}
