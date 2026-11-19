// Filtre rapide côté serveur : <form data-live-filter="#zone-resultats">. À chaque frappe (après une courte pause)
// ou changement de liste, la page filtrée est demandée et seule la zone de résultats est remplacée ;
// l'adresse est mise à jour (lien partageable, retour arrière). Sans JavaScript, le formulaire reste un GET classique.
export function initLiveFilters() {
    document.querySelectorAll('form[data-live-filter]').forEach((form) => {
        const target = document.querySelector(form.dataset.liveFilter);
        if (!target) return;
        let timer = null;
        let controller = null;

        const refresh = async () => {
            const params = new URLSearchParams(new FormData(form));
            [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });
            const url = `${form.action}${params.toString() ? `?${params}` : ''}`;
            controller?.abort();
            controller = new AbortController();
            target.classList.add('opacity-50');
            try {
                const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: controller.signal });
                const html = new DOMParser().parseFromString(await response.text(), 'text/html');
                const fresh = html.querySelector(form.dataset.liveFilter);
                if (fresh) target.innerHTML = fresh.innerHTML;
                window.history.replaceState(null, '', url);
            } catch (error) {
                if (error.name !== 'AbortError') window.location.assign(url);
            } finally {
                target.classList.remove('opacity-50');
            }
        };

        form.addEventListener('input', (event) => {
            if (event.target.tagName !== 'INPUT') return;
            clearTimeout(timer);
            timer = setTimeout(refresh, 300);
        });
        form.addEventListener('change', (event) => {
            if (event.target.tagName === 'SELECT') refresh();
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            clearTimeout(timer);
            refresh();
        });
        // Échap vide la recherche.
        form.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && event.target.type === 'search' && event.target.value !== '') {
                event.target.value = '';
                refresh();
            }
        });
    });
}
