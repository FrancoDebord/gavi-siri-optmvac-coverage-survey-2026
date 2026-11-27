<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espaces de dépôt des factures et rapports d'activité des agents : lien public secret protégé par un code PIN (comme
 * les espaces photos). Chaque agent dépose ses documents sans compte ; seuls les gestionnaires les consultent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('token', 64)->unique();      // lien public secret
            $table->text('pin');                         // code PIN (chiffré, affiché aux gestionnaires)
            $table->boolean('linked_to_survey')->default(true); // liste des agents de l'enquête
            $table->boolean('active')->default(true);   // lien ouvert aux dépôts
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120)->nullable();
            $table->string('type', 20);                  // facture | rapport
            $table->string('comment', 500)->nullable();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->string('status', 20)->default('recu'); // recu | valide | a_refaire
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('uploader_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['document_space_id', 'collector_id']);
        });

        // Permission « manage agent documents » (administrateur, investigateur principal).
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_documents');
        Schema::dropIfExists('document_spaces');
    }
};
