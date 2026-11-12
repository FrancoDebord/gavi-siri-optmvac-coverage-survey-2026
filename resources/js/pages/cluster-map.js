import L from 'leaflet';
import { escapeHtml } from '../lib/format';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker } from '../lib/map';

/** Carte des grappes d'un village : contours de toutes les grappes et sous-grappes, ménages visités. */
export default function clusterMapPage() {
    // « Ouvrir dans une fenêtre » : fenêtre libre, redimensionnable, sans menu.
    document.querySelectorAll('[data-open-window]').forEach((button) => {
        button.addEventListener('click', () => {
            const width = Math.min(1400, window.screen.availWidth - 80);
            const height = Math.min(900, window.screen.availHeight - 80);
            window.open(button.dataset.openWindow, 'carte-grappes', `popup=yes,width=${width},height=${height},resizable=yes,scrollbars=yes`)?.focus();
        });
    });

    const container = document.getElementById('cluster-map-explorer');
    if (!container) return;
    const { points, boundaries } = JSON.parse(container.dataset.map);
    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries);
    points.forEach((p) => {
        householdMarker(p, `<strong><a href="${p.url}">Ménage ${escapeHtml(p.code)}</a></strong><br>Grappe ${escapeHtml(p.cluster ?? '—')}${p.sub_cluster ? ` · S${escapeHtml(p.sub_cluster)}` : ''}${p.team ? `<br>Binôme ${escapeHtml(p.team)}` : ''}<br>${escapeHtml(p.date ?? '')} · ${escapeHtml(p.status)}`)
            .addTo(layer);
    });
    addStatusLegend(map, points);
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 17 });
    // Carte d'exploration : zoom à la molette actif d'emblée.
    map.scrollWheelZoom.enable();
}
