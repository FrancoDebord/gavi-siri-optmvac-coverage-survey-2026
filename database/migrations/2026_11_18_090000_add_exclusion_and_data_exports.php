<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - households.excluded_at : soumission exclue de la base nettoyée (doublon), par une correction validée du registre ;
 *   elle reste dans la base brute avec ses queries.
 * - data_exports : exports générés (base brute, base nettoyée, paquet d'archivage, copie SQL chiffrée) avec empreinte
 *   SHA-256, manifeste, auteur, expiration et nombre de téléchargements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('excluded_at')->nullable()->after('source_data')->index();
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('en_attente');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });

        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        Schema::table('households', function (Blueprint $table) {
            $table->dropIndex(['excluded_at']);
            $table->dropColumn('excluded_at');
        });
    }
};
