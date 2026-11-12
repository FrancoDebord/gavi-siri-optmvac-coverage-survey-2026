// Sidebar repliable (bureau) / tiroir (mobile), tooltips et confirmations.
import { initDialogs } from './dialog';

const STORAGE_KEY = 'gavi.sidebar.collapsed';

function storage(action, key, value) {
    try {
        return action === 'get' ? localStorage.getItem(key) : localStorage.setItem(key, value);
    } catch {
        return null;
    }
}

export function initLayout() {
    if (storage('get', STORAGE_KEY) === '1') {
        document.body.classList.add('sidebar-collapsed');
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            if (window.matchMedia('(min-width: 992px)').matches) {
                const collapsed = document.body.classList.toggle('sidebar-collapsed');
                storage('set', STORAGE_KEY, collapsed ? '1' : '0');
            } else {
                document.body.classList.toggle('sidebar-open');
            }
        });
    });
    document.querySelector('.sidebar-backdrop')?.addEventListener('click', () => document.body.classList.remove('sidebar-open'));

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new window.bootstrap.Tooltip(el));

    // Confirmations (data-confirm) et messages flash en boîtes de dialogue.
    initDialogs();
}
