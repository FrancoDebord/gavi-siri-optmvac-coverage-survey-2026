<?php

namespace App\Console\Commands;

use App\Services\Import\SamplingFrameImporter;
use Illuminate\Console\Command;

/** Recharge la base de sondage (grappes, ménages dénombrés, subdivisions) et liste les villages non rattachés. */
class ImportSamplingFrameCommand extends Command
{
    protected $signature = 'gavi:import-sampling-frame {path? : CSV zone,arrondissement,village,grappe,menages_denombres,subdivisions}';

    protected $description = 'Charge la base de sondage des grappes (ménages dénombrés, subdivisions) et la rattache aux villages';

    public function handle(SamplingFrameImporter $importer): int
    {
        $report = $importer->import($this->argument('path'));
        $this->info("{$report['clusters']} grappe(s) de {$report['villages']} village(s) chargées.");
        foreach ($report['unmatched'] as $village) {
            $this->warn("Village non rattaché : {$village}");
        }

        return self::SUCCESS;
    }
}
