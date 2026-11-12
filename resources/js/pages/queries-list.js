import { dataTable } from '../lib/datatable';
import { bindExportLinks, initFilterBar } from '../lib/filters';
import { escapeHtml } from '../lib/format';
import { postJson, toast } from '../lib/http';

/**
 * Liste détaillée des queries (DataTables côté serveur) avec actions rapides :
 * changement de statut (unitaire ou groupé) et commentaire.
 */
export default function queriesListPage() {
    const root = document.getElementById('queries-list');
    const table = document.getElementById('queries-table');
    const modalEl = document.getElementById('status-modal');
    const modal = modalEl ? new window.bootstrap.Modal(modalEl) : null;
    const canProcess = root.dataset.canProcess === '1';
    let current = {};
    let selection = new Set();

    const dt = dataTable(table, {
        serverSide: true,
        processing: true,
        order: [[10, 'desc']],
        ajax: (request, callback) => {
            const params = new URLSearchParams({
                draw: request.draw,
                start: request.start,
                length: request.length,
                search: request.search.value,
                order_column: request.columns[request.order[0]?.column]?.data ?? 'collection_date',
                order_dir: request.order[0]?.dir ?? 'desc',
                ...current,
            });
            fetch(`${root.dataset.url}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => r.json())
                .then(callback)
                .catch(() => callback({ draw: request.draw, data: [], recordsTotal: 0, recordsFiltered: 0 }));
        },
        columns: [
            { data: 'id', orderable: false, render: (id) => (canProcess ? `<input type="checkbox" class="form-check-input row-select" value="${id}" ${selection.has(String(id)) ? 'checked' : ''} aria-label="Sélectionner">` : '') },
            { data: 'code', className: 'text-nowrap fw-semibold', render: (v, t, row) => `<a href="${row.url}">${escapeHtml(v)}</a>` },
            {
                data: 'label',
                orderable: false,
                className: 'col-query-label',
                render: (v, t, row) => `<div class="fw-semibold">${escapeHtml(v)}</div>
                    <div class="text-truncate-2 small" title="${escapeHtml(row.message)}">${escapeHtml(row.message)}</div>
                    ${row.observed ? `<div class="small text-muted">Valeur : ${escapeHtml(row.observed)}</div>` : ''}
                    ${row.suggestion ? `<div class="small text-muted text-truncate-2" title="${escapeHtml(row.suggestion)}"><i class="bi bi-lightbulb me-1"></i>${escapeHtml(row.suggestion)}</div>` : ''}`,
            },
            { data: 'variable', render: (v, t, row) => `<code>${escapeHtml(v ?? '—')}</code>${row.instance ? `<div class="small text-muted">${escapeHtml(row.instance)}</div>` : ''}` },
            { data: 'household_code', render: (v, t, row) => `<a href="${row.household_url}">${escapeHtml(v ?? '—')}</a>` },
            { data: 'zone' },
            { data: 'commune' },
            { data: 'village' },
            { data: 'cluster' },
            { data: 'collector' },
            { data: 'collection_date', className: 'text-nowrap' },
            { data: 'detected_at', className: 'text-nowrap' },
            { data: 'severity', render: (v, t, row) => `<span class="badge badge-severity-${v}">${escapeHtml(row.severity_label)}</span>` },
            {
                data: 'status',
                render: (v, t, row) => `<span class="badge text-bg-${row.status_color}">${escapeHtml(row.resolution_label)}</span>
                    <div class="small text-muted">${escapeHtml(row.status_label)}${row.resolved_at ? ` · ${escapeHtml(row.resolved_at)}` : ''}</div>`,
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-end text-nowrap',
                render: (id, t, row) => `<a href="${row.url}" class="btn btn-sm btn-outline-secondary" title="Détail"><i class="bi bi-eye"></i></a>
                    ${canProcess ? `<button class="btn btn-sm btn-outline-primary ms-1 quick-status" data-id="${id}" data-status="${row.status}" title="Changer le statut / commenter"><i class="bi bi-pencil-square"></i></button>` : ''}`,
            },
        ],
    });

    const filters = initFilterBar(document.getElementById('filter-bar'), (values) => {
        current = values;
        selection = new Set();
        updateSelection();
        dt.ajax.reload();
    });
    filters.ready.then(() => {
        current = filters.values();
        dt.ajax.reload();
    });
    bindExportLinks(root, () => current);

    // Export : options du formulaire (format, regroupement) + filtres affichés.
    const exportForm = document.getElementById('export-form');
    exportForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const params = new URLSearchParams({ ...current, ...Object.fromEntries(new FormData(exportForm)) });
        [...params.keys()].forEach((key) => {
            if (params.get(key) === '') params.delete(key);
        });
        window.location.href = `${exportForm.action}?${params}`;
        window.bootstrap.Modal.getInstance(document.getElementById('export-modal'))?.hide();
    });
    document.getElementById('export-modal')?.addEventListener('show.bs.modal', () => {
        const total = dt.page.info()?.recordsDisplay;
        exportForm.querySelector('[data-export-count]').textContent = total !== undefined ? `${total} query(s) avec les filtres actuels` : '';
    });

    function updateSelection() {
        const bar = document.getElementById('bulk-bar');
        if (!bar) return;
        bar.classList.toggle('d-none', selection.size === 0);
        bar.querySelector('[data-count]').textContent = selection.size;
    }

    table.addEventListener('change', (event) => {
        if (event.target.classList.contains('row-select')) {
            event.target.checked ? selection.add(event.target.value) : selection.delete(event.target.value);
            updateSelection();
        }
    });
    document.getElementById('select-all')?.addEventListener('change', (event) => {
        table.querySelectorAll('.row-select').forEach((box) => {
            box.checked = event.target.checked;
            event.target.checked ? selection.add(box.value) : selection.delete(box.value);
        });
        updateSelection();
    });

    function openModal(ids, status) {
        const form = modalEl.querySelector('form');
        form.dataset.ids = JSON.stringify(ids);
        form.querySelector('[name="status"]').value = '';
        form.querySelector('[name="comment"]').value = '';
        form.querySelectorAll('[name="status"] option').forEach((opt) => {
            const allowed = opt.dataset.from ? JSON.parse(opt.dataset.from) : null;
            opt.disabled = Boolean(status && allowed && !allowed.includes(status));
        });
        modalEl.querySelector('[data-selection-count]').textContent = ids.length;
        modal.show();
    }

    table.addEventListener('click', (event) => {
        const button = event.target.closest('.quick-status');
        if (button) openModal([button.dataset.id], button.dataset.status);
    });
    document.getElementById('bulk-status')?.addEventListener('click', () => openModal([...selection], null));

    modalEl?.querySelector('form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const ids = JSON.parse(form.dataset.ids || '[]');
        const status = form.querySelector('[name="status"]').value;
        const comment = form.querySelector('[name="comment"]').value;
        if (!status && !comment.trim()) {
            toast('Choisissez un statut ou saisissez un commentaire.', 'warning');
            return;
        }
        try {
            const result = await postJson(root.dataset.bulkUrl, { ids, status, comment });
            toast(result.message);
            modal.hide();
            selection = new Set();
            updateSelection();
            dt.ajax.reload(null, false);
        } catch (error) {
            toast(error.message, 'danger');
        }
    });
}
