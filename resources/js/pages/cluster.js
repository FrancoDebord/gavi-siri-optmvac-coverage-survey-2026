import L from 'leaflet';
import { escapeHtml } from '../lib/format';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker } from '../lib/map';

/** Fiche grappe : contour, sous-grappes et ménages (recrutés en vert, autres en rouge). */
export default function clusterPage() {
    const container = document.getElementById('cluster-map');
    if (!container) return;
    const { boundaries, points } = JSON.parse(container.dataset.map);

    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries, { link: false });

    points.forEach((p) => {
        householdMarker(p, `<strong><a href="${p.url}">Ménage ${escapeHtml(p.code)}</a></strong><br>Sous-grappe : ${escapeHtml(p.sub_cluster ?? '—')}<br>${escapeHtml(p.status)}`, 6)
            .addTo(layer);
    });
    addStatusLegend(map, points);

    const bounds = layer.getBounds();
    if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [20, 20], maxZoom: 17 });
    }
}
