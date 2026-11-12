import L from 'leaflet';
import { escapeHtml } from '../lib/format';
import { baseMap, drawBoundaries, householdMarker, STATUS_COLORS } from '../lib/map';

/** Fiche ménage : le ménage mis en évidence, les contours de sa grappe / sous-grappes et les autres ménages de la grappe. */
export default function householdPage() {
    const container = document.getElementById('household-map');
    if (!container) return;
    const { household, neighbours, boundaries } = JSON.parse(container.dataset.map);

    const map = baseMap(container);
    const layer = L.featureGroup().addTo(map);
    drawBoundaries(layer, boundaries);
    neighbours.forEach((p) => {
        householdMarker(p, `<a href="${p.url}">Ménage ${escapeHtml(p.code)}</a>${p.sub_cluster ? `<br>Sous-grappe ${escapeHtml(p.sub_cluster)}` : ''}`, 4).setStyle({ fillOpacity: 0.55 }).addTo(layer);
    });

    const fill = household.consented ? STATUS_COLORS.recruited : STATUS_COLORS.other;
    if (household.accuracy) {
        L.circle([household.lat, household.lng], { radius: household.accuracy, color: '#0d6efd', weight: 1, fillOpacity: 0.08, interactive: false }).addTo(map);
    }
    const marker = L.circleMarker([household.lat, household.lng], { radius: 10, weight: 4, color: '#fff', fillColor: fill, fillOpacity: 1 })
        .bindPopup(`<strong>Ménage ${escapeHtml(household.code)}</strong><br>${household.consented ? 'Recruté' : 'Non recruté'}`)
        .addTo(map);
    // Anneau sombre autour du point pour le distinguer des autres ménages.
    L.circleMarker([household.lat, household.lng], { radius: 13, weight: 2, color: '#010101', fill: false, interactive: false }).addTo(map);
    marker.bringToFront();

    const bounds = layer.getBounds().extend([household.lat, household.lng]);
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 17 });
}
