<?php

namespace App\Console\Commands;

use App\Services\Quality\QualityEngine;
use Illuminate\Console\Command;

class RunQualityChecksCommand extends Command
{
    protected $signature = 'gavi:run-quality-checks';

    protected $description = 'Exécute les règles de contrôle qualité et met à jour les queries';

    public function handle(QualityEngine $engine): int
    {
        $this->info('Exécution du moteur de queries…');
        $run = $engine->run('console');

        if ($run->status !== 'success') {
            $this->error('Échec : '.$run->error);

            return self::FAILURE;
        }

        $this->table(['Ménages contrôlés', 'Queries ouvertes', 'Fermées automatiquement', 'Durée'], [[
            $run->households_checked, $run->opened_count, $run->auto_closed_count,
            $run->started_at->diffForHumans($run->finished_at, true),
        ]]);
        if ($run->error) {
            $this->warn('Règles en erreur : '.$run->error);
        }

        return self::SUCCESS;
    }
}
