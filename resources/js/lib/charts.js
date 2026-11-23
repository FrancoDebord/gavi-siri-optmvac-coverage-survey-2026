import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    ArcElement,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, DoughnutController, ArcElement, Filler, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);

Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.font.size = 12;
Chart.defaults.color = '#706D6B'; // gris AIRID : légendes et axes
Chart.defaults.plugins.legend.labels.boxWidth = 12;
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.locale = 'fr-FR';

const instances = new Map();

/** Résolution de l'image exportée (× la taille affichée) : nette une fois agrandie dans PowerPoint. */
const IMAGE_SCALE = 3;

/**
 * Image PNG d'un graphique : rendu haute résolution, fond blanc (PowerPoint), titre au-dessus.
 * @returns {Promise<Blob>}
 */
function chartImage(canvas, title) {
    const chart = instances.get(canvas) ?? Chart.getChart(canvas);
    if (!chart) return Promise.reject(new Error('Graphique introuvable'));
    const previous = chart.options.devicePixelRatio;
    chart.options.devicePixelRatio = IMAGE_SCALE;
    chart.resize();
    chart.update('none');

    const titleHeight = title ? 34 * IMAGE_SCALE : 0;
    const margin = 12 * IMAGE_SCALE;
    const image = document.createElement('canvas');
    image.width = chart.canvas.width + 2 * margin;
    image.height = chart.canvas.height + titleHeight + 2 * margin;
    const ctx = image.getContext('2d');
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, image.width, image.height);
    if (title) {
        ctx.fillStyle = '#010101';
        ctx.font = `bold ${16 * IMAGE_SCALE}px ${Chart.defaults.font.family}`;
        ctx.textBaseline = 'top';
        ctx.fillText(title, margin, margin);
    }
    ctx.drawImage(chart.canvas, margin, margin + titleHeight);

    // Retour à l'affichage normal de la page.
    chart.options.devicePixelRatio = previous;
    chart.resize();
    chart.update('none');

    return new Promise((resolve, reject) => {
        image.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Image indisponible'))), 'image/png');
    });
}

const fileName = (title) => `${(title || 'graphique').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '').toLowerCase()}-${new Date().toISOString().slice(0, 10)}.png`;

function flash(button, icon) {
    const i = button.querySelector('i');
    const original = i.className;
    i.className = `bi bi-${icon}`;
    setTimeout(() => { i.className = original; }, 1500);
}

/**
 * Boutons des cartes de graphique (data-chart-image) : « download » télécharge le PNG, « copy » le copie dans le
 * presse-papiers pour le coller dans PowerPoint (repli : téléchargement si le navigateur refuse la copie).
 */
export function initChartImages() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-chart-image]');
        if (!button) return;
        const canvas = document.getElementById(button.dataset.chartCanvas);
        const title = button.dataset.chartTitle;
        const download = (blob) => {
            const url = URL.createObjectURL(blob);
            const link = Object.assign(document.createElement('a'), { href: url, download: fileName(title) });
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        };
        try {
            const blob = await chartImage(canvas, title);
            if (button.dataset.chartImage === 'copy') {
                try {
                    await navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]);
                    flash(button, 'clipboard-check');

                    return;
                } catch {
                    download(blob); // copie refusée par le navigateur : image téléchargée à la place
                }
            } else {
                download(blob);
            }
            flash(button, 'check-lg');
        } catch (error) {
            console.error(error);
            flash(button, 'x-lg');
        }
    });
}

/** Crée le graphique au premier appel, puis met simplement à jour ses données. */
export function renderChart(canvas, config) {
    if (!canvas) return null;
    const existing = instances.get(canvas);
    if (existing) {
        existing.data = config.data;
        if (config.options) existing.options = config.options;
        existing.update('none');
        return existing;
    }
    const chart = new Chart(canvas, config);
    instances.set(canvas, chart);
    return chart;
}
