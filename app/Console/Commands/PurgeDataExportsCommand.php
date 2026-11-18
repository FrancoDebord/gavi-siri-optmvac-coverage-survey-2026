<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;

/** Supprime les fichiers d'export expirés ; l'enregistrement (auteur, empreinte, manifeste) est conservé. */
class PurgeDataExportsCommand extends Command
{
    protected $signature = 'gavi:purge-exports';

    protected $description = 'Supprime les fichiers d\'export expirés (conservation de '.DataExport::RETENTION_DAYS.' jours) en gardant leur trace';

    public function handle(): int
    {
        $expired = DataExport::where('status', DataExport::STATUS_DONE)->where('expires_at', '<', now())->get();
        foreach ($expired as $export) {
            if ($export->file_path && is_file($export->file_path)) {
                @unlink($export->file_path);
            }
            $export->update(['status' => DataExport::STATUS_EXPIRED]);
        }
        $this->info(count($expired).' export(s) expiré(s) supprimé(s).');

        return self::SUCCESS;
    }
}
