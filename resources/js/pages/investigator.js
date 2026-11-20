import L from 'leaflet';
import { renderChart } from '../lib/charts';
import { color, escapeHtml } from '../lib/format';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker } from '../lib/map';

function teamsChart(data, metric) {
    renderChart(document.getElementById('chart-pi-teams'), {
        type: 'bar',
        data: {
            labels: data.days,
            // Une barre par binôme et par jour, côte à côte (non empilées).
            datasets: data.series.map((s, i) => ({ label: s.label, data: s[metric], backgroundColor: color(i), maxBarThickness: 28 })),
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

function cumulativeChart(canvasId, data) {
    renderChart(document.getElementById(canvasId), {
        type: 'line',
        data: {
            labels: data.days,
            datasets: data.series.map((s, i) => ({ label: `${s.label} (${s.total})`, data: s.values, borderColor: color(i), backgroundColor: color(i), tension: 0.25, pointRadius: 2 })),
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
}

/** Ménages visités : recrutés en vert, autres en rouge. */
function householdsMap(points, boundaries) {
    const container = document.getElementById('pi-map');
    if (!container) return;
    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries, { teams: false });
    points.forEach((p) => {
        householdMarker(p, `<strong><a href="${p.url}">Ménage ${escapeHtml(p.code)}</a></strong><br>${escapeHtml(p.zone ?? '')} · ${escapeHtml(p.village ?? '')}<br>Grappe ${escapeHtml(p.cluster ?? '—')} · binôme ${escapeHtml(p.team ?? '—')}<br>${escapeHtml(p.date ?? '')} · ${p.consented ? 'recruté' : 'non recruté'}`)
            .addTo(layer);
    });
    addStatusLegend(map, points);
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20], maxZoom: 14 });
}

/**
 * Formulaire des rapports : n'affiche que les champs utiles au type choisi.
 * data-report-show="etat:zone villages" = visible pour l'état de la collecte au niveau « zone » et pour le rapport « villages ».
 * La zone est obligatoire pour « état / une zone », facultative (« Toutes les zones ») pour les autres rapports.
 */
function reportForm() {
    const form = document.getElementById('report-form');
    if (!form) return;
    const update = () => {
        const type = form.querySelector('[name="type"]:checked')?.value ?? 'etat';
        const level = form.querySelector('[name="niveau"]:checked')?.value ?? 'tous';
        form.querySelectorAll('[data-report-show]').forEach((block) => {
            const active = block.dataset.reportShow.split(' ').some((rule) => {
                const [t, l] = rule.split(':');
                return t === type && (!l || l === level);
            });
            block.classList.toggle('d-none', !active);
            block.querySelectorAll('select, input').forEach((field) => { field.disabled = !active; });
        });
        const optional = form.querySelector('#report-zone [data-optional]');
        if (optional) {
            optional.hidden = type === 'etat';
            if (type === 'etat' && optional.selected) optional.nextElementSibling.selected = true;
        }
    };
    form.addEventListener('change', update);
    update();
}

/** Vue d'ensemble de l'investigateur principal (données intégrées à la page). */
export default function investigatorPage() {
    const source = document.getElementById('pi-charts');
    if (!source) return;
    const data = JSON.parse(source.textContent);
    const metric = document.getElementById('pi-teams-metric');

    teamsChart(data.teams, metric?.value || 'surveyed');
    metric?.addEventListener('change', () => teamsChart(data.teams, metric.value));
    cumulativeChart('chart-pi-villages', data.villages);
    cumulativeChart('chart-pi-zones', data.zones);
    householdsMap(data.points, data.boundaries);
    reportForm();
}
