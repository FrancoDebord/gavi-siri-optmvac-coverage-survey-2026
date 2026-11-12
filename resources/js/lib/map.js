import L from 'leaflet';
import { AIRID, escapeHtml } from './format';

/** Fonds de carte au choix (sélecteur en haut à droite) ; le dernier choisi est mémorisé. */
const TILES = {
    'Plan (OpenStreetMap)': ['https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; contributeurs OpenStreetMap' }],
    'Humanitaire (OSM HOT)': ['https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; contributeurs OpenStreetMap, style HOT' }],
    'Satellite (Esri)': ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Imagerie &copy; Esri, Maxar, Earthstar Geographics' }],
    'Relief (OpenTopoMap)': ['https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', { maxZoom: 17, attribution: '&copy; OpenStreetMap, SRTM, style OpenTopoMap' }],
    'Clair (CARTO)': ['https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 20, subdomains: 'abcd', attribution: '&copy; OpenStreetMap, &copy; CARTO' }],
};
const TILE_KEY = 'gavi.map.tiles';

/** Ménages recrutés (ayant accepté) en vert, autres ménages visités en rouge. */
export const STATUS_COLORS = { recruited: AIRID.success, other: AIRID.red };

function stored(value) {
    try {
        if (value === undefined) return localStorage.getItem(TILE_KEY);
        localStorage.setItem(TILE_KEY, value);
    } catch {
        // stockage indisponible (navigation privée) : fond par défaut
    }
    return null;
}

/**
 * Bouton plein écran (en haut à gauche, sous le zoom) : la carte occupe toute la fenêtre ; Échap pour revenir.
 */
function addFullscreenControl(map, container) {
    const control = L.control({ position: 'topleft' });
    control.onAdd = () => {
        const bar = L.DomUtil.create('div', 'leaflet-bar');
        const button = L.DomUtil.create('a', 'map-fullscreen-toggle', bar);
        button.href = '#';
        button.role = 'button';
        button.title = 'Agrandir la carte (plein écran)';
        button.setAttribute('aria-label', button.title);
        button.innerHTML = '<i class="bi bi-arrows-fullscreen"></i>';
        const toggle = (force) => {
            const on = container.classList.toggle('map-fullscreen', force);
            document.body.classList.toggle('map-fullscreen-open', on);
            button.innerHTML = `<i class="bi bi-${on ? 'fullscreen-exit' : 'arrows-fullscreen'}"></i>`;
            button.title = on ? 'Réduire la carte (Échap)' : 'Agrandir la carte (plein écran)';
            if (on) map.scrollWheelZoom.enable();
            setTimeout(() => map.invalidateSize(), 60);
        };
        L.DomEvent.on(button, 'click', (event) => {
            L.DomEvent.preventDefault(event);
            L.DomEvent.stopPropagation(event);
            toggle();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && container.classList.contains('map-fullscreen')) toggle(false);
        });
        return bar;
    };
    control.addTo(map);
}

/**
 * Carte Leaflet de base (centrée sur le Bénin) : fonds de carte au choix, échelle, plein écran.
 * Zoom à la molette : activé dès qu'on clique dans la carte (désactivé quand la souris la quitte,
 * pour ne pas gêner le défilement de la page) ; toujours actif en plein écran.
 */
export function baseMap(container) {
    const map = L.map(container, { scrollWheelZoom: false, zoomSnap: 0.5 }).setView([8.5, 2.4], 7);
    const layers = Object.fromEntries(Object.entries(TILES).map(([name, [url, options]]) => [name, L.tileLayer(url, options)]));
    const initial = layers[stored()] ? stored() : Object.keys(TILES)[0];
    layers[initial].addTo(map);
    L.control.layers(layers, null, { position: 'topright' }).addTo(map);
    L.control.scale({ imperial: false }).addTo(map);
    addFullscreenControl(map, container);
    map.on('baselayerchange', (event) => stored(event.name));
    map.on('click focus', () => map.scrollWheelZoom.enable());
    map.on('mouseout', () => {
        if (!container.classList.contains('map-fullscreen')) map.scrollWheelZoom.disable();
    });

    return map;
}

/** Point d'un ménage : vert s'il est recruté, rouge sinon. */
export function householdMarker(point, popup, radius = 5) {
    return L.circleMarker([point.lat, point.lng], {
        radius,
        weight: 1,
        color: '#fff',
        fillColor: point.consented ? STATUS_COLORS.recruited : STATUS_COLORS.other,
        fillOpacity: 0.9,
    }).bindPopup(popup);
}

/** Légende en bas à droite : [[libellé, couleur], …]. */
export function addLegend(map, items) {
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = () => {
        const div = L.DomUtil.create('div', 'bg-white rounded shadow-sm px-2 py-1 small');
        div.innerHTML = items.map(([label, fill]) => `<div><span class="d-inline-block rounded-circle me-1 align-middle" style="width:10px;height:10px;background:${fill}"></span>${escapeHtml(label)}</div>`).join('');
        return div;
    };
    legend.addTo(map);

    return legend;
}

