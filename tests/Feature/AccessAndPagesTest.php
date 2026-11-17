<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAndPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        // Deux ménages dans deux zones sanitaires, chacun avec une anomalie (GPS manquant).
        $processor = new SubmissionProcessor;
        foreach ([['1', '1', '1', '41', 'A'], ['2', '2', '28', '1', 'B']] as [$zone, $commune, $arr, $village, $code]) {
            $processor->process(new NormalizedSubmission("uuid:{$code}", [
                'today' => '2026-10-20', 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => '1',
                'household' => "HH-{$code}", 'household_survey_status' => '1',
            ], [], []), ['source_type' => 'demo']);
        }
        app(QualityEngine::class)->run('test');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_stalled_queue_is_reported_to_administrators(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('sources.index'))->assertOk()->assertDontSee('aucun processus ne traite la file');

        \DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);
        $this->actingAs($admin)->get(route('sources.index'))->assertOk()->assertSee('aucun processus ne traite la file');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('aucun processus ne traite la file');
        $this->actingAs($this->user(User::ROLE_READER))->get(route('dashboard'))->assertOk()->assertDontSee('aucun processus ne traite la file');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Connexion');
        $this->get(route('password.request'))->assertOk()->assertSee('Mot de passe oublié');
    }

    public function test_login_and_inactive_accounts(): void
    {
        $user = $this->user(User::ROLE_READER, ['email' => 'lecteur@test.bj']);
        $this->post(route('login'), ['email' => 'lecteur@test.bj', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        auth()->logout();

        $this->user(User::ROLE_READER, ['email' => 'inactif@test.bj', 'is_active' => false]);
        $this->post(route('login'), ['email' => 'inactif@test.bj', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_can_open_every_page(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $query = DataQuery::firstOrFail();

        foreach ([
            route('dashboard'), route('queries.dashboard'), route('queries.index'), route('queries.show', $query),
            route('households.show', $query->household_id), route('imports.index'), route('dictionary.index'),
            route('mapping.edit'), route('rules.index'), route('rules.create'), route('sources.index'), route('sources.create'),
            route('users.index'), route('users.create'), route('audit.index'), route('profile.edit'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_dashboard_json_endpoints(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->getJson(route('dashboard.data'))->assertOk()
            ->assertJsonPath('kpis.visited', 2)
            ->assertJsonPath('kpis.refused', 2)
            ->assertJsonStructure(['kpis', 'daily', 'by_zone', 'by_commune', 'productivity', 'hierarchy', 'points']);

        $this->actingAs($admin)->getJson(route('queries.dashboard.data'))->assertOk()
            ->assertJsonStructure(['kpis' => ['total', 'open', 'per_100_households'], 'by_severity', 'top_rules', 'by_variable', 'rankings' => ['zone', 'collector'], 'heatmap']);

        $this->actingAs($admin)->getJson(route('queries.datatable', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk()
            ->assertJsonPath('draw', 1)
            ->assertJsonStructure(['recordsTotal', 'recordsFiltered', 'data' => [['id', 'message', 'status_label']]]);

        $this->actingAs($admin)->getJson(route('filters.options'))->assertOk()->assertJsonCount(2, 'options.health_zone_id');
    }

    public function test_exports_download(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('dashboard.export', 'xlsx'))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('dashboard.export', 'pdf'))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('queries.export.collectors'))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('queries.report.daily', ['date' => '2026-10-20']))->assertOk()->assertDownload();
    }

    public function test_reader_only_sees_dashboards(): void
    {
        $reader = $this->user(User::ROLE_READER);

        $this->actingAs($reader)->get(route('dashboard'))->assertOk();
        $this->actingAs($reader)->get(route('imports.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($reader)->get(route('sources.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($reader)->postJson(route('queries.bulk'), ['ids' => [1], 'status' => 'envoyee'])->assertForbidden();
    }

    public function test_data_manager_cannot_manage_sources_or_users(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);

        $this->actingAs($manager)->get(route('imports.index'))->assertOk();
        $this->actingAs($manager)->get(route('rules.index'))->assertOk();
        $this->actingAs($manager)->get(route('sources.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($manager)->get(route('users.index'))->assertRedirect(route('dashboard'));
    }

    public function test_supervisor_is_restricted_to_their_zone(): void
    {
        $zone = HealthZone::where('code', '1')->firstOrFail();
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $zone->id]);
        $mine = DataQuery::where('health_zone_id', $zone->id)->firstOrFail();
        $other = DataQuery::where('health_zone_id', '!=', $zone->id)->firstOrFail();

        // Les filtres demandés ne permettent pas de sortir du périmètre.
        $this->actingAs($supervisor)->getJson(route('dashboard.data', ['health_zone_id' => $other->health_zone_id]))
            ->assertOk()->assertJsonPath('kpis.visited', 1);
        $data = $this->actingAs($supervisor)->getJson(route('queries.datatable', ['draw' => 1, 'length' => 100]))->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame([$zone->name], array_values(array_unique(array_column($data, 'zone'))));

        $this->actingAs($supervisor)->get(route('queries.show', $mine))->assertOk();
        $this->actingAs($supervisor)->get(route('queries.show', $other))->assertForbidden();
        $this->actingAs($supervisor)->get(route('households.show', $other->household_id))->assertForbidden();

        // Traitement : seule la query du périmètre est modifiée.
        $response = $this->actingAs($supervisor)->postJson(route('queries.bulk'), ['ids' => [$mine->id, $other->id], 'status' => DataQuery::STATUS_JUSTIFIED, 'comment' => 'Vérifié sur le carnet']);
        $response->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $mine->fresh()->status);
        $this->assertSame(DataQuery::STATUS_OPEN, $other->fresh()->status);
    }

    public function test_commune_supervisor_filters_are_locked(): void
    {
        $commune = Commune::where('code', '1')->firstOrFail();
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $commune->health_zone_id, 'commune_id' => $commune->id]);

        $this->actingAs($supervisor)->getJson(route('filters.options'))->assertOk()
            ->assertJsonPath('locked', ['health_zone_id', 'commune_id'])
            ->assertJsonCount(1, 'options.commune_id');
    }

    public function test_status_change_from_query_page_is_audited_in_history(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $query = DataQuery::firstOrFail();

        // Transition interdite : pas de fermeture sans réponse.
        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED])
            ->assertSessionHasErrors('status');

        $this->actingAs($manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_JUSTIFIED, 'comment' => 'Date relue sur le carnet'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(DataQuery::STATUS_JUSTIFIED, $query->fresh()->status);
        $this->assertDatabaseHas('query_events', ['data_query_id' => $query->id, 'to_status' => DataQuery::STATUS_JUSTIFIED, 'user_id' => $manager->id]);
    }

    public function test_custom_expression_rule_can_be_created_and_tested(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);

        $this->actingAs($manager)->postJson(route('rules.test'), ['expression' => "\${household_survey_status} = '1'", 'scope' => 'household'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($manager)->post(route('rules.store'), [
            'code' => 'expr_refus', 'name' => 'Refus', 'severity' => 'mineure', 'scope' => 'household',
            'expression' => "\${household_survey_status} = '1'", 'is_active' => '1',
        ])->assertRedirect(route('rules.index'));
        $this->actingAs($manager)->post(route('rules.store'), [
            'code' => 'EXPR_BAD', 'name' => 'Mauvaise', 'severity' => 'mineure', 'scope' => 'household', 'expression' => '${a} = (',
        ])->assertSessionHasErrors('expression');

        app(QualityEngine::class)->run('test');
        $this->assertSame(Household::count(), DataQuery::where('rule_code', 'EXPR_REFUS')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rule.created', 'user_id' => $manager->id]);
    }
}
