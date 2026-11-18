<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\DecisionCategory;
use App\Models\FieldDecision;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function category(string $name): int
    {
        return DecisionCategory::where('name', $name)->value('id');
    }

    public function test_default_categories_and_adding_one(): void
    {
        $this->assertSame(
            ['Décisions administratives', 'Décisions de sampling', 'Décisions de collecte', 'Décisions de lecture de carnet', 'Autre'],
            DecisionCategory::orderBy('sort')->pluck('name')->all(),
        );
        $pi = $this->user(User::ROLE_PI);

        $this->actingAs($pi)->post(route('decisions.categories.store'), ['name' => 'Décisions de sécurité', 'color' => 'danger'])->assertSessionHas('success');
        $this->assertSame('Décisions de sécurité', DecisionCategory::orderBy('sort')->pluck('name')->get(4), 'avant « Autre »');
        $this->actingAs($pi)->post(route('decisions.categories.store'), ['name' => 'Décisions de sécurité', 'color' => 'danger'])->assertSessionHasErrors('name');

        $autre = DecisionCategory::where('name', 'Autre')->firstOrFail();
        $this->actingAs($pi)->post(route('decisions.categories.toggle', $autre));
        $this->assertFalse($autre->fresh()->is_active);
        $this->actingAs($pi)->post(route('decisions.store'), [
            'decision_category_id' => $autre->id, 'decided_on' => now()->toDateString(), 'title' => 'x', 'decision' => 'y',
        ])->assertSessionHasErrors('decision_category_id');
    }

    public function test_pi_records_a_decision_and_informs_supervisors(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $tchaourou = HealthZone::where('code', '1')->firstOrFail();
        $sebou = Village::where('short_code', 'SEB')->firstOrFail();

        $this->actingAs($pi)->get(route('decisions.create'))->assertOk()->assertSee('Décisions de lecture de carnet');
        $this->actingAs($pi)->post(route('decisions.store'), [
            'decision_category_id' => $this->category('Décisions de sampling'),
            'decided_on' => now()->toDateString(),
            'effective_from' => now()->addDay()->toDateString(),
            'title' => 'Remplacement des ménages absents',
            'context' => 'Beaucoup de ménages absents à Sébou pendant les récoltes.',
            'decision' => 'Après deux visites sans succès, passer au ménage suivant de la liste.',
            'decided_by' => 'Investigateur principal',
            'village_id' => $sebou->id,
            'notify' => 1,
        ])->assertRedirect();

        $decision = FieldDecision::firstOrFail();
        $this->assertSame('DEC-0001', $decision->code);
        $this->assertSame([$tchaourou->id, FieldDecision::STATUS_ACTIVE, $pi->id], [$decision->health_zone_id, $decision->status, $decision->user_id], 'zone déduite du village');
        $this->assertSame('Tchaourou › Sébou', $decision->scopeLabel());
        $message = TeamMessage::firstOrFail();
        $this->assertSame(['zone', $tchaourou->id, 'information'], [$message->target_type, $message->target_id, $message->kind]);
        $this->assertStringContainsString('DEC-0001', $message->body);

        $this->actingAs($pi)->get(route('decisions.show', $decision))->assertOk()->assertSee('Après deux visites sans succès');
        $this->actingAs($pi)->get(route('decisions.index', ['q' => 'récoltes']))->assertOk()->assertSee('Remplacement des ménages absents');
        $this->actingAs($pi)->get(route('decisions.index', ['q' => 'introuvable']))->assertOk()->assertDontSee('Remplacement des ménages absents');
    }

    public function test_replacing_a_decision_and_scope_consistency(): void
    {
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $old = FieldDecision::create(['decision_category_id' => $this->category('Décisions de lecture de carnet'), 'decided_on' => now()->subDays(3),
            'title' => 'Dates illisibles', 'decision' => 'Saisir 99/99/9999', 'status' => FieldDecision::STATUS_ACTIVE]);

        $this->actingAs($manager)->get(route('decisions.create', ['remplace' => $old->id]))->assertOk()->assertSee('Cette décision remplacera');
        $this->actingAs($manager)->post(route('decisions.store'), [
            'decision_category_id' => $this->category('Décisions de lecture de carnet'), 'decided_on' => now()->toDateString(),
            'title' => 'Dates illisibles (révisé)', 'decision' => 'Cocher « date inconnue »', 'replaces_id' => $old->id,
        ])->assertRedirect();
        $new = FieldDecision::latest('id')->firstOrFail();
        $this->assertSame([FieldDecision::STATUS_REPLACED, $new->id], [$old->fresh()->status, $old->fresh()->replaced_by_id]);

        // Statut « remplacée » sans décision de remplacement : refusé ; grappe d'une autre zone : refusée.
        $payload = ['decision_category_id' => $new->decision_category_id, 'decided_on' => now()->toDateString(), 'title' => 'x', 'decision' => 'y'];
        $this->actingAs($manager)->put(route('decisions.update', $new), $payload + ['status' => FieldDecision::STATUS_REPLACED])->assertSessionHasErrors('replaced_by_id');
        $cluster = Cluster::create(['code' => 'DAG-ASS-01', 'name' => 'Assiyo – grappe 01', 'number' => 1, 'health_zone_id' => HealthZone::where('code', '2')->value('id'),
            'village_id' => Village::where('short_code', 'ASS')->value('id')]);
        $this->actingAs($manager)->post(route('decisions.store'), $payload + ['health_zone_id' => HealthZone::where('code', '1')->value('id'), 'cluster_id' => $cluster->id])
            ->assertSessionHasErrors('village_id');
        $this->actingAs($manager)->put(route('decisions.update', $new), $payload + ['status' => FieldDecision::STATUS_CANCELLED])->assertRedirect();
        $this->assertSame(FieldDecision::STATUS_CANCELLED, $new->fresh()->status);
    }

    public function test_visibility_and_export(): void
    {
        $dagla = HealthZone::where('code', '2')->firstOrFail();
        $category = $this->category('Décisions de collecte');
        FieldDecision::create(['decision_category_id' => $category, 'decided_on' => now(), 'title' => 'Décision générale', 'decision' => 'Pour tous', 'status' => 'en_vigueur']);
        FieldDecision::create(['decision_category_id' => $category, 'decided_on' => now(), 'title' => 'Décision Dagla', 'decision' => 'Pour Dagla', 'health_zone_id' => $dagla->id, 'status' => 'en_vigueur']);

        $supTch = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '1')->value('id')]);
        $this->actingAs($supTch)->get(route('decisions.index'))->assertOk()->assertSee('Décision générale')->assertDontSee('Décision Dagla')->assertDontSee('Nouvelle décision');
        $this->actingAs($supTch)->get(route('decisions.show', FieldDecision::where('title', 'Décision Dagla')->value('id')))->assertForbidden();
        $this->actingAs($supTch)->get(route('decisions.create'))->assertRedirect(route('supervision.index'));

        $team = $this->user(User::ROLE_TEAM, ['team_id' => Team::where('code', '46-47')->value('id')]);
        $this->actingAs($team)->get(route('decisions.index'))->assertRedirect(route('team.queries'));

        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('decisions.export', ['health_zone_id' => 'global']))->assertOk()->assertDownload('decisions-terrain-'.now()->format('Ymd').'.xlsx');
    }

    public function test_only_admin_can_delete_a_decision_and_replaced_ones_come_back_in_force(): void
    {
        $category = $this->category('Décisions de collecte');
        $old = FieldDecision::create(['decision_category_id' => $category, 'decided_on' => now()->subDay(), 'title' => 'Ancienne', 'decision' => 'a', 'status' => FieldDecision::STATUS_ACTIVE]);
        $new = FieldDecision::create(['decision_category_id' => $category, 'decided_on' => now(), 'title' => 'Nouvelle', 'decision' => 'b', 'status' => FieldDecision::STATUS_ACTIVE]);
        $old->update(['status' => FieldDecision::STATUS_REPLACED, 'replaced_by_id' => $new->id]);

        // PI et data manager gèrent les décisions mais ne peuvent pas les supprimer.
        foreach ([User::ROLE_PI, User::ROLE_DATA_MANAGER] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('decisions.show', $new))->assertOk()->assertDontSee('bi-trash me-1"></i>Supprimer', false);
            $this->actingAs($user)->delete(route('decisions.destroy', $new))->assertRedirect($user->homeRoute());
        }
        $this->assertModelExists($new);

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('decisions.show', $new))->assertOk()->assertSee('bi-trash me-1"></i>Supprimer', false);
        $this->actingAs($admin)->delete(route('decisions.destroy', $new))
            ->assertRedirect(route('decisions.index'))->assertSessionHas('success');

        $this->assertModelMissing($new);
        $this->assertSame(FieldDecision::STATUS_ACTIVE, $old->fresh()->status);
        $this->assertNull($old->fresh()->replaced_by_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'decision.deleted', 'subject_id' => $new->id, 'user_id' => $admin->id]);
    }

    public function test_confidential_decisions_are_visible_to_admins_only(): void
    {
        $category = $this->category('Décisions de collecte');
        $payload = ['decision_category_id' => $category, 'decided_on' => now()->toDateString(), 'title' => 'Décision secrète', 'decision' => 'Contenu réservé'];
        $admin = $this->user(User::ROLE_ADMIN);
        $pi = $this->user(User::ROLE_PI);
        $manager = $this->user(User::ROLE_DATA_MANAGER);
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => HealthZone::where('code', '1')->value('id')]);

        // Seul l'administrateur voit et utilise la case « Confidentielle » ; la valeur envoyée par un autre profil est ignorée.
        $this->actingAs($admin)->get(route('decisions.create'))->assertOk()->assertSee('name="is_confidential"', false);
        $this->actingAs($pi)->get(route('decisions.create'))->assertOk()->assertDontSee('name="is_confidential"', false);
        $this->actingAs($pi)->post(route('decisions.store'), ['title' => 'Tentative PI', 'is_confidential' => 1] + $payload)->assertRedirect();
        $this->assertFalse(FieldDecision::where('title', 'Tentative PI')->firstOrFail()->is_confidential);

        // Décision confidentielle : jamais diffusée aux superviseurs, même si demandé.
        $this->actingAs($admin)->post(route('decisions.store'), $payload + ['is_confidential' => 1, 'notify' => 1])->assertRedirect();
        $secret = FieldDecision::where('title', 'Décision secrète')->firstOrFail();
        $this->assertTrue($secret->is_confidential);
        $this->assertFalse(TeamMessage::where('body', 'like', '%Décision secrète%')->exists());
        $this->actingAs($admin)->get(route('decisions.index'))->assertSee('Décision secrète')->assertSee('Confidentielle');
        $this->actingAs($admin)->get(route('decisions.show', $secret))->assertOk();

        // Autres profils : ni dans le registre, ni en accès direct, ni modifiable, ni dans l'export ou le rapport de synthèse.
        foreach ([$pi, $manager, $supervisor] as $user) {
            $this->actingAs($user)->get(route('decisions.index'))->assertOk()->assertDontSee('Décision secrète');
            $this->actingAs($user)->get(route('decisions.show', $secret))->assertForbidden();
        }
        $this->actingAs($pi)->get(route('decisions.edit', $secret))->assertForbidden();
        $this->actingAs($pi)->put(route('decisions.update', $secret), $payload + ['status' => FieldDecision::STATUS_CANCELLED])->assertForbidden();
        $this->actingAs($pi)->post(route('decisions.summary.toggle', $secret))->assertForbidden();
        $this->assertSame(FieldDecision::STATUS_ACTIVE, $secret->fresh()->status);
        $this->actingAs($pi)->post(route('decisions.store'), ['title' => 'Remplaçante', 'replaces_id' => $secret->id] + $payload)->assertSessionHasErrors('replaces_id');
        $this->actingAs($pi)->get(route('investigator.summary'))->assertOk()->assertDontSee('Décision secrète');
        $this->actingAs($admin)->get(route('investigator.summary'))->assertOk()->assertDontSee('Décision secrète');

        // Une décision visible remplacée par une décision confidentielle ne révèle pas le titre de celle-ci.
        $public = FieldDecision::create(['decision_category_id' => $category, 'decided_on' => now(), 'title' => 'Décision publique', 'decision' => 'x',
            'status' => FieldDecision::STATUS_REPLACED, 'replaced_by_id' => $secret->id]);
        $this->actingAs($pi)->get(route('decisions.show', $public))->assertOk()->assertDontSee('Décision secrète');
        $this->actingAs($pi)->get(route('decisions.index'))->assertSee('Décision publique')->assertDontSee('Décision secrète');
    }
}
