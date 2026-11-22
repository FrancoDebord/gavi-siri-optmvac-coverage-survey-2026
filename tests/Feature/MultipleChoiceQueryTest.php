<?php

namespace Tests\Feature;

use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Question à choix multiple « campaign_vaccs » : une ou plusieurs campagnes cochées (« rr-bj opv-bj »). */
class MultipleChoiceQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function submit(string $uuid, string $code, array $campaigns): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '46', 'country' => 'bj',
            'household' => $code, 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
        ], [
            'cg' => [['key' => null, 'data' => ['participation_agreed' => '1', 'caregiver_num_children' => count($campaigns), 'age_years' => '30'],
                'repeats' => ['smc_cov_hh_cg_child_2023' => array_map(fn ($value) => ['key' => null, 'data' => [
                    'age_months_est' => '20', 'receive_vaccine' => '1', 'campaign_vaccs' => $value,
                ], 'repeats' => []], $campaigns)]]],
        ], []), ['source_type' => 'demo']);
    }

    public function test_one_or_both_campaigns_are_valid_and_a_wrong_answer_is_corrected_with_checkboxes(): void
    {
        $this->submit('uuid:1', 'TCH-SEB-01-001', ['rr-bj opv-bj', 'opv-bj', 'rr-bj', 'rr-bj opv - bj']);
        $this->submit('uuid:2', 'TCH-SEB-01-002', ['rr-bj zz']);
        app(QualityEngine::class)->run('test');

        $queries = DataQuery::where('rule_code', 'DICT_CHOICE')->where('variable', 'campaign_vaccs')->get();
        $this->assertCount(1, $queries, $queries->pluck('message')->implode(' | '));
        $query = $queries->first();
        $this->assertStringContainsString('zz', $query->message);

        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $this->actingAs($manager)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Question à vérifier / corriger')->assertSee('Question à corriger')
            ->assertSee('Une ou plusieurs réponses possibles.')
            ->assertSee('name="corrections[0][new_value][]" id="c0-0" value="rr-bj" checked', false)
            ->assertDontSee('value="penta-gn"', false); // filtre du formulaire : campagnes du pays (country=${country})

        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'campaign_vaccs', 'instance_path' => $query->instance_path, 'new_value' => ['', 'rr-bj', 'opv - bj'], 'reason' => 'carnet'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('rr-bj opv-bj', DataCorrection::where('variable', 'campaign_vaccs')->value('new_value'));
    }
}
