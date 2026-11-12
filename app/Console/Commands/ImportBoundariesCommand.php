<?php

namespace App\Console\Commands;

use App\Services\Import\ClusterBoundaryImporter;
use Illuminate\Console\Command;

class ImportBoundariesCommand extends Command
{
    protected $signature = 'gavi:import-boundaries';

    protected $description = 'Importe les contours GPS des grappes et sous-grappes (settings/points_gps) et réécrit la compilation des points';

    public function handle(ClusterBoundaryImporter $importer): int
    {
        $report = $importer->run();

        $this->table(['Fichier', 'Grappe', 'Sous-grappe', 'Points', 'Hameaux', 'Superficie (ha)', 'Statut', 'Remarques'], collect($report['files'])->map(fn ($f) => [
            $f['file'], $f['cluster_code'] ?? '—', $f['sub_cluster'] ?? '—', $f['points_count'] ?? '—', $f['hamlets'] ?: '—',
            $f['area_ha'] ?? '—', $f['status'], implode(' ', array_filter([$f['error'], ...$f['warnings']])),
        ])->all());
        $this->info("{$report['clusters']} contour(s) de grappe et {$report['sub_clusters']} de sous-grappe appliqués ; {$report['points']} point(s) compilés dans {$report['compilation']}.");

        return self::SUCCESS;
    }
}
