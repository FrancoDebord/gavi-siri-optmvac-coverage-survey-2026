<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rechargement des données d'un ménage avec les corrections proposées : une query dont l'erreur disparaît grâce à
 * la correction proposée dans une autre query suit cette correction (résolue à sa validation, rouverte à son refus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->foreignId('resolved_by_query_id')->nullable()->after('auto_closed')->constrained('data_queries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by_query_id');
        });
    }
};
