/** Observations de supervision : sélection des observations à envoyer dans les queries. */
export default function observationsPage() {
    const boxes = () => [...document.querySelectorAll('[data-observation]')];
    const all = document.querySelector('[data-select-all]');
    const button = document.querySelector('[data-escalate-selected]');
    const count = document.querySelector('[data-selected-count]');
    if (!button) return;

    const refresh = () => {
        const checked = boxes().filter((box) => box.checked).length;
        count.textContent = checked;
        button.disabled = checked === 0;
        if (all) {
            all.checked = checked > 0 && checked === boxes().length;
            all.indeterminate = checked > 0 && checked < boxes().length;
        }
    };

    all?.addEventListener('change', () => {
        boxes().forEach((box) => { box.checked = all.checked; });
        refresh();
    });
    boxes().forEach((box) => box.addEventListener('change', refresh));
    refresh();
}
