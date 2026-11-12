import { renderChart } from '../lib/charts';
import { AIRID } from '../lib/format';

/** Espace superviseur : graphique d'évolution journalière (données intégrées à la page). */
export default function supervisionPage() {
    const source = document.getElementById('supervision-daily');
    if (!source) return;
    const rows = JSON.parse(source.textContent);

    renderChart(document.getElementById('chart-supervision-daily'), {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.date),
            datasets: [
                { type: 'bar', label: 'Ménages visités', data: rows.map((r) => r.visited), backgroundColor: 'rgba(112,109,107,.35)', yAxisID: 'y' },
                { type: 'bar', label: 'Ménages enquêtés', data: rows.map((r) => r.surveyed), backgroundColor: AIRID.success, yAxisID: 'y' },
                { type: 'line', label: 'Enquêtés (cumul)', data: rows.map((r) => r.cum_surveyed), borderColor: AIRID.red, backgroundColor: AIRID.red, tension: 0.25, yAxisID: 'y1' },
            ],
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Par jour' }, ticks: { precision: 0 } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Cumul' }, ticks: { precision: 0 } },
            },
        },
    });
}
