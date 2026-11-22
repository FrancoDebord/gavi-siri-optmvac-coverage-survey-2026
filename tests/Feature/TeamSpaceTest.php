<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSpaceTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->team = Team::where('code', '46-47')->firstOrFail();

        // Binôme 46-47 : hier 1 ménage à Sébou (agent 46), aujourd'hui 2 ménages dont un enquêté avec un enfant éligible (agent 47).
        $processor = new SubmissionProcessor;
        $child = ['key' => null, 'repeats' => [], 'data' => ['child_dob' => '2025-05-02', 'receive_vaccine' => '1', 'age_months_est' => '18', 'c_low' => '12', 'c_high' => '35']];
        foreach ([
            ['uuid:1', 'TCH-SEB-01-001', '46', '3', now()->subDay()->toDateString(), []],
            ['uuid:2', 'TCH-SEB-01-002', '47', '0', now()->toDateString(), ['cg' => [['key' => null, 'data' => ['participation_agreed' => '1'], 'repeats' => ['smc_cov_hh_cg_child_2023' => [$child]]]]]],
            ['uuid:3', 'TCH-SEB-02-001', '47', '1', now()->toDateString(), []],
            ['uuid:4', 'DAG-ASS-01-001', '33', '0', now()->toDateString(), []],
        ] as [$uuid, $code, $collector, $status, $date, $repeats]) {
            $zone = str_starts_with($code, 'DAG') ? ['2', '2', '13', '57'] : ['1', '1', '5', '66'];
            $processor->process(new NormalizedSubmission($uuid, [
                'today' => $date, 'l1' => $zone[0], 'l2' => $zone[1], 'l3' => $zone[2], 'village' => $zone[3], 'fieldworker' => $collector,
                'household' => $code, 'household_survey_status' => $status,
            ], $repeats, []), ['source_type' => 'demo']);
        }
        app(QualityEngine::class)->run('test');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_team_account_sees_its_own_progress_only(): void
    {
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);

        // Connexion : « / » renvoie vers l'espace du binôme, sans message d'erreur.
        $this->actingAs($account)->get('/')->assertRedirect(route('team.queries'))->assertSessionMissing('danger');

        $response = $this->actingAs($account)->get(route('team.space', ['team_id' => Team::where('code', '33-43')->value('id')]))->assertOk()
            ->assertSee('Binôme 46-47')->assertSee('Enfants éligibles (12 à 35 mois)')->assertDontSee('Boton');
        $kpis = $response->viewData('kpis');
        $this->assertSame([1, 2, 3, 1, 1], [$kpis['total']['villages'], $kpis['total']['clusters'], $kpis['total']['visited'], $kpis['total']['surveyed'], $kpis['total']['children']]);
        $this->assertSame([2, 1, 1], [$kpis['today']['visited'], $kpis['today']['surveyed'], $kpis['today']['children']]);
        $this->assertSame(2, $kpis['days']);
        $daily = $response->viewData('daily');
        $this->assertSame([1, 3], array_column($daily, 'cum_visited'));
        $this->assertStringContainsString('Sébou (TCH-SEB-01, TCH-SEB-02)', $daily[1]['places']);
        $this->assertSame([1, 2], array_column($response->viewData('agents'), 'visited'));
        $this->assertGreaterThan(0, $kpis['open_queries']);

        // Aucun accès aux dashboards de l'équipe centrale.
        foreach (['dashboard.data', 'queries.index', 'supervision.index', 'investigator.index', 'messages.index'] as $route) {
            $this->actingAs($account)->get(route($route))->assertRedirect(route('team.queries'));
        }
    }

    public function test_team_sees_its_submissions_queries_and_clusters_only(): void
    {
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $mine = Household::where('household_code', 'TCH-SEB-01-002')->firstOrFail();
        $other = Household::where('household_code', 'DAG-ASS-01-001')->firstOrFail();

        // Soumissions : les 3 ménages du binôme, filtres, fiche détaillée de ses seuls ménages.
        $page = $this->actingAs($account)->get(route('team.submissions'))->assertOk()->assertSee('TCH-SEB-01-002')->assertDontSee('DAG-ASS-01-001');
        $this->assertSame(3, $page->viewData('households')->total());
        $this->assertSame(1, $this->actingAs($account)->get(route('team.submissions', ['statut' => 'enquetes']))->viewData('households')->total());
        $this->actingAs($account)->get(route('households.show', $mine))->assertOk()->assertSee('TCH-SEB-01-002');
        $this->actingAs($account)->get(route('households.show', $other))->assertForbidden();
        $this->actingAs($account)->get(route('households.photo', [$other, 'x.jpg']))->assertForbidden();

        // Queries : liste et fiche détaillée en lecture seule.
        $queries = $this->actingAs($account)->get(route('team.queries'))->assertOk()
            ->assertSee('Progression de la résolution des erreurs par le binôme')->assertSee('data-progress-answered', false)->viewData('queries');
        $this->assertGreaterThan(0, $queries->total());
        $this->assertTrue(collect($queries->items())->every(fn ($q) => in_array($q->household_code, ['TCH-SEB-01-001', 'TCH-SEB-01-002', 'TCH-SEB-02-001'], true)));
        $query = $queries->items()[0];
        $this->actingAs($account)->get(route('queries.show', $query))->assertOk()->assertSee($query->code)->assertSee('Mes queries')->assertSee('query-status-form', false)->assertSee('Valeur saisie (ODK)');
        // Le compte binôme répond sans attendre d'affectation ; une correction exige une valeur.
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => 'corrigee', 'comment' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame(DataQuery::STATUS_OPEN, $query->fresh()->status);
        $foreign = DataQuery::where('household_code', 'DAG-ASS-01-001')->firstOrFail();
        $this->actingAs($account)->get(route('queries.show', $foreign))->assertForbidden();

        // Grappes : contour relevé et ménages du binôme ; grappe d'un autre binôme refusée.
        $cluster = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $cluster->update(['boundary' => ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['hameau' => null, 'superficie_ha' => 4.2],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[2.48, 9.30], [2.50, 9.30], [2.50, 9.32], [2.48, 9.30]]]]]]], 'boundary_file' => 'TCH-SEB-01-06-11-2026.csv', 'boundary_date' => '2026-11-06']);
        $map = $this->actingAs($account)->get(route('team.clusters'))->assertOk()->assertSee('TCH-SEB-01-06-11-2026.csv')->assertSee('4,20 ha')->viewData('mapData');
        $this->assertSame(['TCH-SEB-01'], array_column($map['boundaries'], 'code'));
        $this->actingAs($account)->get(route('clusters.show', $cluster))->assertOk()->assertSee('Mes grappes');
        $this->actingAs($account)->get(route('clusters.show', Cluster::where('code', 'DAG-ASS-01')->firstOrFail()))->assertForbidden();

        // Équipe centrale : mêmes onglets pour le binôme choisi.
        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('team.queries', ['team_id' => $this->team->id]))->assertOk()->assertSee('Binôme 46-47');
    }

    public function test_pi_progress_tab_for_all_zones_or_one_zone(): void
    {
        $pi = $this->user(User::ROLE_PI);

        $page = $this->actingAs($pi)->get(route('investigator.progress'))->assertOk()
            ->assertSee('Progression de la collecte')->assertSee('Enfants éligibles (12 à 35 mois)')->assertSee('Par zone sanitaire');
        $kpis = $page->viewData('kpis');
        $this->assertSame([2, 4, 2, 1], [$kpis['total']['villages'], $kpis['total']['visited'], $kpis['total']['surveyed'], $kpis['total']['children']]);
        $this->assertSame([3, 2], [$kpis['today']['visited'], $kpis['today']['surveyed']]);
        $daily = collect($page->viewData('daily'))->keyBy('date');
        $this->assertSame(2, $daily[now()->toDateString()]['teams'], 'binômes 46-47 et 33-43 actifs aujourd\'hui');
        $this->assertSame(['46-47', '33-43'], collect($page->viewData('byTeam'))->take(2)->map(fn ($r) => $r['team']->code)->sortDesc()->values()->all());
        $this->assertCount(2, $page->viewData('byZone'));

        // Une zone : ses chiffres seulement, plus de tableau par zone.
        $zone = $this->actingAs($pi)->get(route('investigator.progress', ['health_zone_id' => HealthZone::where('code', '1')->value('id')]))->assertOk();
        $this->assertSame(3, $zone->viewData('kpis')['total']['visited']);
        $this->assertSame([], $zone->viewData('byZone'));
        $this->assertCount(8, $zone->viewData('byTeam'));

        $this->actingAs($pi)->get(route('investigator.index'))->assertSee(route('investigator.progress'));
        $this->actingAs($this->user(User::ROLE_READER))->get(route('investigator.progress'))->assertRedirect(route('dashboard'));
    }

    public function test_team_reads_its_messages(): void
    {
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $pi = $this->user(User::ROLE_PI);
        $other = Team::where('code', '33-43')->firstOrFail();
        foreach ([['team', $this->team->id, 'Bravo 46-47'], ['collector', $this->team->collectors()->value('id'), 'Pour l\'agent 46'], ['team', $other->id, 'Pour 33-43'], ['all', null, 'Pour tous']] as [$type, $id, $body]) {
            TeamMessage::create(['sender_id' => $pi->id, 'target_type' => $type, 'target_id' => $id, 'kind' => 'encouragement', 'body' => $body]);
        }

        $this->actingAs($account)->get(route('team.space'))->assertSee('Bravo 46-47')->assertSee('Pour l&#039;agent 46', false)->assertSee('Pour tous')->assertDontSee('Pour 33-43');
        $message = TeamMessage::where('body', 'Bravo 46-47')->firstOrFail();
        $this->actingAs($account)->post(route('messages.read', $message))->assertRedirect();
        $this->assertTrue($message->isReadBy($account));
        $this->actingAs($account)->post(route('messages.read', TeamMessage::where('body', 'Pour 33-43')->firstOrFail()))->assertForbidden();
    }

    public function test_supervisor_and_central_team_can_open_a_team_space(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '1')->value('id')]);
        $this->actingAs($supervisor)->get(route('team.space', ['team_id' => $this->team->id]))->assertOk()->assertSee('Binôme 46-47');
        $this->actingAs($supervisor)->get(route('team.space', ['team_id' => Team::where('code', '33-43')->value('id')]))->assertForbidden();

        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('team.space', ['team_id' => Team::where('code', '33-43')->value('id')]))->assertOk()->assertSee('Binôme 33-43');
    }

    public function test_team_account_requires_a_team(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $payload = ['name' => 'Binôme 46-47', 'email' => 'b4647@test.bj', 'role' => User::ROLE_TEAM, 'is_active' => 1, 'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123'];

        $this->actingAs($admin)->post(route('users.store'), $payload)->assertSessionHasErrors('team_id');
        $this->actingAs($admin)->post(route('users.store'), $payload + ['team_id' => $this->team->id])->assertRedirect();
        $this->assertSame($this->team->id, User::where('email', 'b4647@test.bj')->value('team_id'));
    }

    public function test_pi_reports_for_villages_and_teams(): void
    {
        $pi = $this->user(User::ROLE_PI);

        foreach ([
            ['type' => 'villages'],
            ['type' => 'villages', 'health_zone_id' => HealthZone::where('code', '1')->value('id')],
            ['type' => 'binomes'],
            ['type' => 'binomes_jour', 'du' => now()->subDays(3)->toDateString(), 'au' => now()->toDateString()],
        ] as $params) {
            $response = $this->actingAs($pi)->get(route('investigator.report', $params))->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), json_encode($params));
        }
        $this->actingAs($pi)->get(route('investigator.report', ['type' => 'binomes_jour', 'du' => '2026-11-05', 'au' => '2026-11-01']))->assertSessionHasErrors('au');

        // KPIs et queries à suivre sur la vue d'ensemble.
        $page = $this->actingAs($pi)->get(route('investigator.index'))->assertOk()->assertSee('Queries à suivre')->assertSee('Villages visités');
        $this->assertSame(2, $page->viewData('more')['villages']);
        $this->assertSame(2, $page->viewData('more')['teams_today']);
        $this->assertNotEmpty($page->viewData('followQueries'));
    }
}
