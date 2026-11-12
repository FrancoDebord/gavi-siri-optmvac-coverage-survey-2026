<?php

namespace App\Jobs;

use App\Models\DataSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Planifié chaque minute : lance la synchro des sources actives dont l'intervalle est écoulé. */
class DispatchDueSyncsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DataSource::where('is_active', true)->get()
            ->filter->isDueForSync()
            ->each(fn (DataSource $source) => SyncDataSourceJob::dispatch($source->id, 'schedule'));
    }
}
