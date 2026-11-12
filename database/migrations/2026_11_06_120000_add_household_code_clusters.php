<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grappes issues de l'identifiant ménage (ZONE-VIL-NN-MMM) :
 * code à 3 lettres des villages, numéro de grappe, sous-grappes et contours GeoJSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('villages', function (Blueprint $table) {
            $table->string('short_code', 8)->nullable()->after('code')->index();
        });

        Schema::table('clusters', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->nullable()->after('name');
            $table->json('boundary')->nullable()->after('longitude');
        });

        Schema::create('sub_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->json('boundary')->nullable();
            $table->timestamps();
            $table->unique(['cluster_id', 'number']);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->unsignedSmallInteger('sub_cluster')->nullable()->after('cluster_id');
        });
    }

    public function down(): void
    {
        Schema::table('households', fn (Blueprint $table) => $table->dropColumn('sub_cluster'));
        Schema::dropIfExists('sub_clusters');
        Schema::table('clusters', fn (Blueprint $table) => $table->dropColumn(['number', 'boundary']));
        Schema::table('villages', function (Blueprint $table) {
            $table->dropIndex(['short_code']);
            $table->dropColumn('short_code');
        });
    }
};
