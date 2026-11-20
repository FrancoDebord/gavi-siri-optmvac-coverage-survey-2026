<?php

use App\Models\QualityRule;
use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Règles de qualité du Bénin (regles_qualite_benin_cov26c4v2.md) qui complètent les règles en place : créées si
 * elles manquent (codes CRI_xx, MAJ_xx, OBS_xx), sans toucher aux règles existantes. Certaines sont créées
 * désactivées (BeninRuleSet::INACTIVE). Retour arrière : règles et queries supprimées.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (BeninRuleSet::rules() as $code => $rule) {
            QualityRule::firstOrCreate(['code' => $code], [
                'name' => $rule['name'],
                'description' => $rule['message'].' (règle '.str_replace('_', '-', $code).' du document qualité Bénin)',
                'category' => isset($rule['expression']) ? 'expression' : 'metier',
                'kind' => $rule['kind'] === 'observation' ? QualityRule::KIND_OBSERVATION : QualityRule::KIND_QUERY,
                'severity' => $rule['severity'],
                'scope' => $rule['scope'],
                'variables' => $rule['variables'],
                'expression' => $rule['expression'] ?? null,
                'message' => $rule['message'],
                'suggestion' => $rule['suggestion'],
                'handler' => $rule['handler'] ?? null,
                'is_active' => ! in_array($code, BeninRuleSet::INACTIVE, true),
            ]);
        }
    }

    public function down(): void
    {
        $codes = array_keys(BeninRuleSet::rules());
        $ids = DB::table('data_queries')->whereIn('rule_code', $codes)->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('query_events')->whereIn('data_query_id', $chunk)->delete();
            DB::table('data_queries')->whereIn('id', $chunk)->delete();
        }
        QualityRule::whereIn('code', $codes)->delete();
    }
};
