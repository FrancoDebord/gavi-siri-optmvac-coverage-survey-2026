import { renderChart } from '../lib/charts';
import { groupChart, initCoveragePage, levelTable, rateCell, setKpis, sourceChart } from '../lib/coverage';
import { AIRID, fmt, pct } from '../lib/format';
import { initTableCopy, rateOnly, renderPptTables } from '../lib/ppt-tables';

/** Couverture CPS : par cycle (carte / rappel), nombre de cycles reçus, puis par zone, commune, âge et sexe. */
// Une couleur par cycle, du plus clair (cycle 1) au rouge AIRID (cycle 5).
const CYCLE_COLORS = [AIRID.greyLight, AIRID.grey, AIRID.black, AIRID.redLight, AIRID.red];

export default function smcCoveragePage() {
    const root = document.getElementById('smc-coverage');
    const cycles = Array.from({ length: Number(root.dataset.maxCycles || 5) }, (_, i) => i + 1);
    let last = null;

    // Cycles prévus dans au moins une commune de l'échantillon filtré.
    const planned = (data) => cycles.filter((k) => data.total.cycles[k].denominator > 0);
    const renderLevel = () => {
        if (!last) return;
        levelTable(last, [
            ...planned(last).map((k) => ({ label: `C${k}`, cell: (r) => rateCell(r.cycles[k].pct, r.cycles[k].received, r.cycles[k].denominator) })),
            { label: '≥ 1 cycle', cell: (r) => rateCell(r.at_least_one) },
            { label: 'Tous les cycles', cell: (r) => rateCell(r.all_cycles) },
            { label: 'CPS déclarée', cell: (r) => `<td class="text-end">${pct(r.declared)}</td>` },
        ]);
    };

    initCoveragePage(root, (data) => {
        last = data;
        const t = data.total;
        setKpis({
            children: fmt(t.children),
            declared: pct(t.declared),
            card_given: pct(t.card_given),
            card_present: pct(t.card_present),
            at_least_one: pct(t.at_least_one),
            all_cycles: pct(t.all_cycles),
        });
        const ks = planned(data);
        sourceChart('chart-cycles', ks.map((k) => `Cycle ${k}`), ks.map((k) => t.cycles[k].pct_card),
            ks.map((k) => Math.round((t.cycles[k].pct - t.cycles[k].pct_card) * 10) / 10));
        renderChart(document.getElementById('chart-distribution'), {
            type: 'bar',
            data: {
                labels: t.distribution.map((_, n) => `${n} cycle${n > 1 ? 's' : ''}`),
                datasets: [{ label: 'Enfants', data: t.distribution, backgroundColor: t.distribution.map((_, n) => (n === 0 ? AIRID.red : AIRID.black)) }],
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });
        const series = ks.map((k) => ({ label: `Cycle ${k}`, color: CYCLE_COLORS[k - 1], value: (r) => r.cycles[k].pct }));
        groupChart('chart-zones', data.by_zone, series);
        groupChart('chart-communes', data.by_commune, series);
        groupChart('chart-age', data.by_age, series);
        groupChart('chart-sex', data.by_sex, series);
        document.querySelector('#cycle-table tbody').innerHTML = ks.map((k) => {
            const c = t.cycles[k];
            return `<tr><td class="fw-semibold">Cycle ${k}</td><td class="text-end">${fmt(c.denominator)}</td><td class="text-end">${fmt(c.card)}</td><td class="text-end">${fmt(c.recall)}</td>
                <td class="text-end">${fmt(c.received)}</td><td class="text-end fw-semibold">${pct(c.pct)}</td><td class="text-end">${pct(c.pct_card)}</td></tr>`;
        }).join('');
        renderLevel();
        // Tableaux pour PowerPoint : cycles prévus, au moins un cycle, tous les cycles, CPS déclarée (en %).
        renderPptTables(data.tables, [
            ...ks.map((k) => ({ label: `Cycle ${k}`, value: (r) => rateOnly(r.cycles[k].pct) })),
            { label: '≥ 1 cycle', value: (r) => rateOnly(r.at_least_one) },
            { label: 'Tous les cycles', value: (r) => rateOnly(r.all_cycles) },
            { label: 'CPS déclarée', value: (r) => rateOnly(r.declared) },
        ], t);
    });
    initTableCopy(root);
    document.getElementById('coverage-level')?.addEventListener('change', renderLevel);
}
