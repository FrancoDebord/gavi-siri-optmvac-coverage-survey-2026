<?php

namespace App\Console\Commands;

use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\Household;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Import\SubmissionProcessor;
use App\Support\DashboardCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildClustersCommand extends Command
{
    protected $signature = 'gavi:rebuild-clusters';

    protected $description = 'Réaffecte grappes et sous-grappes des ménages déjà importés d\'après leur identifiant (ZONE-VIL-NN[-Sx]-MMM) et supprime les grappes devenues vides';

    public function handle(): int
    {
        $processor = new SubmissionProcessor;
        $clusterVar = Map::var('grappe');
        $subClusterVar = Map::var('sous_grappe');
        $changed = 0;

        DB::transaction(function () use ($processor, $clusterVar, $subClusterVar, &$changed) {
            foreach (Household::lazyById(200) as $household) {
                $data = $household->data ?? [];
                $household->fill([
                    'cluster_id' => $processor->resolveCluster($household->household_code, $clusterVar ? ($data[$clusterVar] ?? null) : null,
                        $household->village_id, $household->commune_id, $household->health_zone_id),
                    'sub_cluster' => SubmissionProcessor::subCluster($household->household_code, $subClusterVar ? ($data[$subClusterVar] ?? null) : null),
                ]);
                if ($household->isDirty()) {
                    $household->saveQuietly();
                    DataQuery::withoutGlobalScope('kind')->where('household_id', $household->id)->update(['cluster_id' => $household->cluster_id]);
                    $changed++;
                }
            }
        });

        // Grappes sans ménage, cible, contour ni query : anciennes grappes « une par village ».
        $removed = Cluster::whereDoesntHave('households')->whereNull('target_households')->whereNull('boundary')
            ->whereNotIn('id', DataQuery::withoutGlobalScope('kind')->whereNotNull('cluster_id')->select('cluster_id'))
            ->delete();

        DashboardCache::bump();
        $this->info("{$changed} ménage(s) réaffecté(s), {$removed} grappe(s) vide(s) supprimée(s).");

        return self::SUCCESS;
    }
}
