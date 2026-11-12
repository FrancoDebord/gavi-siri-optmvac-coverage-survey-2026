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
