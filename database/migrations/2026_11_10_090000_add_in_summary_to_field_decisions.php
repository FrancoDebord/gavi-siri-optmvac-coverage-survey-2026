<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Décision affichée ou non dans le rapport de synthèse du PI (affichée par défaut). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_decisions', function (Blueprint $table) {
            $table->boolean('in_summary')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('field_decisions', fn (Blueprint $table) => $table->dropColumn('in_summary'));
    }
};
