<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponse qui appelle une fermeture particulière : ex. MAJ_18, âge confirmé par le binôme (l'enfant a bien 5 ans ou
 * plus) ; à la validation finale, la query est fermée comme irrésoluble et non comme validée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->string('closes_as', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropColumn('closes_as');
        });
    }
};
