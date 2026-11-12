<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Incidents de terrain signalés par les superviseurs (sécurité, accès, matériel…) et leur suivi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on');
            $table->string('category', 30);
            $table->string('severity', 20);
            $table->string('title', 150);
            $table->text('description');
            $table->text('impact')->nullable(); // conséquence sur la collecte (ménages non visités, retard…)
            $table->string('status', 20)->default('ouvert');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['health_zone_id', 'status']);
            $table->index('occurred_on');
        });

        Schema::create('field_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_incident_updates');
        Schema::dropIfExists('field_incidents');
    }
};
