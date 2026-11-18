<?php

use App\Services\Import\SamplingFrameImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de sondage : chaque grappe sélectionnée de chaque village (ménages dénombrés, subdivisions à faire),
 * d'après les listes des zones de Tchaourou et Dassa-Glazoué (database/data/base_sondage_grappes.csv).
 * Un village de la liste peut correspondre à deux villages de la plateforme (ex. Kpassa 1 et Kapassa 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('arrondissement');
            $table->string('village_label');
            $table->json('village_ids')->nullable(); // villages de la plateforme rattachés (vide = non rattaché)
            $table->unsignedSmallInteger('cluster_number');
            $table->boolean('single_cluster')->default(false); // « Grappe » unique du village
            $table->unsignedInteger('households_enumerated');
            $table->unsignedTinyInteger('subdivisions');
            $table->timestamps();
            $table->index(['health_zone_id', 'cluster_number']);
        });

        // Rattachement aux villages existants (base installée) ; sinon : php artisan gavi:import-sampling-frame.
        if (Schema::hasTable('villages') && DB::table('villages')->exists()) {
            (new SamplingFrameImporter)->import();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_frames');
    }
};
