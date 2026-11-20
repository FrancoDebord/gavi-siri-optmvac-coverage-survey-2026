<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validation en deux temps : la réponse d'un binôme est d'abord vérifiée par le superviseur de sa zone (statut
 * « validee_superviseur »), puis validée définitivement par l'administrateur ou le data manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->foreignId('supervised_by')->nullable()->after('responded_at')->constrained('users')->nullOnDelete();
            $table->timestamp('supervised_at')->nullable()->after('supervised_by');
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervised_by');
            $table->dropColumn('supervised_at');
        });
    }
};
