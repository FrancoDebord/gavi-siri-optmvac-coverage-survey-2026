import './bootstrap';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'leaflet/dist/leaflet.css';
import '../css/app.css';

import { initChartImages } from './lib/charts';
import { initLayout } from './lib/layout';
import { initDocumentViewer } from './lib/document-viewer';
import { initLightbox } from './lib/lightbox';
import { initLiveFilters } from './lib/live-filter';
import { initPasswordFields } from './lib/password-fields';
import { initSearchableSelects } from './lib/searchable-select';
import { initSessionRefresh } from './lib/session';
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
import userFormPage from './pages/user-form';
import vapCoveragePage from './pages/vap-coverage';
import vaccineCoveragePage from './pages/vaccine-coverage';
import smcCoveragePage from './pages/smc-coverage';
import piResponsePage from './pages/pi-response';
import photoSpacePage from './pages/photo-space';
import photoProjectsPage from './pages/photo-projects';

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
    'user-form': userFormPage,
    'vap-coverage': vapCoveragePage,
    'vaccine-coverage': vaccineCoveragePage,
    'smc-coverage': smcCoveragePage,
    'pi-response': piResponsePage,
    'photo-space': photoSpacePage,
    'photo-projects': photoProjectsPage,
};

document.addEventListener('DOMContentLoaded', () => {
    initLayout();
    initSessionRefresh();
    initSearchableSelects();
    initLiveFilters();
    initPasswordFields();
    initLightbox();
    initDocumentViewer();
    initChartImages();
    const page = document.body.dataset.page;
    if (page && pages[page]) {
        pages[page]();
    }
});
