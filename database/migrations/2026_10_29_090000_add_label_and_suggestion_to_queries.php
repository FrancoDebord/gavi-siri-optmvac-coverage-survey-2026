<?php

use App\Services\Quality\RuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche complète d'une query : libellé, libellé de la question concernée, suggestion de résolution.
 * La suggestion est portée par la règle (modifiable) et recopiée dans chaque query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_rules', function (Blueprint $table) {
            $table->text('suggestion')->nullable()->after('message');
        });

        Schema::table('data_queries', function (Blueprint $table) {
            $table->string('label')->nullable()->after('rule_code');
            $table->string('variable_label')->nullable()->after('variable');
            $table->text('suggestion')->nullable()->after('message');
            $table->index('detected_at');
            $table->index('variable');
        });

        // Suggestions par défaut pour les règles déjà en base.
        foreach (RuleCatalog::all() as $code => $rule) {
            DB::table('quality_rules')->where('code', $code)->whereNull('suggestion')->update(['suggestion' => $rule['suggestion'] ?? null]);
        }
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropIndex(['detected_at']);
            $table->dropIndex(['variable']);
            $table->dropColumn(['label', 'variable_label', 'suggestion']);
        });
        Schema::table('quality_rules', function (Blueprint $table) {
            $table->dropColumn('suggestion');
        });
    }
};
