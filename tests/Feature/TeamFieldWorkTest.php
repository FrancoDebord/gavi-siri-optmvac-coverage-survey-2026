<?php

namespace Tests\Feature;

use App\Console\Commands\CreateTeamAccountsCommand;
use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\PhotoCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Comptes binômes, queries présentées clairement aux agents, PDF des cartes de grappes. */
class TeamFieldWorkTest extends TestCase
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
        foreach ([['uuid:1', 'TCH-SEB-01-001', '9.31 2.49 0 5'], ['uuid:2', 'TCH-SEB-01-002', '9.3105 2.4905 0 5']] as [$uuid, $code, $gps]) {
            (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
                'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
                'household' => $code, 'household_survey_status' => '0', 'visit_num' => '1', 'current_location' => $gps,
            ], [], []), ['source_type' => 'demo']);
        }
    }

    public function test_one_account_per_team_named_after_its_agents_with_password(): void
    {
        $rows = collect(CreateTeamAccountsCommand::sync());
        $this->assertSame(Team::count(), $rows->count());
        $account = User::where('team_id', $this->team->id)->firstOrFail();
        $this->assertTrue($account->hasRole(User::ROLE_TEAM));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+(\.[a-z0-9-]+)?(\.\d+)?$/', $account->username);
        $this->assertCount($rows->pluck('username')->count(), $rows->pluck('username')->unique());

        // Connexion par identifiant et mot de passe « password » ; changement de mot de passe imposé avant tout.
        $this->assertTrue($account->must_change_password);
        $this->post('/login', ['email' => strtoupper($account->username), 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($account);
        $this->get(route('team.queries'))->assertRedirect(route('profile.edit'));
        $this->get(route('profile.edit'))->assertOk()->assertSee('Première connexion');
        $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors('password', null, 'updatePassword');
        $this->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'Binome-4647', 'password_confirmation' => 'Binome-4647'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->must_change_password);
        $this->get('/')->assertRedirect(route('team.queries'));

        // Relancer ne crée pas de doublon et ne change pas un mot de passe modifié.
        $account->forceFill(['password' => bcrypt('Nouveau-mot-2026')])->save();
        CreateTeamAccountsCommand::sync();
        $this->assertSame(1, User::where('team_id', $this->team->id)->count());
        $this->assertTrue(password_verify('Nouveau-mot-2026', $account->fresh()->password));
    }

    public function test_query_shows_raw_value_photo_suggestion_and_member_uri(): void
    {
        PhotoCheck::create(['submission_uuid' => 'uuid:1', 'instance_path' => null, 'variable' => 'visit_num', 'expected' => '2',
            'message' => 'Numéro de visite différent de la fiche photographiée', 'photo' => 'fiche.jpg']);
        app(QualityEngine::class)->run('test');
        CreateTeamAccountsCommand::sync();
        $account = User::where('team_id', $this->team->id)->firstOrFail();
        $account->update(['must_change_password' => false]);
        $query = DataQuery::where('rule_code', 'PHOTO_CHECK')->firstOrFail();

        $this->actingAs($account)->get(route('team.queries'))->assertOk()
            ->assertSee('Saisi :')->assertSee('1 – Visite 1')->assertSee('Photo :')->assertSee('2 – Visite 2');
        $this->actingAs($account)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Valeur saisie (ODK)')->assertSee('Valeur suggérée')->assertSee('lue sur la photo')->assertSee('URI de la soumission')->assertSee('uuid:1')
            ->assertSee('Pré-rempli avec la valeur lue sur la photo');

        // Le binôme corrige directement : prise en compte immédiate, query levée.
        $this->actingAs($account)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            ['variable' => 'visit_num', 'new_value' => '2', 'reason' => 'carnet'],
        ]])->assertSessionHas('success', "Correction prise en compte : l'erreur est levée.");
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->fresh()->status);
    }

    public function test_data_manager_records_the_value_read_on_the_photo(): void
    {
        app(QualityEngine::class)->run('test');
        CreateTeamAccountsCommand::sync();
        $account = User::where('team_id', $this->team->id)->firstOrFail();
        $account->update(['must_change_password' => false]);
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $query = DataQuery::where('submission_uuid', 'uuid:1')->whereNotNull('variable')->where('variable', 'visit_num')->first()
            ?? DataQuery::where('submission_uuid', 'uuid:1')->whereNotNull('variable')->firstOrFail();
        $query->update(['variable' => 'visit_num']);

        $this->actingAs($account)->post(route('queries.suggest', $query), ['suggested_value' => '2'])->assertForbidden();
        $this->actingAs($manager)->post(route('queries.suggest', $query), ['suggested_value' => '9'])->assertSessionHasErrors('suggested_value');
        $this->actingAs($manager)->post(route('queries.suggest', $query), ['suggested_value' => '2', 'suggested_note' => 'fiche de visite, photo 2'])
            ->assertSessionHas('success');
        $this->assertSame('photo', $query->fresh()->suggested_source);

        $this->actingAs($account)->get(route('team.queries'))->assertSee('Photo :')->assertSee('2 – Visite 2');
        $this->actingAs($account)->get(route('queries.show', $query))->assertOk()
            ->assertSee('lue sur la photo par '.$manager->name)->assertSee('fiche de visite, photo 2')->assertSee('Pré-rempli avec la valeur lue sur la photo');
    }

    public function test_team_cluster_maps_pdf(): void
    {
        $cluster = Cluster::where('code', 'like', '%01%')->firstOrFail();
        $cluster->update(['boundary' => ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => [],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[2.489, 9.309], [2.492, 9.309], [2.492, 9.312], [2.489, 9.312], [2.489, 9.309]]]]]]]]);
        CreateTeamAccountsCommand::sync();
        $account = User::where('team_id', $this->team->id)->firstOrFail();
        $account->update(['must_change_password' => false]);
        // Tuiles satellite simulées (une image JPEG unie).
        $tile = imagecreatetruecolor(256, 256);
        ob_start();
        imagejpeg($tile);
        Http::fake(['server.arcgisonline.com/*' => Http::response(ob_get_clean(), 200, ['Content-Type' => 'image/jpeg'])]);
        File::deleteDirectory(storage_path('app/tiles'));

        $this->actingAs($account)->get(route('team.clusters'))->assertOk()->assertSee(route('team.clusters.pdf'));
        $pdf = $this->actingAs($account)->get(route('team.clusters.pdf'))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.cartes_binome']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'World_Imagery/MapServer/tile/'));
        $this->assertNotEmpty(File::allFiles(storage_path('app/tiles/esri')), 'Tuiles mises en cache');

        // Équipe centrale : PDF de n'importe quel binôme.
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $this->actingAs($manager)->get(route('team.clusters.pdf', ['team_id' => $this->team->id]))->assertOk();
    }
}
