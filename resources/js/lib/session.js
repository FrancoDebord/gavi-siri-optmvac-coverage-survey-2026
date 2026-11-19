// Jeton CSRF tenu à jour tant que la page est ouverte : évite l'erreur « page expirée » (419) quand on soumet
// un formulaire après une longue pause, ou après un retour arrière du navigateur.
const REFRESH_EVERY = 10 * 60 * 1000;
let lastRefresh = Date.now();

function apply(token) {
    if (!token) return;
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
    document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = token; });
    if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

async function refresh() {
    const url = document.querySelector('meta[name="session-refresh"]')?.content;
    if (!url) return;
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        if (response.ok) {
            apply((await response.json()).token);
            lastRefresh = Date.now();
        }
    } catch {
        // Hors connexion : nouvel essai au prochain passage.
    }
}

export function initSessionRefresh() {
    setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, REFRESH_EVERY);
    // Retour sur l'onglet après une pause, ou page restaurée par le bouton « précédent ».
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && Date.now() - lastRefresh > 5 * 60 * 1000) refresh();
    });
    window.addEventListener('pageshow', (event) => { if (event.persisted) refresh(); });
}
