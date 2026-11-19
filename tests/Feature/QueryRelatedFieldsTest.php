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

/** « Penta 2 avant Penta 1 » : l'erreur peut venir de l'une ou l'autre date ; partage de la query par lien court. */
class QueryRelatedFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DataQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:A', [
            'today' => '2026-10-20', 'l1' => '1', 'l2' => '1', 'l3' => '1', 'village' => '41', 'fieldworker' => '1',
            'household' => 'HH-A', 'household_survey_status' => '0', 'c_low' => '12', 'c_high' => '35',
            'num_slept_last_night' => '5', 'num_bednets' => '2', 'hh_caregivers_num' => '1',
        ], ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [['key' => null, 'data' => [
            'child_dob' => '2025-01-10', 'receive_vaccine' => '1', 'see_card' => '1',
            'Penta1' => '1', 'Penta1date' => '2025-03-25', 'Penta2' => '1', 'Penta2date' => '2025-02-20',
        ], 'repeats' => []]]]]]], []));
        app(QualityEngine::class)->run('test');

        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);
        $this->query = DataQuery::where('rule_code', 'VAC_DOSE_ORDER')->firstOrFail();
    }

    public function test_page_asks_which_of_the_two_dates_is_wrong(): void
    {
        $this->assertSame('Penta2date', $this->query->variable);

        $this->actingAs($this->admin)->get(route('queries.show', $this->query))->assertOk()
            ->assertSee('Quelle valeur est fausse ?')
            ->assertSee('name="pair_choice"', false)
            ->assertSee('name="corrections[1][variable]" value="Penta1date"', false)
            ->assertSee('Aussi en cause');
    }

    public function test_the_earlier_dose_can_be_corrected_instead(): void
    {
        $path = $this->query->instance_path;
        // Choix « Penta 1 » : la ligne Penta 2 (inchangée, facultative) est ignorée.
        $this->actingAs($this->admin)->post(route('queries.status', $this->query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'Penta2date', 'instance_path' => $path, 'new_value' => '2025-02-20', 'optional' => '1', 'group' => 'paire'],
            ['variable' => 'Penta1date', 'instance_path' => $path, 'new_value' => '2025-01-25', 'optional' => '1', 'group' => 'paire', 'reason' => 'carnet'],
        ]])->assertSessionHas('success');

        $corrections = DataCorrection::where('data_query_id', $this->query->id)->get();
        $this->assertSame(['Penta1date'], $corrections->pluck('variable')->all());
        $this->assertSame('2025-01-25', $corrections->first()->new_value);
    }

    public function test_short_link_opens_the_query_after_login(): void
    {
        $short = $this->query->shareUrl();
        $this->assertStringEndsWith('/q/'.$this->query->code, $short);
        $this->assertStringStartsWith('https://wa.me/?text=', $this->query->whatsappUrl());
        $this->assertStringContainsString(rawurlencode($short), $this->query->whatsappUrl());

        $this->get($short)->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get($short)->assertRedirect(route('queries.show', $this->query));
        $this->actingAs($this->admin)->get('/q/Q-999999')->assertRedirect(route('queries.show', 999999));

        // Hors périmètre : la fiche reste refusée.
        $outsider = User::factory()->create(['health_zone_id' => $this->query->health_zone_id + 1]);
        $outsider->assignRole(User::ROLE_SUPERVISOR);
        $this->actingAs($outsider)->get(route('queries.show', $this->query))->assertForbidden();
    }
}
