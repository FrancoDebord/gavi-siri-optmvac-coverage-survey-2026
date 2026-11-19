<?php

use App\Models\Team;
use App\Services\Import\TeamVillageImporter;
use Database\Seeders\QualityRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Villages couverts par chaque binôme (règle TEAM_VILLAGE : village coché hors de ceux du binôme de l'agent).
 * Crée la règle si elle manque et charge la liste livrée (database/data/binomes_villages.csv).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_village', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('village_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'village_id']);
        });

        (new QualityRuleSeeder)->run(); // règles du catalogue manquantes (sans toucher aux règles existantes)

        if (Team::exists()) {
            $report = (new TeamVillageImporter)->import();
            foreach ($report['unmatched'] as $line) {
                Log::warning("Villages des binômes : {$line}.");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_village');
    }
};
