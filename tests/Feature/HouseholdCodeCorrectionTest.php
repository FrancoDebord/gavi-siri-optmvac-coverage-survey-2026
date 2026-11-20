<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\Household;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use App\Support\HouseholdCode;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Identifiant ménage non conforme : forme correcte proposée et identifiant modifiable avec la localisation. */
class HouseholdCodeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_household_code_is_proposed(): void
    {
        $this->assertSame('TCH-KAP-01-S1-124', HouseholdCode::normalize('TCH-KAP-1-S-1-124'));
        $this->assertSame('TCH-KEA-05-024', HouseholdCode::normalize('TCH-KEA-05-O24'));
        $this->assertNull(HouseholdCode::normalize('TCH-KAP-01-S1-124'), 'déjà conforme');
        $this->assertNull(HouseholdCode::normalize('DAG-HOI-01-0zen'), 'non reconnaissable');
    }

    public function test_household_code_can_be_corrected_from_the_query(): void
    {
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:K', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
            'household' => 'TCH-SEB-1-S-1-005', 'household_survey_status' => '3',
        ], [], []), ['source_type' => 'demo']);
        app(QualityEngine::class)->run('test');

        $query = DataQuery::where('rule_code', 'HH_CODE')->firstOrFail();
        $this->assertSame('TCH-SEB-01-S1-005', $query->suggested_value);
        $this->assertStringContainsString('TCH-SEB-01-S1-005', $query->suggestion);

        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('queries.show', $query))->assertOk()
            ->assertSee('name="corrections[63][new_value]"', false)
            ->assertSee('value="TCH-SEB-01-S1-005"', false)
            ->assertSee('proposé');

        $this->actingAs($admin)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            63 => ['variable' => 'household', 'new_value' => 'TCH-SEB-01-S1-005', 'optional' => '1', 'group' => 'geo', 'reason' => 'saisie'],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame('TCH-SEB-01-S1-005', Household::where('submission_uuid', 'uuid:K')->value('household_code'));
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
    }
}
