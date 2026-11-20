<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\QualityRule;
use App\Services\Quality\RuleCatalog;
use Database\Seeders\QualityRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DateRuleSuggestionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_queries_get_the_new_suggestion(): void
    {
        $this->seed(QualityRuleSeeder::class);
        $old = 'Relire sur le carnet les dates de la série ; corriger l\'inversion des doses ou du jour et du mois pour « {question} ».';
        $rule = QualityRule::where('code', 'VAC_DOSE_ORDER')->firstOrFail();
        $rule->update(['suggestion' => $old]);
        $id = DB::table('data_queries')->insertGetId([
            'fingerprint' => 'f1', 'quality_rule_id' => $rule->id, 'rule_code' => 'VAC_DOSE_ORDER', 'severity' => 'majeure', 'status' => DataQuery::STATUS_VALIDATED,
            'kind' => QualityRule::KIND_QUERY, 'variable' => 'opv3date', 'variable_label' => 'Date VPO 3', 'message' => 'VPO 3 avant VPO 2',
            'suggestion' => 'ancien texte', 'detected_at' => now(), 'last_detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        (include database_path('migrations/2026_11_22_090000_refresh_date_rule_suggestions.php'))->up();

        $this->assertSame(RuleCatalog::SUGGESTIONS['VAC_DOSE_ORDER'], $rule->fresh()->suggestion);
        $this->assertStringContainsString('« Date VPO 3 » et la dose précédente', DB::table('data_queries')->where('id', $id)->value('suggestion'));
    }
}
