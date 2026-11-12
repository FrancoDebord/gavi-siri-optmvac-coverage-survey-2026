<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Origine des contours importés depuis settings/points_gps (fichier et date du relevé). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clusters', 'sub_clusters'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('boundary_file')->nullable()->after('boundary');
                $table->date('boundary_date')->nullable()->after('boundary_file');
            });
        }
    }

    public function down(): void
    {
        foreach (['clusters', 'sub_clusters'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['boundary_file', 'boundary_date']));
        }
    }
};
