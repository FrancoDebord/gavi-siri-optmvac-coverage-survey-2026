<?php

namespace App\Console\Commands;

use App\Models\Cluster;
use App\Models\ClusterFrame;
use App\Models\Collector;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\SubCluster;
use App\Models\SyncLog;
use App\Models\Team;
use App\Models\Village;
use App\Services\Import\SamplingFrameImporter;
use App\Services\Import\TeamVillageImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * État des données dont dépendent les tableaux de bord (référentiel, base de sondage, contours, villages des binômes,
 * comptes ODK, queries, file de traitement) : à lancer en local et sur le serveur pour comparer.
 */
class DiagnosticCommand extends Command
{
    protected $signature = 'gavi:diagnostic';

    protected $description = 'Affiche l\'état des données (référentiel, base de sondage, contours, binômes, queries) pour comparer deux installations';

    public function handle(): int
    {
        $settings = rtrim((string) config('gavi.settings_path'), '/\\');
        $file = fn (string $name) => is_file($settings.DIRECTORY_SEPARATOR.$name) ? 'présent' : 'ABSENT';
        $gpsDir = $settings.DIRECTORY_SEPARATOR.'points_gps';
        $gpsFiles = is_dir($gpsDir) ? count(glob($gpsDir.DIRECTORY_SEPARATOR.'*', GLOB_NOSORT) ?: []) : 0;
        $frames = ClusterFrame::count();
        $unmatched = ClusterFrame::get(['village_ids'])->filter(fn ($f) => empty($f->village_ids))->count();
        $revision = is_file(base_path('REVISION')) ? trim((string) file_get_contents(base_path('REVISION'))) : '—';

        $rows = [
            ['Application', 'Version (REVISION)', $revision],
            ['Application', 'Dernière migration', (string) DB::table('migrations')->orderByDesc('id')->value('migration')],
            ['Réglages', 'Dossier', $settings],
            ['Réglages', 'village.csv / binomes.csv', $file('village.csv').' / '.$file('binomes.csv')],
            ['Réglages', 'Codes villages ('.config('gavi.household_code.village_codes_file').')', $file((string) config('gavi.household_code.village_codes_file'))],
            ['Réglages', 'Fichiers de contours (points_gps)', is_dir($gpsDir) ? "{$gpsFiles} fichier(s)" : 'DOSSIER ABSENT'],
            ['Référentiel', 'Villages / avec code à 3 lettres', Village::count().' / '.Village::whereNotNull('short_code')->count()],
            ['Référentiel', 'Binômes / agents rattachés', Team::count().' / '.Collector::whereNotNull('team_id')->count()],
            ['Base de sondage', 'Grappes / non rattachées à un village', "{$frames} / {$unmatched}".(is_file(SamplingFrameImporter::path()) ? '' : ' (fichier CSV absent)')],
            ['Villages des binômes', 'Liens binôme-village ('.basename(TeamVillageImporter::path()).')', (string) DB::table('team_village')->count()],
            ['Grappes', 'Grappes / avec contour / sous-grappes', Cluster::count().' / '.Cluster::whereNotNull('boundary')->count().' / '.SubCluster::count()],
            ['Ménages', 'Total (base nettoyée) / avec grappe / avec GPS', Household::count().' / '.Household::whereNotNull('cluster_id')->count().' / '.Household::whereNotNull('latitude')->count()],
            ['Ménages', 'Avec compte ODK / dernière collecte', Household::whereNotNull('submitter')->count().' / '.(Household::max('interview_date') ?? '—')],
            ['Synchronisation', 'Dernière synchro réussie', $this->lastSync()],
            ['Queries', 'Total / non résolues / TEAM_VILLAGE', DataQuery::count().' / '.DataQuery::whereIn('status', DataQuery::UNRESOLVED)->count().' / '.DataQuery::where('rule_code', 'TEAM_VILLAGE')->count()],
            ['Queries', 'Dernier contrôle qualité', (string) (DB::table('quality_runs')->max('finished_at') ?? DB::table('quality_runs')->max('started_at') ?? '— (jamais lancé par la file)')],
            ['File de traitement', 'Tâches en attente / en échec', DB::table('jobs')->count().' / '.DB::table('failed_jobs')->count()],
        ];
        $this->table(['Domaine', 'Élément', 'Valeur'], $rows);

        return self::SUCCESS;
    }

    private function lastSync(): string
    {
        try {
            return (string) (SyncLog::where('status', 'success')->latest('id')->value('finished_at') ?? SyncLog::latest('id')->value('finished_at') ?? '—');
        } catch (Throwable) {
            return '—';
        }
    }
}
