<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base nettoyée et registre des corrections (piste d'audit GCP) :
 * - households.source_data : la soumission telle que reçue (normalisée, sans correction) ; la base de travail
 *   (households, caregivers, children) est reconstruite depuis cette source + les corrections validées ;
 * - data_corrections : registre des corrections (on n'y modifie que le statut ; une correction est remplacée
 *   par une nouvelle, jamais réécrite). Les données ODK ne sont jamais modifiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->json('source_data')->nullable()->after('raw_data');
        });

        Schema::create('data_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('submission_uuid')->index();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('data_query_id')->nullable()->constrained('data_queries')->nullOnDelete();
            $table->string('variable');
            $table->string('instance_path')->nullable(); // ex. cg[1]/smc_cov_hh_cg_child_2023[2]
            $table->text('source_value')->nullable(); // valeur reçue d'ODK
            $table->text('old_value')->nullable(); // valeur de la base nettoyée au moment de la proposition
            $table->text('new_value')->nullable(); // null = valeur effacée
            $table->string('reason', 30);
            $table->string('source_reference')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('proposee');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('signed')->default(false);
            $table->text('decision_comment')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('data_corrections')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'submission_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_corrections');
        Schema::table('households', fn (Blueprint $table) => $table->dropColumn('source_data'));
    }
};
