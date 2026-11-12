<?php

use App\Jobs\DispatchDueSyncsJob;
use App\Jobs\RunQualityChecksJob;
use Illuminate\Support\Facades\Schedule;

// Synchronisation automatique : chaque minute, les sources dont l'intervalle est écoulé sont mises en file.
Schedule::job(new DispatchDueSyncsJob)->everyMinute()->withoutOverlapping();

// Passage complet du moteur chaque nuit (règles dépendant de la date du jour, seuils modifiés…).
Schedule::job(new RunQualityChecksJob('schedule'))->dailyAt('02:00');

// Contours GPS des grappes : nouveaux fichiers du dossier settings/points_gps et compilation des points.
Schedule::command('gavi:import-boundaries')->everyTenMinutes()->withoutOverlapping();

// Nettoyage des jobs échoués anciens.
Schedule::command('queue:prune-failed --hours=168')->daily();