/** Légende des statuts avec effectifs. */
export function addStatusLegend(map, points) {
    const recruited = points.filter((p) => p.consented).length;
    return addLegend(map, [
        [`Recrutés (${recruited})`, STATUS_COLORS.recruited],
        [`Non recrutés : refus, absents, sans enfant… (${points.length - recruited})`, STATUS_COLORS.other],
    ]);
}

/**
 * Couleurs des contours, distinctes de celles des ménages (vert / rouge) :
 * grappe principale en bleu nuit (trait plein épais), sous-grappes en pointillés, une couleur par numéro.
 */
export const BOUNDARY_COLORS = {
    // Palette harmonisée (même saturation, tons complémentaires du bleu nuit) ; ni vert ni rouge, réservés aux ménages.
    cluster: '#1B3A6B',
    subClusters: ['#E8A33D', '#7E57C2', '#2E9CCA', '#D6589A', '#8D6E63', '#C9A227'],
};

const subClusterColor = (number) => BOUNDARY_COLORS.subClusters[(Math.max(1, Number(number) || 1) - 1) % BOUNDARY_COLORS.subClusters.length];

const legends = new WeakMap();

/** Légende des contours (en bas à gauche) : grappe et sous-grappes présentes sur la carte ; remplacée à chaque appel. */
export function addBoundaryLegend(map, boundaries) {
    legends.get(map)?.remove();
    const hasCluster = boundaries.some((b) => b.boundary);
    const numbers = [...new Set(boundaries.flatMap((b) => (b.sub_clusters || []).map((s) => Number(s.number))))].sort((a, b) => a - b);
    if (!hasCluster && !numbers.length) return null;

    const line = (c, dashed, width) => `<span class="d-inline-block me-1 align-middle" style="width:22px;border-top:${width}px ${dashed ? 'dashed' : 'solid'} ${c}"></span>`;
    const legend = L.control({ position: 'bottomleft' });
    legend.onAdd = () => {
        const div = L.DomUtil.create('div', 'bg-white rounded shadow-sm px-2 py-1 small');
        div.innerHTML = [
            hasCluster ? `<div>${line(BOUNDARY_COLORS.cluster, false, 3)}Grappe</div>` : '',
            ...numbers.map((n) => `<div>${line(subClusterColor(n), true, 2)}Sous-grappe ${n}</div>`),
        ].join('');
        return div;
    };
    legend.addTo(map);
    legends.set(map, legend);

    return legend;
}

/**
 * Contours GeoJSON des grappes (bleu nuit, trait plein) et de leurs sous-grappes (pointillés, couleur par numéro).
 * boundaries : [{ code, name, url?, boundary, sub_clusters: [{ number, boundary }] }]
 */
export function drawBoundaries(layer, boundaries, { link = true } = {}) {
    // Chaque contour (un par hameau) a sa fenêtre : hameau et superficie ; un point isolé (< 3 points) est un petit rond.
    const geoJson = (data, style, header) => L.geoJSON(data, {
        style,
        pointToLayer: (feature, latlng) => L.circleMarker(latlng, { ...style, radius: 4, fillOpacity: 0.8 }),
        onEachFeature: (feature, featureLayer) => {
            const props = feature.properties || {};
            const details = [
                props.hameau ? `Hameau ${escapeHtml(props.hameau)}` : null,
                props.superficie_ha !== undefined ? `${String(props.superficie_ha).replace('.', ',')} ha` : null,
            ].filter(Boolean).join(' · ');
            featureLayer.bindPopup(header + (details ? `<br>${details}` : ''));
        },
    });

    boundaries.forEach((cluster) => {
        const title = `<strong>Grappe ${escapeHtml(cluster.code)}</strong><br>${escapeHtml(cluster.name)}`;
        const more = link && cluster.url ? `<br><a href="${cluster.url}">Voir la grappe et ses ménages</a>` : '';
        if (cluster.boundary) {
            const c = BOUNDARY_COLORS.cluster;
            geoJson(cluster.boundary, { color: c, weight: 3.5, opacity: 0.95, fillColor: c, fillOpacity: 0.05 }, title + more).addTo(layer);
        }
        // Sous-grappes dessinées après la grappe, donc par-dessus.
        (cluster.sub_clusters || []).forEach((sub) => {
            const c = subClusterColor(sub.number);
            geoJson(sub.boundary, { color: c, weight: 2.5, dashArray: '8 6', opacity: 0.95, fillColor: c, fillOpacity: 0.08 }, `${title}<br>Sous-grappe ${escapeHtml(sub.number)}${more}`).addTo(layer);
        });
    });
    if (layer._map) addBoundaryLegend(layer._map, boundaries);
}
