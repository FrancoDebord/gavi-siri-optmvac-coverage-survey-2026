<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\FieldIncident;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use App\Models\Village;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    private HealthZone $tchaourou;

    private HealthZone $dagla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        $this->tchaourou = HealthZone::where('code', '1')->firstOrFail();
        $this->dagla = HealthZone::where('code', '2')->firstOrFail();

        $processor = new SubmissionProcessor;
        $today = now()->toDateString();
        foreach ([
            ['uuid:1', 'TCH-SEB-01-001', '1', '1', '5', '66', '46', '0', $today],
            ['uuid:2', 'TCH-SEB-01-002', '1', '1', '5', '66', '46', '3', $today],
            ['uuid:3', 'TCH-SEB-02-001', '1', '1', '5', '66', '47', '1', now()->subDay()->toDateString()],
            ['uuid:4', 'DAG-ASS-01-001', '2', '2', '13', '57', '33', '0', $today],
        ] as [$uuid, $code, $zone, $commune, $arr, $village, $collector, $status, $date]) {
            $processor->process(new NormalizedSubmission($uuid, [
                'today' => $date, 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => $collector,
                'household' => $code, 'household_survey_status' => $status,
            ], [], []), ['source_type' => 'demo']);
        }
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_supervisor_lands_on_their_zone_with_collectors_and_clusters(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->tchaourou->id]);

        $this->actingAs($supervisor)->get(route('dashboard'))->assertRedirect(route('supervision.index'));
        $this->actingAs($supervisor)->get(route('dashboard', ['complet' => 1]))->assertOk();

        $response = $this->actingAs($supervisor)->get(route('supervision.index', ['health_zone_id' => $this->dagla->id]))->assertOk()
            ->assertSee('Ma zone : Tchaourou')
            ->assertSee('Grappe 01')->assertSee('TCH-SEB-02')
            ->assertDontSee('DAG-ASS-01'); // le paramètre de zone est ignoré pour un superviseur

        $kpis = $response->viewData('kpis');
        $this->assertSame([2, 3, 1, 1], [$kpis['visited_today'], $kpis['visited'], $kpis['surveyed'], $kpis['refused']]);

        // Binôme 46-47 : total commun (jours travaillés comptés une fois), puis détail de chaque agent.
        $teams = $response->viewData('teams')->keyBy('code');
        $pair = $teams['46-47'];
        $this->assertSame('Imorou & Djibril', $pair->name);
        $this->assertSame([2, 3, 1, 2, 2], [$pair->today, $pair->visited, $pair->surveyed, $pair->days, $pair->clusters]);
        $agents = collect($pair->agents)->keyBy('code');
        $this->assertSame([2, 2], [$agents['46']->today, $agents['46']->visited]);
        $this->assertSame([0, 1], [$agents['47']->today, $agents['47']->visited]);
        $this->assertSame($teams->first(), $pair, 'le binôme le plus actif aujourd\'hui en premier');
        // Les 8 binômes de la zone sont listés, y compris ceux sans aucune visite.
        $this->assertCount(8, $teams);
        $this->assertSame(0, $teams['50-51']->visited);
        $this->assertSame(Collector::where('health_zone_id', $this->tchaourou->id)->count(), $teams->sum(fn ($t) => count($t->agents)));
        // Aujourd'hui : un binôme actif (46-47), dont un seul agent (46).
        $this->assertSame([1, 1], [$response->viewData('kpis')['teams_today'], $response->viewData('kpis')['collectors_today']]);
        $response->assertSee('Binôme 46-47');

        $villages = $response->viewData('villages');
        $this->assertSame(['Sébou'], $villages->pluck('name')->all());
        $this->assertSame(['TCH-SEB-01', 'TCH-SEB-02'], collect($villages[0]->clusters)->pluck('code')->all());
        $this->assertSame(3, $villages[0]->totals['visited']);
    }

    public function test_teams_are_loaded_and_usable_as_a_filter_everywhere(): void
    {
        $this->assertSame(16, Team::count());
        $this->assertSame(0, Collector::whereNull('team_id')->whereIn('code', range(20, 51))->count());
        $team = Team::where('code', '46-47')->firstOrFail();
        $this->assertSame($this->tchaourou->id, $team->health_zone_id);
        $manager = $this->user(User::ROLE_DATA_MANAGER);

        // Filtre « binôme » sur le dashboard : les ménages de ses deux agents seulement.
        $data = $this->actingAs($manager)->getJson(route('dashboard.data', ['team_id' => $team->id]))->assertOk()->json();
        $this->assertSame(3, $data['kpis']['visited']);
        $this->assertSame('Binôme 46-47 · Imorou & Djibril', $data['productivity_teams'][0]['name']);
        $this->assertSame(3, $data['productivity_teams'][0]['interviews']);
        $this->assertSame(2, $data['productivity_teams'][0]['days']);
        $this->assertCount(2, $data['productivity']);

        $options = $this->actingAs($manager)->getJson(route('filters.options', ['health_zone_id' => $this->tchaourou->id, 'team_id' => $team->id]))->json('options');
        $this->assertCount(8, $options['team_id']);
        $this->assertSame(['Djibril, Barakatou', 'Imorou, Kora'], array_column($options['collector_id'], 'name'));

        app(QualityEngine::class)->run('test'); // GPS manquant, etc. : des queries pour chaque ménage
        $queries = $this->actingAs($manager)->getJson(route('queries.dashboard.data', ['team_id' => $team->id]))->assertOk()->json();
        $this->assertSame(['Binôme 46-47 · Imorou & Djibril'], array_column($queries['rankings']['team'], 'name'));
        $this->assertSame(['Binôme 46-47'], array_column($queries['heatmap']['rows'], 'collector'), 'heatmap : les deux agents sur une ligne');
        $this->assertSame(3, $queries['rankings']['team'][0]['households']);
    }

    public function test_other_profiles_choose_the_zone(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);

        $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->actingAs($manager)->get(route('supervision.index', ['health_zone_id' => $this->dagla->id]))->assertOk()
            ->assertSee('Supervision : Dassa-Glazoué')->assertSee('DAG-ASS-01')->assertDontSee('TCH-SEB-01');
    }

    public function test_supervisor_reports_and_resolves_an_incident(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->tchaourou->id]);
        $cluster = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();

        $this->actingAs($supervisor)->get(route('incidents.create'))->assertOk()->assertSee('TCH-SEB-01')->assertDontSee('DAG-ASS-01');
        $this->actingAs($supervisor)->post(route('incidents.store'), [
            'occurred_on' => now()->toDateString(), 'category' => 'materiel', 'severity' => 'elevee',
            'title' => 'Tablette en panne', 'description' => 'La tablette du binôme 46 ne démarre plus.',
            'impact' => '5 ménages non visités', 'cluster_id' => $cluster->id, 'collector_id' => Collector::where('code', '46')->value('id'),
        ])->assertRedirect();

        $incident = FieldIncident::firstOrFail();
        $this->assertSame([$this->tchaourou->id, $cluster->village_id, FieldIncident::STATUS_OPEN, $supervisor->id],
            [$incident->health_zone_id, $incident->village_id, $incident->status, $incident->reported_by]);
        $this->actingAs($supervisor)->get(route('supervision.index'))->assertSee('Tablette en panne');

        // Résolution : commentaire obligatoire ; transition interdite refusée.
        $this->actingAs($supervisor)->post(route('incidents.update', $incident), ['status' => FieldIncident::STATUS_RESOLVED])->assertSessionHasErrors('comment');
        $this->actingAs($supervisor)->post(route('incidents.update', $incident), ['status' => FieldIncident::STATUS_IN_PROGRESS])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('incidents.update', $incident), ['status' => FieldIncident::STATUS_RESOLVED, 'comment' => 'Tablette de rechange remise.'])->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertSame(FieldIncident::STATUS_RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->actingAs($supervisor)->post(route('incidents.update', $incident), ['status' => FieldIncident::STATUS_IN_PROGRESS])->assertSessionHasErrors('status');
        $this->assertSame(3, $incident->updates()->count(), 'signalement, prise en charge, résolution ; le refus n\'ajoute rien');

        $this->actingAs($supervisor)->get(route('incidents.show', $incident))->assertOk()->assertSee('Tablette de rechange remise.');
    }

    public function test_incidents_stay_within_the_supervisor_zone(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->tchaourou->id]);
        $other = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->dagla->id]);

        // Grappe d'une autre zone refusée.
        $this->actingAs($supervisor)->post(route('incidents.store'), [
            'occurred_on' => now()->toDateString(), 'category' => 'acces', 'severity' => 'moyenne', 'title' => 'Route coupée', 'description' => 'Pluie',
            'cluster_id' => Cluster::where('code', 'DAG-ASS-01')->value('id'),
        ])->assertSessionHasErrors('cluster_id');

        $this->actingAs($supervisor)->post(route('incidents.store'), [
            'occurred_on' => now()->toDateString(), 'category' => 'acces', 'severity' => 'moyenne', 'title' => 'Route coupée', 'description' => 'Pluie',
            'village_id' => Village::where('code', '66')->value('id'),
        ])->assertSessionHasNoErrors();
        $incident = FieldIncident::firstOrFail();

        $this->actingAs($other)->get(route('incidents.show', $incident))->assertForbidden();
        $this->actingAs($other)->post(route('incidents.update', $incident), ['comment' => 'x'])->assertForbidden();
        $this->actingAs($other)->get(route('incidents.index'))->assertOk()->assertDontSee('Route coupée');
        $this->actingAs($supervisor)->get(route('incidents.index'))->assertOk()->assertSee('Route coupée');
        // Date future refusée.
        $this->actingAs($supervisor)->post(route('incidents.store'), [
            'occurred_on' => now()->addDay()->toDateString(), 'category' => 'acces', 'severity' => 'moyenne', 'title' => 'x', 'description' => 'x',
        ])->assertSessionHasErrors('occurred_on');
    }

    public function test_readers_can_follow_but_not_report_incidents(): void
    {
        $reader = $this->user(User::ROLE_READER);
        $incident = FieldIncident::create(['health_zone_id' => $this->dagla->id, 'occurred_on' => now(), 'category' => 'reseau', 'severity' => 'faible',
            'title' => 'Pas de réseau', 'description' => 'Synchronisation impossible']);

        $this->actingAs($reader)->get(route('incidents.index'))->assertOk()->assertSee('Pas de réseau')->assertDontSee('Signaler un incident');
        $this->actingAs($reader)->get(route('incidents.show', $incident))->assertOk()->assertDontSee('Enregistrer le suivi');
        // Refus par rôle : retour au dashboard avec un message (comportement commun de l'application).
        $this->actingAs($reader)->get(route('incidents.create'))->assertRedirect(route('dashboard'))->assertSessionHas('danger');
        $this->actingAs($reader)->post(route('incidents.update', $incident), ['comment' => 'x'])->assertRedirect(route('dashboard'));
        $this->assertSame(0, $incident->updates()->count());
    }
}
