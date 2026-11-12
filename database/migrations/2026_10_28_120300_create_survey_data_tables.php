<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('submission_uuid')->unique();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('form_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 20)->nullable(); // odk_central | kobotoolbox | import
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('arrondissement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('household_code')->nullable();
            $table->unsignedTinyInteger('visit_number')->nullable();
            $table->string('survey_status', 10)->nullable();
            $table->boolean('consented')->default(false);
            $table->date('interview_date')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy', 10, 2)->nullable();
            $table->string('form_version')->nullable();
            $table->string('review_state', 30)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->longText('data');     // variables à plat (hors repeats)
            $table->longText('raw_data'); // soumission brute
            $table->timestamps();

            $table->index('interview_date');
            $table->index(['cluster_id', 'interview_date']);
            $table->index(['collector_id', 'interview_date']);
            $table->index(['health_zone_id', 'commune_id']);
            $table->index(['household_code', 'cluster_id']);
        });

        Schema::create('caregivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('repeat_key')->nullable();
            $table->boolean('consented')->default(false);
            $table->string('initials')->nullable();
            $table->string('sex', 5)->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->longText('data');
            $table->timestamps();
            $table->index(['household_id', 'position']);
        });

        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caregiver_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('repeat_key')->nullable();
            $table->string('sex', 5)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->boolean('eligible')->default(false);
            $table->boolean('card_seen')->default(false);
            $table->longText('data');
            $table->timestamps();
            $table->index(['household_id', 'caregiver_id']);
            $table->index('eligible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
        Schema::dropIfExists('caregivers');
        Schema::dropIfExists('households');
    }
};
