// Photos : rotation par quart de tour (photos de carnet prises de travers) et message si indisponible.
function initPhotos() {
    document.querySelectorAll('.query-photo').forEach((figure) => {
        const img = figure.querySelector('img');
        figure.querySelector('[data-rotate]')?.addEventListener('click', () => {
            const rotation = (Number(img.dataset.rotation) + 90) % 360;
            img.dataset.rotation = rotation;
            img.style.transform = `rotate(${rotation}deg)`;
            img.classList.toggle('rotated', rotation % 180 !== 0);
        });
        img.addEventListener('error', () => {
            img.closest('a').classList.add('d-none');
            figure.querySelector('.query-photo-error')?.classList.remove('d-none');
        });
    });
}

// Fiche d'une query : photos et aperçu des transitions autorisées.
export default function queryShowPage() {
    initPhotos();
    const select = document.querySelector('#query-status-form [name="status"]');
    const help = document.getElementById('status-help');
    if (!select || !help) return;
    const signature = document.getElementById('signature-field');
    const update = () => {
        const option = select.selectedOptions[0];
        help.textContent = option?.dataset.help ?? '';
        // Signature électronique (mot de passe) pour valider ou déclarer irrésoluble une query en double validation.
        if (signature) {
            const required = option?.dataset.signature === '1';
            signature.classList.toggle('d-none', !required);
            signature.querySelector('input').required = required;
        }
    };
    select.addEventListener('change', update);
    update();
}
