<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gel de la base (database lock) : registre des gels et dégels. Chaque gel ouvre une version numérotée,
 * archivée par un paquet d'archivage (export) dont l'empreinte SHA-256 identifie la version gelée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('action', 10); // gel | degel
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('signed')->default(false);
            $table->foreignId('data_export_id')->nullable()->constrained('data_exports')->nullOnDelete();
            $table->json('stats')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_locks');
    }
};
