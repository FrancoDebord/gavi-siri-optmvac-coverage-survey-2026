<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Dictionary\Dictionary;
use App\Services\Import\SubmissionProcessor;
use App\Services\Import\TabularSubmissionReader;
use App\Support\DashboardCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Import d'un export ODK (CSV) ou Kobo (XLSX) avec suivi de progression. */
class ImportSubmissionsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'running', 'message' => 'Lecture des fichiers…']);

        try {
            $reader = (new TabularSubmissionReader(Dictionary::current()))->load($batch->files);
            $batch->update(['total' => $reader->count(), 'format' => $reader->format(), 'message' => 'Enregistrement des soumissions…']);

            $processor = new SubmissionProcessor;
            $counts = [SubmissionProcessor::NEW => 0, SubmissionProcessor::UPDATED => 0, SubmissionProcessor::UNCHANGED => 0];
            $processed = 0;

            foreach ($reader->submissions() as $submission) {
                $counts[$processor->process($submission, ['import_batch_id' => $batch->id, 'source_type' => 'import_'.$reader->format()])]++;
                if (++$processed % 25 === 0) {
                    $batch->update(['processed' => $processed]);
                }
            }

            $batch->update([
                'status' => 'done',
                'processed' => $processed,
                'new_count' => $counts[SubmissionProcessor::NEW],
                'updated_count' => $counts[SubmissionProcessor::UPDATED],
                'message' => sprintf('%d soumission(s) : %d nouvelle(s), %d mise(s) à jour, %d inchangée(s).',
                    $processed, $counts[SubmissionProcessor::NEW], $counts[SubmissionProcessor::UPDATED], $counts[SubmissionProcessor::UNCHANGED]),
            ]);
            DashboardCache::bump();

            if ($counts[SubmissionProcessor::NEW] + $counts[SubmissionProcessor::UPDATED] > 0) {
                RunQualityChecksJob::dispatch('import', $batch->user_id);
            }
        } catch (Throwable $e) {
            report($e);
            $batch->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }
}
