<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 20); // auto | metier | expression
            $table->string('severity', 20); // critique | majeure | mineure
            $table->string('scope', 20)->default('household'); // household | caregiver | child
            $table->string('variables')->nullable();
            $table->text('expression')->nullable();
            $table->string('message')->nullable();
            $table->json('parameters')->nullable();
            $table->string('handler')->nullable(); // classe PHP pour les règles auto/métier
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('quality_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 20)->default('manual');
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('households_checked')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('auto_closed_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('data_queries', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('quality_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_code', 40);
            $table->string('severity', 20);
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('caregiver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('child_id')->nullable()->constrained('children')->nullOnDelete();
            $table->string('submission_uuid')->nullable();
            $table->string('household_code')->nullable();
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained()->nullOnDelete();
            $table->date('collection_date')->nullable();
            $table->string('variable')->nullable();
            $table->string('instance_path')->nullable(); // ex. cg[1]/enfant[2]
            $table->text('observed_value')->nullable();
            $table->text('expected')->nullable();
            $table->text('message');
            $table->string('status', 20)->default('ouverte');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('auto_closed')->default(false);
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['cluster_id', 'collection_date']);
            $table->index(['collector_id', 'collection_date']);
            $table->index(['health_zone_id', 'commune_id']);
            $table->index('rule_code');
        });

        Schema::create('query_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('status'); // status | comment | system
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('query_events');
        Schema::dropIfExists('data_queries');
        Schema::dropIfExists('quality_runs');
        Schema::dropIfExists('quality_rules');
    }
};
