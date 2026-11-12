<?php

namespace App\Services\Import;

use App\Models\DataSource;
use App\Models\SyncLog;
use App\Services\Connectors\ConnectorFactory;
use App\Support\DashboardCache;
use Carbon\CarbonImmutable;
use Throwable;

/** Synchronisation incrémentale d'une source ODK Central / KoboToolbox avec journalisation. */
class SyncService
{
    public function __construct(private readonly ConnectorFactory $connectors) {}

    public function sync(DataSource $source, string $trigger = 'manual', ?int $userId = null, bool $full = false): SyncLog
    {
        $log = SyncLog::create([
            'data_source_id' => $source->id,
            'user_id' => $userId,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $startedAt = microtime(true);
        $syncStartedAt = now();

        try {
            $processor = new SubmissionProcessor;
            $connector = $this->connectors->make($source);
            $counts = [SubmissionProcessor::NEW => 0, SubmissionProcessor::UPDATED => 0, SubmissionProcessor::UNCHANGED => 0];
            $latest = $source->last_submission_at;

            $fetched = $connector->fetchSubmissions($full ? null : $source->last_synced_at, function (array $page) use ($processor, $source, &$counts, &$latest) {
                foreach ($page as $submission) {
                    if ($submission->uuid === '') {
                        continue;
                    }
                    $counts[$processor->process($submission, ['data_source_id' => $source->id, 'source_type' => $source->type])]++;
                    $submitted = SubmissionProcessor::dateTime($submission->submittedAt);
                    if ($submitted && (! $latest || $submitted->gt($latest))) {
                        $latest = CarbonImmutable::instance($submitted);
                    }
                }
            });

            $log->update([
                'status' => 'success',
                'fetched_count' => $fetched,
                'new_count' => $counts[SubmissionProcessor::NEW],
                'updated_count' => $counts[SubmissionProcessor::UPDATED],
            ]);
            // On repart de l'heure de début de synchro : une soumission arrivée pendant la synchro sera reprise la fois suivante.
            $source->forceFill(['last_synced_at' => $syncStartedAt, 'last_submission_at' => $latest, 'last_status' => 'success'])->save();
            DashboardCache::bump();
        } catch (Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $source->forceFill(['last_status' => 'failed'])->save();
        } finally {
            $log->update(['finished_at' => now(), 'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000)]);
        }

        return $log->fresh();
    }
}
