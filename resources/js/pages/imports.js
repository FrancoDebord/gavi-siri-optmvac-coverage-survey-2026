import { getJson } from '../lib/http';

// Barre de progression des imports en cours (polling toutes les 2 s).
export default function importsPage() {
    document.querySelectorAll('[data-import-status]').forEach((row) => {
        const url = row.dataset.importStatus;
        const bar = row.querySelector('.progress-bar');
        const label = row.querySelector('[data-status-label]');
        const message = row.querySelector('[data-message]');

        const poll = async () => {
            try {
                const data = await getJson(url);
                bar.style.width = `${data.progress}%`;
                bar.textContent = `${data.progress} %`;
                label.textContent = data.status_label;
                label.className = `badge text-bg-${data.status_color}`;
                if (message) message.textContent = data.message ?? '';
                if (['done', 'failed'].includes(data.status)) {
                    bar.classList.remove('progress-bar-animated');
                    return;
                }
            } catch {
                /* nouvel essai au prochain tour */
            }
            setTimeout(poll, 2000);
        };
        poll();
    });
}
