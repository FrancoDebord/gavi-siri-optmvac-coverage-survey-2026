import { renderChart } from '../lib/charts';
import { bindExportLinks, initFilterBar } from '../lib/filters';
import { AIRID, escapeHtml, fmt, pct } from '../lib/format';
import { getJson } from '../lib/http';

const SEVERITY_COLORS = { critique: AIRID.red, majeure: AIRID.warning, mineure: AIRID.grey };
const RANKING_FILTER = { zone: 'health_zone_id', commune: 'commune_id', village: 'village_id', cluster: 'cluster_id', team: 'team_id', collector: 'collector_id' };

function kpis(k) {
    const values = {
        total: fmt(k.total),
        open: fmt(k.open),
        resolved: fmt(k.resolved),
        critical_open: fmt(k.critical_open),
        per_100: k.per_100_households === null ? '—' : fmt(k.per_100_households),
        resolution_rate: pct(k.resolution_rate),
        avg_resolution: k.avg_resolution_hours === null ? '—' : (k.avg_resolution_hours >= 48 ? `${fmt(Math.round(k.avg_resolution_hours / 24 * 10) / 10)} j` : `${fmt(k.avg_resolution_hours)} h`),
        auto_closed: fmt(k.auto_closed),
        unresolvable: fmt(k.unresolvable),
    };
    Object.entries(values).forEach(([key, value]) => {
        document.querySelectorAll(`[data-kpi="${key}"]`).forEach((el) => {
            el.textContent = value;
        });
    });
}

function charts(data) {
    renderChart(document.getElementById('chart-severity'), {
        type: 'bar',
        data: {
            labels: data.by_severity.map((s) => s.label),
            datasets: [
                { label: 'Non résolues', data: data.by_severity.map((s) => s.open), backgroundColor: data.by_severity.map((s) => SEVERITY_COLORS[s.key]) },
                { label: 'Résolues', data: data.by_severity.map((s) => s.resolved), backgroundColor: data.by_severity.map((s) => `${SEVERITY_COLORS[s.key]}55`) },
            ],
        },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });

    renderChart(document.getElementById('chart-status'), {
        type: 'doughnut',
        data: {
            labels: data.by_status.map((s) => s.label),
            datasets: [{ data: data.by_status.map((s) => s.n), backgroundColor: [AIRID.red, AIRID.warning, AIRID.greyLight, AIRID.black, AIRID.success, AIRID.redLight] }],
        },
        options: { plugins: { legend: { position: 'right' } }, cutout: '60%' },
    });

    renderChart(document.getElementById('chart-rules'), {
        type: 'bar',
        data: {
            labels: data.top_rules.map((r) => r.code),
            datasets: [
                { label: 'Non résolues', data: data.top_rules.map((r) => r.open), backgroundColor: AIRID.red },
                { label: 'Résolues', data: data.top_rules.map((r) => r.n - r.open), backgroundColor: AIRID.success },
            ],
        },
        options: {
            indexAxis: 'y',
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { tooltip: { callbacks: { title: (items) => data.top_rules[items[0].dataIndex].name } } },
        },
    });

    renderChart(document.getElementById('chart-variables'), {
        type: 'bar',
        data: {
            labels: data.by_variable.map((v) => v.variable),
            datasets: [{ label: 'Queries', data: data.by_variable.map((v) => v.n), backgroundColor: AIRID.black }],
        },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } },
    });
}

function rankings(data, listUrl, filters) {
    Object.entries(data.rankings).forEach(([level, rows]) => {
        const tbody = document.querySelector(`#ranking-${level} tbody`);
        if (!tbody) return;
        tbody.innerHTML = rows.length ? rows.map((r) => {
            const params = new URLSearchParams({ ...filters, [RANKING_FILTER[level]]: r.id });
            return `<tr>
                <td><a href="${listUrl}?${params}">${escapeHtml(r.name)}</a></td>
                <td class="text-end">${fmt(r.total)}</td>
                <td class="text-end">${r.open ? `<span class="badge text-bg-danger">${fmt(r.open)}</span>` : '0'}</td>
                <td class="text-end">${r.critical ? `<span class="badge badge-severity-critique">${fmt(r.critical)}</span>` : '0'}</td>
                <td class="text-end">${fmt(r.households)}</td>
                <td class="text-end">${r.rate === null ? '—' : fmt(r.rate)}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="6" class="text-center text-muted">Aucune query</td></tr>';
    });
}

function heatmap(data) {
    const table = document.getElementById('heatmap');
    if (!table) return;
    const { days, rows, max } = data.heatmap;
    if (!rows.length) {
        table.innerHTML = '<tbody><tr><td class="text-muted">Aucune query sur la période</td></tr></tbody>';
        return;
    }
    const cell = (n) => {
        if (!n) return '<td class="text-muted">·</td>';
        const alpha = 0.15 + 0.85 * (n / Math.max(1, max));
        const dark = alpha > 0.55;
        return `<td style="background:rgba(193,2,2,${alpha.toFixed(2)});${dark ? 'color:#fff;' : ''}">${n}</td>`;
    };
    table.innerHTML = `<thead><tr><th>Binôme</th>${days.map((d) => `<th>${d}</th>`).join('')}<th class="text-end">Total</th></tr></thead>
        <tbody>${rows.map((r) => `<tr><th class="text-nowrap">${escapeHtml(r.collector)}</th>${r.values.map(cell).join('')}<td class="text-end fw-semibold">${r.values.reduce((a, b) => a + b, 0)}</td></tr>`).join('')}</tbody>`;
}

export default function queriesDashboardPage() {
    const root = document.getElementById('queries-dashboard');
    let current = {};

    async function load() {
        const data = await getJson(root.dataset.url, current);
        kpis(data.kpis);
        charts(data);
        rankings(data, root.dataset.listUrl, current);
        heatmap(data);
        document.querySelectorAll('[data-generated-at]').forEach((el) => {
            el.textContent = data.generated_at;
        });
    }

    const filters = initFilterBar(document.getElementById('filter-bar'), (values) => {
        current = values;
        load();
    });
    bindExportLinks(root, () => current);
    filters.ready.then(() => {
        current = filters.values();
        load();
    });

    const refresh = Number(root.dataset.refresh || 0);
    if (refresh > 0) {
        setInterval(() => {
            if (!document.hidden) load();
        }, refresh * 1000);
    }
}
