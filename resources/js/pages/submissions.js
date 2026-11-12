import L from 'leaflet';
import { escapeHtml } from '../lib/format';
import { addStatusLegend, baseMap, drawBoundaries, householdMarker } from '../lib/map';

/** Soumissions : ménages filtrés (vert : recrutés, rouge : autres) sur les contours des grappes et sous-grappes. */
export default function submissionsPage() {
    const container = document.getElementById('submissions-map');
    if (!container) return;
    const { points, boundaries } = JSON.parse(container.dataset.map);

    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries);
    points.forEach((p) => {
        householdMarker(p, `<strong><a href="${p.url}">Ménage ${escapeHtml(p.code)}</a></strong><br>Grappe ${escapeHtml(p.cluster ?? '—')}${p.sub_cluster ? ` · S${escapeHtml(p.sub_cluster)}` : ''}<br>${escapeHtml(p.date ?? '')} · ${escapeHtml(p.status)}`)
            .addTo(layer);
    });
    addStatusLegend(map, points);
    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20], maxZoom: 16 });

    // Carte repliable : Leaflet doit recalculer sa taille quand elle réapparaît.
    document.getElementById('submissions-map-card')?.addEventListener('shown.bs.collapse', () => map.invalidateSize());
}
