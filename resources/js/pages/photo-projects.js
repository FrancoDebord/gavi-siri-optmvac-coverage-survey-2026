import { toast } from '../lib/dialog';

/** Gestion d'un espace photos : boutons de copie du lien, du code PIN et du message à partager (data-copy-text). */
export default function photoProjectsPage() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy-text]');
        if (!button) return;
        try {
            await navigator.clipboard.writeText(button.dataset.copyText);
            toast('Copié dans le presse-papiers.');
        } catch {
            window.prompt('Copiez le texte :', button.dataset.copyText);
        }
    });
}
