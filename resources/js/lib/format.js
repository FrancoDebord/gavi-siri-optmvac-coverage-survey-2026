const numberFormat = new Intl.NumberFormat('fr-FR');

export const fmt = (value) => (value === null || value === undefined ? '—' : numberFormat.format(value));

export const pct = (value) => (value === null || value === undefined ? '—' : `${numberFormat.format(value)} %`);

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/** Couleur de barre de progression selon le taux de réalisation. */
export function rateColor(rate) {
    if (rate === null || rate === undefined) return 'secondary';
    if (rate >= 90) return 'success';
    if (rate >= 60) return 'dark';
    if (rate >= 30) return 'warning';
    return 'danger';
}

/** Palette AIRID : rouge #C10202, noir #010101, gris #706D6B, et couleurs de statut. */
export const AIRID = {
    red: '#C10202',
    redLight: '#E57373',
    black: '#010101',
    grey: '#706D6B',
    greyLight: '#B8B5B3',
    success: '#2E7D4F',
    warning: '#D98E04',
};

/**
 * Couleurs catégorielles (grappes et binômes sur la carte) : d'abord les couleurs de marque,
 * complétées par des teintes sobres pour distinguer de nombreuses catégories.
 */
export const PALETTE = ['#C10202', '#010101', '#706D6B', '#E57373', '#2E7D4F', '#D98E04', '#7A0101', '#B8B5B3', '#3F5E7A', '#8C6D4F'];

export const color = (index) => PALETTE[index % PALETTE.length];
