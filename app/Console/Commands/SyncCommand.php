<?php

namespace App\Console\Commands;

use App\Jobs\RunQualityChecksJob;
use App\Models\DataSource;
use App\Services\Import\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'gavi:sync
        {source? : ID de la source (toutes les sources actives par défaut)}
        {--full : Ignore la date de dernière synchro et récupère toutes les soumissions}
        {--no-checks : Ne pas relancer le moteur de queries}';

    protected $description = 'Synchronise les soumissions depuis ODK Central / KoboToolbox';

    public function handle(SyncService $sync): int
    {
        $sources = $this->argument('source')
            ? DataSource::whereKey($this->argument('source'))->get()
            : DataSource::where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->warn('Aucune source à synchroniser.');

            return self::SUCCESS;
        }

        $changed = 0;
        foreach ($sources as $source) {
            $this->line("→ {$source->name} ({$source->typeLabel()})");
            $log = $sync->sync($source, 'manual', null, (bool) $this->option('full'));
            if ($log->status === 'success') {
                $this->info("  {$log->fetched_count} récupérée(s), {$log->new_count} nouvelle(s), {$log->updated_count} mise(s) à jour en {$log->duration_ms} ms.");
                $changed += $log->new_count + $log->updated_count;
            } else {
                $this->error('  Échec : '.$log->error);
            }
        }

        if ($changed > 0 && ! $this->option('no-checks')) {
            RunQualityChecksJob::dispatchSync('sync');
            $this->info('Moteur de queries exécuté.');
        }

        return self::SUCCESS;
    }
}
