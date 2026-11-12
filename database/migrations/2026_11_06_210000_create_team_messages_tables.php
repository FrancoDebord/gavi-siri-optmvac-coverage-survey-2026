<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages de l'investigateur principal (encouragement, avertissement, information) adressés
 * à un superviseur, aux superviseurs d'une zone, à un binôme, à un agent ou à toutes les équipes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 20); // all, zone, supervisor, team, collector
            $table->unsignedBigInteger('target_id')->nullable();
            // Zone concernée (déduite de la cible) : détermine les superviseurs qui voient le message.
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // encouragement, avertissement, information
            $table->text('body');
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['health_zone_id', 'created_at']);
        });

        Schema::create('team_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['team_message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_message_reads');
        Schema::dropIfExists('team_messages');
    }
};
