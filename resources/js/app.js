import './bootstrap';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'leaflet/dist/leaflet.css';
import '../css/app.css';

import { initLayout } from './lib/layout';
import { initSearchableSelects } from './lib/searchable-select';
import progressPage from './pages/progress';
import queriesDashboardPage from './pages/queries-dashboard';
import queriesListPage from './pages/queries-list';
import queryShowPage from './pages/query-show';
import dictionaryPage from './pages/dictionary';
import importsPage from './pages/imports';
import sourcesPage from './pages/sources';
import rulesPage from './pages/rules';
import clusterPage from './pages/cluster';
import supervisionPage from './pages/supervision';
import investigatorPage from './pages/investigator';
import teamPage from './pages/team';
import householdPage from './pages/household';
import submissionsPage from './pages/submissions';
import clusterMapPage from './pages/cluster-map';

window.bootstrap = bootstrap;

const pages = {
    progress: progressPage,
    'queries-dashboard': queriesDashboardPage,
    'queries-list': queriesListPage,
    'query-show': queryShowPage,
    dictionary: dictionaryPage,
    imports: importsPage,
    sources: sourcesPage,
    rules: rulesPage,
    cluster: clusterPage,
    supervision: supervisionPage,
    investigator: investigatorPage,
    team: teamPage,
    household: householdPage,
    submissions: submissionsPage,
    'cluster-map': clusterMapPage,
};

document.addEventListener('DOMContentLoaded', () => {
    initLayout();
    initSearchableSelects();
    const page = document.body.dataset.page;
    if (page && pages[page]) {
        pages[page]();
    }
});
