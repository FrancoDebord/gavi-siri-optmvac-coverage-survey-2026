<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valeur suggérée pour la correction d'une query, lue sur une photo de la soumission : par le data manager
 * (source « photo ») ou par lecture automatique (source « ia », à vérifier par l'agent). Jamais appliquée seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->text('suggested_value')->nullable()->after('suggestion');
            $table->string('suggested_source', 20)->nullable()->after('suggested_value');
            $table->string('suggested_note')->nullable()->after('suggested_source');
            $table->foreignId('suggested_by')->nullable()->after('suggested_note')->constrained('users')->nullOnDelete();
            $table->timestamp('suggested_at')->nullable()->after('suggested_by');
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_by');
            $table->dropColumn(['suggested_value', 'suggested_source', 'suggested_note', 'suggested_at']);
        });
    }
};
