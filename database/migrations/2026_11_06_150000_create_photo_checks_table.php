<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vérifications des saisies contre les photos du formulaire (carnet, acte de naissance) :
 * valeur lue sur la photo, transformée en query par la règle PHOTO_CHECK tant que la saisie diffère.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_checks', function (Blueprint $table) {
            $table->id();
            $table->string('submission_uuid')->index();
            $table->string('instance_path')->nullable(); // ex. cg[1]/smc_cov_hh_cg_child_2023[2] ; vide = ménage
            $table->string('variable')->nullable();
            // Valeur lue sur la photo ; '' = doit être vide ; null = non vérifiable sur la photo (clôture manuelle).
            $table->string('expected')->nullable();
            $table->string('photo')->nullable(); // pièce jointe ODK consultée
            $table->text('message');
            $table->text('suggestion')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_checks');
    }
};
