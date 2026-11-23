import { groupChart, initCoveragePage, levelTable, rateCell, setKpis, sourceChart } from '../lib/coverage';
import { AIRID, escapeHtml, fmt, pct } from '../lib/format';

/** Couverture des antigènes du calendrier : toutes les doses, puis une dose choisie par zone, commune, âge et sexe. */
// Doses principales du tableau par niveau (si renseignées dans les données).
const KEY_DOSES = ['bcg', 'penta1', 'penta3', 'opv3', 'pcv3', 'rota3', 'meas1', 'meas2', 'yf'];

export default function vaccineCoveragePage() {
    const root = document.getElementById('vaccine-coverage');
    const doses = JSON.parse(document.getElementById('dose-list')?.textContent || '[]');
    const byCode = Object.fromEntries(doses.map((d) => [d.code, d]));
    const select = document.getElementById('dose-select');
    let last = null;

    const renderDose = () => {
        if (!last) return;
        const code = select?.value || 'penta3';
        const series = [{ label: byCode[code]?.label ?? code, color: AIRID.black, value: (r) => r.doses[code]?.pct ?? null }];
        groupChart('chart-zones', last.by_zone, series);
        groupChart('chart-communes', last.by_commune, series);
        groupChart('chart-age', last.by_age, series);
        groupChart('chart-sex', last.by_sex, series);
    };
    const renderLevel = () => {
        if (!last) return;
        const columns = KEY_DOSES.filter((code) => last.collected.includes(code)).map((code) => ({
            label: byCode[code]?.label ?? code,
            cell: (r) => rateCell(r.doses[code]?.pct, r.doses[code]?.received, r.doses[code]?.denominator),
        }));
        columns.push({ label: 'Complets (base)', cell: (r) => rateCell(r.fully_basic) });
        columns.push({ label: 'Zéro dose', cell: (r) => `<td class="text-end">${pct(r.zero_dose)}</td>` });
        levelTable(last, columns);
    };

    initCoveragePage(root, (data) => {
        last = data;
        const t = data.total;
        setKpis({
            children: fmt(t.children),
            card_seen: pct(t.card_seen),
            fully_basic: pct(t.fully_basic),
            fully_age: pct(t.fully_age),
            zero_dose: pct(t.zero_dose),
            dropout_penta: pct(t.dropout_penta),
            dropout_penta_rr: pct(t.dropout_penta_rr),
        });
        const shown = doses.filter((d) => data.collected.includes(d.code));
        sourceChart('chart-doses', shown.map((d) => d.label), shown.map((d) => t.doses[d.code].pct_card),
            shown.map((d) => Math.round((t.doses[d.code].pct - t.doses[d.code].pct_card) * 10) / 10), true);
        document.querySelector('#dose-table tbody').innerHTML = shown.map((d) => {
            const x = t.doses[d.code];
            return `<tr><td class="fw-semibold">${escapeHtml(d.label)}</td><td class="text-end">${d.age_months} m</td><td class="text-end">${fmt(x.denominator)}</td>
                <td class="text-end">${fmt(x.card)}</td><td class="text-end">${fmt(x.recall)}</td><td class="text-end fw-semibold">${pct(x.pct)}</td><td class="text-end">${pct(x.pct_card)}</td></tr>`;
        }).join('');
        // Doses absentes des données : retirées du sélecteur.
        [...(select?.options ?? [])].forEach((o) => { o.hidden = !data.collected.includes(o.value); });
        renderDose();
        renderLevel();
    });
    select?.addEventListener('change', renderDose);
    document.getElementById('coverage-level')?.addEventListener('change', renderLevel);
}
