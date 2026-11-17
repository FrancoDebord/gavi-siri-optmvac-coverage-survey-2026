<?php

use App\Services\Quality\RuleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des queries (GCP) :
 * - nature de chaque règle : query (donnée à corriger ou confirmer) ou observation de supervision (paradonnées) ;
 * - affectation des queries (assigné, auteur et date de l'affectation, échéance) et auteur de la réponse ;
 * - paramètres de l'application (affectation automatique, échéance, double validation).
 * Les anciennes queries des règles devenues observations sont reclassées (jamais supprimées), avec trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_rules', function (Blueprint $table) {
            $table->string('kind', 20)->default('query')->after('category');
        });
        Schema::table('data_queries', function (Blueprint $table) {
            $table->string('kind', 20)->default('query')->after('rule_code');
            $table->foreignId('assigned_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->date('due_on')->nullable()->after('assigned_at');
            $table->foreignId('responded_by')->nullable()->after('due_on')->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable()->after('responded_by');
            $table->foreignId('validated_by')->nullable()->after('responded_at')->constrained('users')->nullOnDelete();
            $table->index(['kind', 'status']);
            $table->index('assigned_to');
        });
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('query_events', function (Blueprint $table) {
            $table->boolean('signed')->default(false)->after('comment');
        });

        $observations = RuleCatalog::OBSERVATIONS;
        DB::table('quality_rules')->whereIn('code', $observations)->update(['kind' => 'observation']);
        DB::table('data_queries')->whereIn('rule_code', $observations)->update(['kind' => 'observation']);

        // Trace sur chaque query reclassée encore ouverte.
        $now = now();
        DB::table('data_queries')->whereIn('rule_code', $observations)->whereIn('status', ['ouverte', 'envoyee', 'justifiee', 'corrigee'])
            ->orderBy('id')->select('id', 'status')->chunk(500, function ($rows) use ($now) {
                DB::table('query_events')->insert($rows->map(fn ($r) => [
                    'data_query_id' => $r->id, 'type' => 'system', 'from_status' => $r->status, 'to_status' => null,
                    'comment' => 'Règle reclassée en observation de supervision (paradonnées) : ce n\'est plus une query de données.',
                    'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });

        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('query_events', fn (Blueprint $table) => $table->dropColumn('signed'));
        Schema::dropIfExists('app_settings');
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropIndex(['assigned_to']);
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropConstrainedForeignId('responded_by');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['kind', 'assigned_at', 'due_on', 'responded_at']);
        });
        Schema::table('quality_rules', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
