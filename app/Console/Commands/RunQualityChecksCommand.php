<?php

namespace App\Console\Commands;

use App\Models\QualityRule;
use App\Services\Quality\QualityEngine;
use Illuminate\Console\Command;

class RunQualityChecksCommand extends Command
{
    protected $signature = 'gavi:run-quality-checks
        {--regles= : codes des règles à exécuter, séparés par des virgules (ex. ROSTER_MEMBERS,ROSTER_CAREGIVERS) ; toutes par défaut}';

    protected $description = 'Exécute les règles de contrôle qualité et met à jour les queries';

    public function handle(QualityEngine $engine): int
    {
        $codes = $this->option('regles') ? array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) $this->option('regles'))))) : null;
        if ($codes !== null) {
            $known = QualityRule::whereIn('code', $codes)->where('is_active', true)->pluck('code')->all();
            if ($unknown = array_diff($codes, $known)) {
                $this->error('Règle(s) inconnue(s) ou inactive(s) : '.implode(', ', $unknown).'. Lancer d\'abord « php artisan migrate » si elles sont nouvelles.');

                return self::FAILURE;
            }
            $this->info('Règles exécutées : '.implode(', ', $codes));
        }
        $this->info('Exécution du moteur de queries…');
        $run = $engine->run('console', null, null, $codes);

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
