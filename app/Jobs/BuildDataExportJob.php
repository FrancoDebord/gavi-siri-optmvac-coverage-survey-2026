<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Services\Export\ExportPackager;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Génère un export en arrière-plan. La charge utile est chiffrée dans la file d'attente
 * (le mot de passe de la copie SQL n'est jamais stocké en clair ni conservé après génération).
 */
class BuildDataExportJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $exportId, public ?string $password = null) {}

    public function handle(ExportPackager $packager): void
    {
        $export = DataExport::find($this->exportId);
        if (! $export || $export->status !== DataExport::STATUS_PENDING) {
            return;
        }
        $export->update(['status' => DataExport::STATUS_RUNNING, 'started_at' => now()]);
        try {
            $packager->build($export, $this->password);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => DataExport::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
        }
    }

    public function failed(?Throwable $e): void
    {
        DataExport::whereKey($this->exportId)->whereIn('status', [DataExport::STATUS_PENDING, DataExport::STATUS_RUNNING])
            ->update(['status' => DataExport::STATUS_FAILED, 'error' => mb_substr((string) $e?->getMessage(), 0, 2000) ?: 'Interrompu', 'finished_at' => now()]);
    }
}
