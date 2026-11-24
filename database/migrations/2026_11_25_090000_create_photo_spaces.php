<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espaces photos de terrain, un par projet : lien public secret protégé par un code PIN, où les binômes déposent
 * leurs photos sans compte (binôme et village de l'enquête, ou nom et lieu libres pour un autre projet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('token', 64)->unique();      // lien public secret
            $table->text('pin');                         // code PIN (chiffré, affiché aux gestionnaires)
            $table->boolean('linked_to_survey')->default(true); // listes binômes / villages de l'enquête
            $table->boolean('active')->default(true);   // lien ouvert aux dépôts et à la consultation
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('field_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120)->nullable();
            $table->string('place', 160)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->dateTime('taken_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('uploader_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['photo_project_id', 'created_at']);
        });

        // Permission « manage field photos » (administrateur, investigateur principal).
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('field_photos');
        Schema::dropIfExists('photo_projects');
    }
};
