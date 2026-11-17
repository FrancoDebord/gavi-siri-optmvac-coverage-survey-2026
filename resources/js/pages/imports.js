import { getJson } from '../lib/http';

// Barre de progression des imports en cours (polling toutes les 2 s).
function pollImportBatches() {
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

/**
 * Part de la barre réservée à chaque étape d'un import de contours : envoi des fichiers (mesuré par le navigateur),
 * puis étapes publiées par le serveur (ClusterBoundaryImporter::run et ImportController).
 */
const STAGES = {
    envoi: { range: [0, 30], label: () => 'Envoi des fichiers au serveur…' },
    attente: { range: [30, 32], label: () => 'Traitement en cours…' },
    depot: { range: [32, 36], label: (d) => `Enregistrement des relevés (${d.done}/${d.total})` },
    lecture: { range: [36, 86], label: (d) => `Lecture et contrôle des relevés (${d.done}/${d.total})` },
    application: { range: [86, 95], label: () => 'Application des contours aux grappes…' },
    compilation: { range: [95, 99], label: () => 'Compilation des points GPS…' },
    termine: { range: [100, 100], label: () => 'Terminé, actualisation de la page…' },
};

/** UUID v4 (crypto.randomUUID n'existe que sur une page HTTPS ; getRandomValues partout). */
function uuid() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function stagePercent(step, done = 0, total = 0) {
    const [from, to] = (STAGES[step] ?? STAGES.attente).range;
    return from + (total > 0 ? Math.min(done / total, 1) : 0) * (to - from);
}

/**
 * Dépôt et réimport des contours : envoi en arrière-plan avec barre de progression (envoi puis traitement),
 * puis rechargement de la page qui affiche le résultat. Sans JavaScript, le formulaire s'envoie normalement.
 */
function boundaryUploads() {
    const panel = document.getElementById('boundary-progress');
    const forms = document.querySelectorAll('form[data-boundary-progress]');
    if (!panel || !forms.length || !window.crypto?.getRandomValues) return;

    const bar = panel.querySelector('.progress-bar');
    const track = panel.querySelector('.progress');
    const label = panel.querySelector('[data-progress-label]');
    const percent = panel.querySelector('[data-progress-percent]');
    const detail = panel.querySelector('[data-progress-detail]');
    let running = false;

    // Sans fichier à envoyer (réimport du dossier), la barre démarre au début du traitement.
    const show = (value, text, sub = '', withUpload = true, variant = null) => {
        const start = withUpload ? 0 : STAGES.attente.range[0];
        const shown = Math.max(0, Math.min(100, Math.round(((value - start) / (100 - start)) * 100)));
        panel.classList.remove('d-none');
        bar.style.width = `${shown}%`;
        track.setAttribute('aria-valuenow', String(shown));
        percent.textContent = `${shown} %`;
        label.textContent = text;
        detail.textContent = sub;
        bar.classList.toggle('bg-danger', variant === 'danger');
        bar.classList.toggle('progress-bar-animated', variant !== 'danger');
    };

    const setBusy = (busy) => {
        running = busy;
        forms.forEach((f) => f.querySelectorAll('button, input[type="file"]').forEach((el) => { el.disabled = busy; }));
    };

    const fail = (message, withUpload) => {
        show(100, message, 'Aucune modification n\'a été perdue : corrigez puis relancez.', withUpload, 'danger');
        percent.textContent = '';
        setBusy(false);
    };

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            // La confirmation (data-confirm, lib/dialog.js) passe d'abord ; on intercepte l'envoi confirmé.
            if (form.dataset.confirm && form.dataset.confirmed !== '1') return;
            event.preventDefault();
            delete form.dataset.confirmed;
            if (running) return;

            const token = uuid();
            const progressUrl = form.dataset.progressUrl.replace('00000000-0000-0000-0000-000000000000', token);
            const data = new FormData(form);
            data.append('progress_token', token);
            const fileInput = form.querySelector('input[type="file"]');
            const withUpload = !!fileInput?.files.length;
            let finished = false;
            let polling = false;

            // Avancement du traitement côté serveur, lu jusqu'à la réponse (une seule boucle de lecture).
            const startPolling = () => {
                if (polling) return;
                polling = true;
                poll();
            };
            const poll = async () => {
                if (finished) return;
                try {
                    const state = await getJson(progressUrl);
                    if (!finished && state.step !== 'termine') {
                        const stage = STAGES[state.step] ?? STAGES.attente;
                        show(stagePercent(state.step, state.done, state.total), stage.label(state), state.detail ?? '', withUpload);
                    }
                } catch {
                    /* nouvel essai au prochain tour */
                }
                if (!finished) setTimeout(poll, 600);
            };

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.addEventListener('progress', (e) => {
                if (!e.lengthComputable) return;
                show(stagePercent('envoi', e.loaded, e.total), STAGES.envoi.label(), `${(e.loaded / 1024).toFixed(0)} Ko sur ${(e.total / 1024).toFixed(0)} Ko`, withUpload);
            });
            xhr.upload.addEventListener('load', () => {
                show(stagePercent('attente'), STAGES.attente.label(), '', withUpload);
                startPolling();
            });
            xhr.addEventListener('load', () => {
                finished = true;
                let body = {};
                try { body = JSON.parse(xhr.responseText); } catch { /* réponse non JSON */ }
                if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
                    show(100, STAGES.termine.label(), '', withUpload);
                    window.location.assign(body.redirect);
                    return;
                }
                const message = xhr.status === 422
                    ? Object.values(body.errors ?? {}).flat().join(' ') || body.message
                    : { 419: 'Session expirée : rechargez la page puis recommencez.', 429: 'Trop d\'envois rapprochés : patientez une minute.' }[xhr.status]
                        ?? body.message ?? `Erreur ${xhr.status} pendant l'import.`;
                fail(message, withUpload);
            });
            xhr.addEventListener('error', () => {
                finished = true;
                fail('Connexion interrompue pendant l\'envoi. Vérifiez la connexion puis relancez.', withUpload);
            });

            setBusy(true);
            show(0, withUpload ? STAGES.envoi.label() : STAGES.attente.label(), '', withUpload);
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            xhr.send(data);
            // Réimport sans fichier : pas d'événement d'envoi, on lit l'avancement tout de suite.
            if (!withUpload) startPolling();
        });
    });
}

export default function importsPage() {
    pollImportBatches();
    boundaryUploads();
}
