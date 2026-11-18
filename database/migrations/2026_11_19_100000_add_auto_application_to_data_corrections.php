<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections appliquées immédiatement (elles lèvent l'anomalie sans en créer) : revue a posteriori par le
 * data manager, qui les accepte ou les refuse (la valeur précédente est alors rétablie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_corrections', function (Blueprint $table) {
            $table->boolean('auto_applied')->default(false)->after('signed');
            $table->foreignId('reviewed_by')->nullable()->after('auto_applied')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('data_corrections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['auto_applied', 'reviewed_at']);
        });
    }
};
