<?php

namespace App\Jobs;

use App\Services\Quality\QualityEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Exécute le moteur de queries sur l'ensemble des soumissions. */
class RunQualityChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** Une exécution en attente suffit : les suivantes sont absorbées. */
    public int $uniqueFor = 600;

    public function __construct(public string $trigger = 'manual', public ?int $userId = null) {}

    public function uniqueId(): string
    {
        return 'quality-checks';
    }

    public function handle(QualityEngine $engine): void
    {
        $engine->run($this->trigger, $this->userId);
    }
}
