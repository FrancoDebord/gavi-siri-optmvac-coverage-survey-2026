<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observation de supervision envoyée dans les queries par l'administrateur (ou le data manager) : elle suit alors le
 * cycle de résolution normal (réponse du binôme, vérification, validation finale). La date et l'auteur de l'envoi
 * sont conservés ; le moteur garde la ligne parmi les queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('kind');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn('escalated_at');
        });
    }
};
