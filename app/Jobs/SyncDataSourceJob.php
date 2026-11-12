<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Services\Import\SyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncDataSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $dataSourceId,
        public string $trigger = 'manual',
        public ?int $userId = null,
        public bool $full = false,
    ) {}

    public function uniqueId(): string
    {
        return 'sync-'.$this->dataSourceId;
    }

    public function handle(SyncService $sync): void
    {
        $source = DataSource::find($this->dataSourceId);
        if (! $source) {
            return;
        }

        $log = $sync->sync($source, $this->trigger, $this->userId, $this->full);

        if ($log->status === 'success' && ($log->new_count + $log->updated_count) > 0) {
            RunQualityChecksJob::dispatch('sync', $this->userId);
        }
    }
}
