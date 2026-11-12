<?php

namespace App\Console\Commands;

use App\Services\Dictionary\DictionaryLoader;
use App\Services\Dictionary\VariableMap;
use App\Services\Import\ReferenceDataLoader;
use Illuminate\Console\Command;

class LoadDictionaryCommand extends Command
{
    protected $signature = 'gavi:load-dictionary
        {path? : Chemin du XLSForm (.xls/.xlsx). Par défaut : le fichier configuré dans config/gavi.php}
        {--no-references : Ne pas charger le référentiel géographique (l1/l2/l3/village/fieldworkers)}';

    protected $description = 'Charge le data dictionary (XLSForm), le mapping par défaut et le référentiel géographique';

    public function handle(DictionaryLoader $loader, ReferenceDataLoader $references): int
    {
        $path = $this->argument('path')
            ?? rtrim(config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR.config('gavi.default_dictionary');

        if (! is_file($path)) {
            $this->error("Fichier introuvable : {$path}");

            return self::FAILURE;
        }

        $formVersion = $loader->loadFile($path, 'default');
        $this->info("Dictionnaire {$formVersion->form_id} v{$formVersion->version} chargé : {$formVersion->variables_count} variables.");

        VariableMap::seedDefaults();
        $this->info('Mapping des variables initialisé.');

        if (! $this->option('no-references')) {
            $counts = $references->load();
            $this->table(['Niveau', 'Éléments'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values());
        }

        return self::SUCCESS;
    }
}
