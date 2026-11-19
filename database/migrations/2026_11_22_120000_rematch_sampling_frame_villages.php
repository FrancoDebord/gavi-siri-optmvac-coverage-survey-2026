<?php

use App\Models\Village;
use App\Services\Import\SamplingFrameImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Base de sondage re-rattachée aux villages actuels de la plateforme, avec les correspondances confirmées
 * (Vêdji → VEDJI, EROKOYA (ZANKOUMADON) → Zankoumadon, Itchogué Sotré → Itchogue-Sotre, FITA → Fita).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Village::exists() || ! is_file(SamplingFrameImporter::path())) {
            return; // base vide : rattachement fait au chargement du référentiel
        }
        $report = (new SamplingFrameImporter)->import();
        foreach ($report['unmatched'] as $village) {
            Log::warning("Base de sondage : village non rattaché ({$village}).");
        }
    }

    public function down(): void
    {
        //
    }
};
