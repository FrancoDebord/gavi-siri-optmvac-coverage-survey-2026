<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClusterController;
use App\Http\Controllers\ClusterMapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataCorrectionController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DataLockController;
use App\Http\Controllers\DataQueryController;
use App\Http\Controllers\DataSourceController;
use App\Http\Controllers\DictionaryController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\FieldDecisionController;
use App\Http\Controllers\FieldIncidentController;
use App\Http\Controllers\FilterOptionsController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvestigatorController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QualityRuleController;
use App\Http\Controllers\QueryDashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\SupervisionController;
use App\Http\Controllers\SupervisionObservationController;
use App\Http\Controllers\TeamMessageController;
use App\Http\Controllers\TeamSpaceController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');

    // Documentation (README et docs/*.md), filtrée par permission dans le contrôleur.
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation.index');
    Route::get('/documentation/{page}', [DocumentationController::class, 'show'])->where('page', '[a-z0-9\-]+')->name('documentation.show');

    Route::middleware('permission:view dashboards')->group(function () {
        // Dashboard de progression
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/donnees', [DashboardController::class, 'data'])->name('dashboard.data');
        Route::get('/dashboard/export/{format}', [DashboardController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->name('dashboard.export');
        Route::get('/filtres/options', FilterOptionsController::class)->name('filters.options');

        // Queries
        Route::get('/queries/dashboard', [QueryDashboardController::class, 'index'])->name('queries.dashboard');
        Route::get('/queries/dashboard/donnees', [QueryDashboardController::class, 'data'])->name('queries.dashboard.data');
        Route::get('/queries', [DataQueryController::class, 'index'])->name('queries.index');
        Route::get('/queries/tableau', [DataQueryController::class, 'datatable'])->name('queries.datatable');
        Route::get('/observations', [SupervisionObservationController::class, 'index'])->name('observations.index');
        Route::get('/corrections', [DataCorrectionController::class, 'index'])->middleware('permission:validate queries|view pi dashboard')->name('corrections.index');
        Route::post('/corrections/accepter', [DataCorrectionController::class, 'acceptAll'])->middleware('permission:validate queries')->name('corrections.accept-all');
        Route::post('/corrections/{correction}/accepter', [DataCorrectionController::class, 'accept'])->middleware('permission:validate queries')->name('corrections.accept');
        Route::post('/corrections/{correction}/refuser', [DataCorrectionController::class, 'refuse'])->middleware(['permission:validate queries', 'throttle:60,1'])->name('corrections.refuse');
        Route::get('/corrections/export', [DataCorrectionController::class, 'export'])->middleware(['permission:validate queries|view pi dashboard', 'throttle:20,1'])->name('corrections.export');

        // Espace superviseur et incidents de terrain
        Route::get('/superviseur', [SupervisionController::class, 'index'])->name('supervision.index');
        Route::get('/incidents', [FieldIncidentController::class, 'index'])->name('incidents.index');
        Route::get('/incidents/{incident}', [FieldIncidentController::class, 'show'])->whereNumber('incident')->name('incidents.show');

        // Exploration des soumissions (ménages) sur les contours des grappes
        Route::get('/soumissions', [SubmissionController::class, 'index'])->name('submissions.index');

        // Décisions de terrain (consultation)
        Route::get('/decisions', [FieldDecisionController::class, 'index'])->name('decisions.index');
        Route::get('/decisions/{decision}', [FieldDecisionController::class, 'show'])->whereNumber('decision')->name('decisions.show');

        // Messages de l'investigateur principal aux équipes
        Route::get('/messages', [TeamMessageController::class, 'index'])->name('messages.index');
    });

    // Lecture des messages (superviseurs et binômes : limitée aux messages qui leur sont visibles).
    Route::post('/messages/lus', [TeamMessageController::class, 'markRead'])->name('messages.read-all');
    Route::post('/messages/{message}/lu', [TeamMessageController::class, 'markRead'])->name('messages.read');

    // Fiches détaillées : équipe centrale et superviseurs (périmètre), comptes binômes (leurs propres données).
    Route::middleware('permission:view dashboards|view team space')->group(function () {
        Route::get('/queries/{query}', [DataQueryController::class, 'show'])->whereNumber('query')->name('queries.show');
        Route::get('/menages/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::get('/menages/{household}/photos/{filename}', [HouseholdController::class, 'photo'])
            ->where('filename', '[A-Za-z0-9_.\-]+')->middleware('throttle:120,1')->name('households.photo');
        Route::get('/grappes/{cluster}', [ClusterController::class, 'show'])->name('clusters.show');
        // Carte des grappes par village (chacun selon son niveau)
        Route::get('/carte-grappes', [ClusterMapController::class, 'index'])->name('maps.clusters');
    });

    // Espace binôme (compte binôme ; l'équipe centrale et les superviseurs peuvent l'ouvrir pour un binôme)
    Route::get('/mon-binome', [TeamSpaceController::class, 'show'])->name('team.space');
    Route::get('/mon-binome/soumissions', [TeamSpaceController::class, 'submissions'])->name('team.submissions');
    Route::get('/mon-binome/queries', [TeamSpaceController::class, 'queries'])->name('team.queries');
    Route::get('/mon-binome/grappes', [TeamSpaceController::class, 'clusters'])->name('team.clusters');
    Route::get('/mon-binome/grappes.pdf', [TeamSpaceController::class, 'clustersPdf'])->name('team.clusters.pdf')->middleware('throttle:10,1');

    // Investigateur principal
    Route::middleware('permission:view pi dashboard')->group(function () {
        Route::get('/investigateur', [InvestigatorController::class, 'index'])->name('investigator.index');
        Route::get('/investigateur/progression', [InvestigatorController::class, 'progress'])->name('investigator.progress');
        Route::get('/investigateur/synthese', [InvestigatorController::class, 'summary'])->name('investigator.summary');
        Route::get('/investigateur/synthese.pdf', [InvestigatorController::class, 'summary'])->name('investigator.summary.pdf')->middleware('throttle:20,1');
        Route::get('/investigateur/grappes', [InvestigatorController::class, 'clusters'])->name('investigator.clusters');
        Route::get('/investigateur/grappes.pdf', [InvestigatorController::class, 'clusters'])->name('investigator.clusters.pdf')->middleware('throttle:20,1');
        Route::get('/investigateur/grappes.xlsx', [InvestigatorController::class, 'clusters'])->name('investigator.clusters.xlsx')->middleware('throttle:20,1');
        Route::get('/investigateur/rapport', [InvestigatorController::class, 'report'])->name('investigator.report')->middleware('throttle:20,1');
    });
    // Décisions de terrain (saisie)
    Route::middleware('permission:manage decisions')->group(function () {
        Route::get('/decisions/nouvelle', [FieldDecisionController::class, 'create'])->name('decisions.create');
        Route::post('/decisions', [FieldDecisionController::class, 'store'])->name('decisions.store')->middleware('throttle:30,1');
        Route::get('/decisions/{decision}/modifier', [FieldDecisionController::class, 'edit'])->whereNumber('decision')->name('decisions.edit');
        Route::put('/decisions/{decision}', [FieldDecisionController::class, 'update'])->whereNumber('decision')->name('decisions.update');
        Route::post('/decisions/{decision}/rapport-synthese', [FieldDecisionController::class, 'toggleSummary'])->whereNumber('decision')->name('decisions.summary.toggle');
        Route::post('/decisions/categories', [FieldDecisionController::class, 'storeCategory'])->name('decisions.categories.store');
        Route::post('/decisions/categories/{category}/activation', [FieldDecisionController::class, 'toggleCategory'])->name('decisions.categories.toggle');
    });
    Route::delete('/decisions/{decision}', [FieldDecisionController::class, 'destroy'])->whereNumber('decision')
        ->middleware('role:'.User::ROLE_ADMIN)->name('decisions.destroy');
    Route::get('/decisions/export.xlsx', [FieldDecisionController::class, 'export'])->middleware('permission:export data')->name('decisions.export');

    Route::post('/messages', [TeamMessageController::class, 'store'])->middleware(['permission:send messages', 'throttle:30,1'])->name('messages.store');

    Route::middleware('permission:export data')->group(function () {
        Route::get('/queries/export', [QueryDashboardController::class, 'export'])->name('queries.export');
        Route::get('/queries/export/binomes', [QueryDashboardController::class, 'exportByCollector'])->name('queries.export.collectors');
        Route::get('/queries/rapport-quotidien', [QueryDashboardController::class, 'dailyReport'])->name('queries.report.daily');
    });

    // Traitement des queries : droits vérifiés par la politique (assigné, équipe de traitement, data manager).
    Route::post('/queries/{query}/statut', [DataQueryController::class, 'updateStatus'])->whereNumber('query')->name('queries.status')->middleware('throttle:60,1');
    Route::post('/queries/{query}/commentaire', [DataQueryController::class, 'comment'])->whereNumber('query')->name('queries.comment')->middleware('throttle:60,1');
    Route::post('/queries/{query}/suggestion', [DataQueryController::class, 'suggest'])->whereNumber('query')->name('queries.suggest')->middleware('throttle:60,1');
    Route::post('/queries/{query}/affectation', [DataQueryController::class, 'assign'])->whereNumber('query')->name('queries.assign')->middleware('throttle:60,1');
    Route::post('/queries/lot', [DataQueryController::class, 'bulk'])->name('queries.bulk')->middleware('throttle:30,1');
    Route::post('/queries/lot/affectation', [DataQueryController::class, 'bulkAssign'])->name('queries.bulk-assign')->middleware(['permission:assign queries', 'throttle:30,1']);

    Route::middleware('permission:report incidents')->group(function () {
        Route::get('/incidents/nouveau', [FieldIncidentController::class, 'create'])->name('incidents.create');
        Route::post('/incidents', [FieldIncidentController::class, 'store'])->name('incidents.store')->middleware('throttle:30,1');
        Route::post('/incidents/{incident}/suivi', [FieldIncidentController::class, 'update'])->name('incidents.update')->middleware('throttle:60,1');
    });

    Route::post('/queries/executer', [DataQueryController::class, 'run'])->middleware(['permission:run quality checks', 'throttle:6,1'])->name('queries.run');

    // Imports
    Route::middleware('permission:import data')->prefix('imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/donnees', [ImportController::class, 'storeData'])->name('data');
        Route::post('/grappes', [ImportController::class, 'storeClusters'])->name('clusters');
        Route::post('/referentiel', [ImportController::class, 'reloadReferences'])->name('references');
        Route::post('/contours', [ImportController::class, 'importBoundaries'])->name('boundaries')->middleware('throttle:10,1');
        Route::get('/contours/compilation.csv', [ImportController::class, 'boundaryCompilation'])->name('boundaries.compilation');
        Route::post('/contours/depot', [ImportController::class, 'uploadBoundaries'])->name('boundaries.upload')->middleware('throttle:20,1');
        Route::get('/contours/progression/{token}', [ImportController::class, 'boundaryProgress'])->whereUuid('token')->name('boundaries.progress');
        Route::delete('/contours/{file}', [ImportController::class, 'destroyBoundary'])->where('file', '[A-Za-z0-9_\-() ]+\.csv')->name('boundaries.destroy');
        Route::get('/contours/contours.geojson', [ImportController::class, 'boundaryGeoJson'])->name('boundaries.geojson');
        Route::get('/contours/export.zip', [ImportController::class, 'boundaryArchive'])->name('boundaries.archive');
        Route::get('/modele-grappes.csv', [ImportController::class, 'clusterTemplate'])->name('clusters.template');
        Route::get('/{batch}/statut', [ImportController::class, 'status'])->name('status');
    });

    // Data dictionary et mapping
    Route::middleware('permission:manage dictionary')->group(function () {
        Route::get('/dictionnaire', [DictionaryController::class, 'index'])->name('dictionary.index');
        Route::post('/dictionnaire', [DictionaryController::class, 'upload'])->name('dictionary.upload');
        Route::post('/dictionnaire/{formVersion}/activer', [DictionaryController::class, 'activate'])->name('dictionary.activate');
        Route::get('/parametres/mapping', [MappingController::class, 'edit'])->name('mapping.edit');
        Route::put('/parametres/mapping', [MappingController::class, 'update'])->name('mapping.update');
    });

    // Règles qualité
    Route::middleware('permission:manage rules')->prefix('parametres/regles')->name('rules.')->group(function () {
        Route::get('/', [QualityRuleController::class, 'index'])->name('index');
        Route::get('/nouvelle', [QualityRuleController::class, 'create'])->name('create');
        Route::post('/', [QualityRuleController::class, 'store'])->name('store');
        Route::get('/{rule}/modifier', [QualityRuleController::class, 'edit'])->name('edit');
        Route::put('/{rule}', [QualityRuleController::class, 'update'])->name('update');
        Route::delete('/{rule}', [QualityRuleController::class, 'destroy'])->name('destroy');
        Route::post('/{rule}/activation', [QualityRuleController::class, 'toggle'])->name('toggle');
        Route::post('/tester-expression', [QualityRuleController::class, 'testExpression'])->name('test')->middleware('throttle:20,1');
    });

    // Sources de données (administrateur)
    Route::middleware('permission:manage sources')->prefix('parametres/sources')->name('sources.')->group(function () {
        Route::get('/', [DataSourceController::class, 'index'])->name('index');
        Route::get('/nouvelle', [DataSourceController::class, 'create'])->name('create');
        Route::post('/', [DataSourceController::class, 'store'])->name('store');
        Route::get('/{source}', [DataSourceController::class, 'show'])->name('show');
        Route::get('/{source}/modifier', [DataSourceController::class, 'edit'])->name('edit');
        Route::put('/{source}', [DataSourceController::class, 'update'])->name('update');
        Route::delete('/{source}', [DataSourceController::class, 'destroy'])->name('destroy');
        Route::post('/{source}/tester', [DataSourceController::class, 'test'])->name('test')->middleware('throttle:10,1');
        Route::post('/{source}/synchroniser', [DataSourceController::class, 'sync'])->name('sync')->middleware('throttle:10,1');
        Route::post('/{source}/dictionnaire', [DataSourceController::class, 'dictionary'])->name('dictionary')->middleware('throttle:5,1');
    });

    // Utilisateurs et audit
    Route::middleware('permission:manage users')->prefix('parametres/utilisateurs')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/nouveau', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/modifier', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/comptes-binomes', [UserController::class, 'teamAccounts'])->name('team-accounts');
    });
    Route::get('/parametres/audit', [AuditLogController::class, 'index'])->middleware('permission:view audit')->name('audit.index');
    Route::get('/parametres/audit/export', [AuditLogController::class, 'export'])->middleware(['permission:view audit', 'throttle:10,1'])->name('audit.export');
    Route::middleware('permission:export databases')->group(function () {
        Route::get('/parametres/exports', [DataExportController::class, 'index'])->name('exports.index');
        Route::post('/parametres/exports', [DataExportController::class, 'store'])->name('exports.store')->middleware('throttle:10,1');
        Route::get('/parametres/exports/{export}/telecharger', [DataExportController::class, 'download'])->name('exports.download')->middleware('throttle:30,1');
    });
    Route::middleware('permission:manage settings')->group(function () {
        Route::get('/parametres/gel', [DataLockController::class, 'index'])->name('locks.index');
        Route::post('/parametres/gel', [DataLockController::class, 'store'])->name('locks.store')->middleware('throttle:10,1');
        Route::get('/parametres/application', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/parametres/application', [SettingsController::class, 'update'])->name('settings.update');
    });
});
