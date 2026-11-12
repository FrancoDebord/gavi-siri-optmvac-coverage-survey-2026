<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // odk_central | kobotoolbox
            $table->string('server_url');
            $table->text('credentials')->nullable(); // JSON chiffré (email, password, token)
            $table->string('project_id')->nullable();
            $table->string('form_id')->nullable();
            $table->string('asset_uid')->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(30);
            $table->boolean('is_active')->default(true);
            $table->text('auth_token')->nullable(); // jeton de session ODK (chiffré)
            $table->timestamp('auth_token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_submission_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->string('form_id');
            $table->string('version');
            $table->string('title')->nullable();
            $table->string('default_language')->nullable();
            $table->string('source', 20)->default('file'); // file | api | default
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('variables_count')->default(0);
            $table->timestamps();
            $table->unique(['form_id', 'version']);
        });

        Schema::create('form_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->string('type', 80);       // type brut (ex. select_one yn)
            $table->string('base_type', 40);  // select_one, integer, date, begin group…
            $table->string('list_name')->nullable();
            $table->text('label')->nullable();
            $table->text('hint')->nullable();
            $table->boolean('required')->default(false);
            $table->text('constraint')->nullable();
            $table->text('constraint_message')->nullable();
            $table->text('relevant')->nullable();
            $table->text('calculation')->nullable();
            $table->string('appearance')->nullable();
            $table->text('choice_filter')->nullable();
            $table->string('group_path')->nullable();  // chemin des groupes/repeats parents
            $table->string('repeat_name')->nullable(); // repeat englobant le plus proche
            $table->timestamps();
            $table->index(['form_version_id', 'name']);
            $table->index(['form_version_id', 'base_type']);
        });

        Schema::create('form_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->string('list_name');
            $table->string('name');
            $table->text('label')->nullable();
            $table->json('extra')->nullable();
            $table->index(['form_version_id', 'list_name']);
        });

        Schema::create('variable_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();      // champ métier (zone_sanitaire, penta1_date…)
            $table->string('label');
            $table->string('category', 30);       // geo, menage, enfant, vaccin, para
            $table->string('variable_name')->nullable();
            $table->string('value')->nullable();  // valeur attendue (ex. consentement = '0')
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 20)->default('manual'); // manual | schedule
            $table->string('status', 20)->default('running'); // running | success | failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['data_source_id', 'started_at']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // data | clusters | dictionary
            $table->string('format', 10)->nullable(); // odk | kobo
            $table->string('status', 20)->default('pending'); // pending | running | done | failed
            $table->json('files')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('variable_mappings');
        Schema::dropIfExists('form_choices');
        Schema::dropIfExists('form_variables');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('data_sources');
    }
};
