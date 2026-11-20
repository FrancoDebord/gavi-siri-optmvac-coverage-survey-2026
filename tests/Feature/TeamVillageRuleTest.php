<?php

namespace Tests\Feature;

use App\Models\DataQuery;
use App\Models\Team;
use App\Models\User;
use App\Models\Village;
use App\Services\Dashboard\RemainingVillages;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Import\TeamVillageImporter;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Village coché hors des villages du binôme de l'agent (binomes_villages.csv). */
class TeamVillageRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function village(string $name): Village
    {
        return Village::get()->first(fn ($v) => mb_strtolower($v->name) === mb_strtolower($name)) ?? $this->fail("Village {$name} absent");
    }

    private function submit(string $uuid, string $code, Village $village, string $collector, ?string $account = null): void
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
            'today' => now()->toDateString(), 'l1' => $village->healthZone->code, 'l2' => $village->commune->code, 'l3' => $village->arrondissement->code,
            'village' => $village->code, 'fieldworker' => $collector, 'household' => $code, 'household_survey_status' => '3',
        ], [], $account ? ['__system' => ['submitterName' => $account]] : []), ['source_type' => 'demo']);
    }

    public function test_reference_list_is_loaded_for_every_team(): void
    {
        $report = (new TeamVillageImporter)->import();
        $this->assertSame([], $report['unmatched']);
        $this->assertSame(16, $report['teams']);
        $kora = Team::where('code', '46-47')->firstOrFail();
        $this->assertContains('Wari-Maro', $kora->villages->pluck('name')->all());
        $this->assertContains('Kapassa 2', Team::where('code', '38-39')->firstOrFail()->villages->pluck('name')->all(), 'Kpassa 2 de la liste');
    }

    public function test_wrong_village_is_flagged_with_the_village_of_the_household_id(): void
    {
        $wariMaro = $this->village('Wari-Maro');
        $this->submit('uuid:ok', 'TCH-WAR-04-S1-001', $wariMaro, '46');
        $this->submit('uuid:faux', 'TCH-WAR-04-S1-008', $this->village('Bétérou'), '46'); // Kora & Barakatou : Bétérou n'est pas à eux
        $this->submit('uuid:agent', 'DAG-KPE-01-119', $this->village('Kpekoute'), '28'); // Martial & Crédo à Kpekoute (Evrard & Edith)
        app(QualityEngine::class)->run('test');

        $this->assertSame(2, DataQuery::where('rule_code', 'TEAM_VILLAGE')->count());

        $wrong = DataQuery::where('rule_code', 'TEAM_VILLAGE')->where('household_code', 'TCH-WAR-04-S1-008')->firstOrFail();
        $this->assertStringContainsString('Bétérou', $wrong->message);
        $this->assertStringContainsString('désigne Wari-Maro', $wrong->message);
        $this->assertSame($wariMaro->code, $wrong->suggested_value);
        $this->assertSame(DataQuery::SUGGESTION_RULE, $wrong->suggested_source);

        $agent = DataQuery::where('rule_code', 'TEAM_VILLAGE')->where('household_code', 'DAG-KPE-01-119')->firstOrFail();
        $this->assertNull($agent->suggested_value);
        $this->assertStringContainsString('22-23', $agent->message, 'binôme qui couvre Kpekoute');
        $this->assertStringContainsString('agent sélectionné', $agent->suggestion);

        // Fiche : village proposé pré-coché dans la correction.
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('queries.show', $wrong))->assertOk()
            ->assertSee('proposé')
            ->assertSee('id="vil-'.$wariMaro->code.'" value="'.$wariMaro->code.'" checked', false);

        // Village corrigé : la query se ferme au passage suivant du moteur.
        $this->actingAs($admin)->post(route('queries.status', $wrong), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            60 => ['variable' => 'l3', 'new_value' => $wariMaro->arrondissement->code, 'optional' => '1', 'group' => 'geo', 'reason' => 'revisite'],
            61 => ['variable' => 'village', 'new_value' => $wariMaro->code, 'optional' => '1', 'group' => 'geo'],
        ]])->assertSessionHasNoErrors();
        app(QualityEngine::class)->run('test');
        $this->assertTrue(in_array($wrong->fresh()->status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_CORRECTED], true), $wrong->fresh()->status);
    }

    public function test_odk_account_reveals_the_wrongly_selected_agent(): void
    {
        $wariMaro = $this->village('Wari-Maro');
        // Le compte « TCH 05 » est celui de Kora & Barakatou (46-47) : 10 soumissions avec leurs agents.
        foreach (range(1, 10) as $i) {
            $this->submit("uuid:k{$i}", sprintf('TCH-WAR-04-S1-%03d', $i), $wariMaro, '46', 'TCH 05');
        }
        // Même compte, mais agent de Dieudonné Idossou & Grazella (38) sélectionné, à Wari-Maro (village de Kora).
        $this->submit('uuid:x', 'TCH-WAR-04-S1-050', $wariMaro, '38', 'TCH 05');
        app(QualityEngine::class)->run('test');

        $query = DataQuery::where('rule_code', 'TEAM_VILLAGE')->sole();
        $this->assertSame('TCH-WAR-04-S1-050', $query->household_code);
        $this->assertStringStartsWith('Agent mal sélectionné', $query->message);
        $this->assertSame('fieldworker', $query->variable);
        $kora = Team::where('code', '46-47')->firstOrFail();
        $this->assertSame($kora->id, $query->team_id, 'binôme du compte ODK');

        // Toutes les queries de cette soumission vont au binôme qui l'a soumise (compte ODK) : c'est lui qui a collecté
        // et qui s'est trompé d'agent ; le binôme de l'agent sélectionné ne les voit pas.
        $this->assertSame([$kora->id], DataQuery::where('household_code', 'TCH-WAR-04-S1-050')->pluck('team_id')->unique()->values()->all());
        $submitting = User::factory()->create(['team_id' => $kora->id]);
        $submitting->assignRole(User::ROLE_TEAM);
        $this->actingAs($submitting)->get(route('team.queries'))->assertOk()->assertSee('TCH-WAR-04-S1-050');
        $this->actingAs($submitting)->get(route('queries.show', $query))->assertOk();
        $other = User::factory()->create(['team_id' => Team::where('code', '38-39')->value('id')]);
        $other->assignRole(User::ROLE_TEAM);
        $this->actingAs($other)->get(route('team.queries'))->assertOk()->assertDontSee('TCH-WAR-04-S1-050');
        $this->actingAs($other)->get(route('team.village-queries'))->assertOk()->assertDontSee('TCH-WAR-04-S1-050');
        $this->actingAs($other)->get(route('queries.show', $query))->assertForbidden();

        // Page de suivi (admin) : compte, binôme du compte et diagnostic.
        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('team-villages.index'))->assertOk()
            ->assertSee('TCH-WAR-04-S1-050')->assertSee('TCH 05')->assertSee('Agent mal sélectionné')->assertSee('46-47');

        // Recherche par numéro de query, sous toutes ses formes, partout.
        foreach ([$query->code, 'q'.$query->id, (string) $query->id] as $term) {
            $this->actingAs($admin)->getJson(route('queries.datatable', ['draw' => 1, 'search' => $term]))->assertJsonPath('data.0.code', $query->code);
            $this->actingAs($admin)->get(route('team-villages.index', ['q' => $term]))->assertSee('TCH-WAR-04-S1-050');
        }
        $this->actingAs($admin)->get(route('submissions.index', ['q' => $query->code]))->assertSee('TCH-WAR-04-S1-050')->assertDontSee('TCH-WAR-04-S1-001');
        $kora = User::factory()->create(['team_id' => $kora->id]);
        $kora->assignRole(User::ROLE_TEAM);
        $this->actingAs($kora)->get(route('team.queries', ['q' => $query->code]))->assertSee('TCH-WAR-04-S1-050');
        // Soumissions du binôme de l'agent sélectionné (Dieudonné Idossou & Grazella).
        $declared = User::factory()->create(['team_id' => Team::where('code', '38-39')->value('id')]);
        $declared->assignRole(User::ROLE_TEAM);
        $this->actingAs($declared)->get(route('team.submissions', ['q' => $query->code]))->assertSee('TCH-WAR-04-S1-050');
        $this->actingAs($kora)->get(route('team.queries', ['q' => 'Q-999999']))->assertDontSee('TCH-WAR-04-S1-050');

        // Onglet « Villages et agents » du binôme : ses villages et ces queries seulement.
        $this->actingAs($kora)->get(route('team.village-queries'))->assertOk()
            ->assertSee('Villages du binôme 46-47')->assertSee('Wari-Maro')->assertSee('TCH-WAR-04-S1-050')->assertSee('Villages et agents');
        // Villages du binôme : visités (Wari-Maro) ou restants (Kpawa, aucun ménage).
        $this->actingAs($kora)->get(route('team.village-queries'))->assertSee('pas encore visité')->assertSee('village(s) restant(s)')->assertSee('Kpawa');
        // Page admin : villages attribués à chaque binôme, avec son compte ODK.
        $this->actingAs($admin)->get(route('team-villages.index'))
            ->assertSee('Villages de chaque binôme')->assertSee('Kpassatona')->assertSee('Gouroubara');
        // Villages restants : sans ménage visité, avec le binôme concerné ; Wari-Maro (visité) n'y figure pas.
        $remaining = RemainingVillages::build()->flatten(1);
        $this->assertNotNull($remaining->firstWhere('village', 'Kpawa'));
        $this->assertNull($remaining->firstWhere('village', 'Wari-Maro'));
        $this->assertStringContainsString('46-47', implode(' ', $remaining->firstWhere('village', 'Kpawa')['teams']));
        $this->actingAs($admin)->get(route('team-villages.index'))->assertSee('Villages restants');

        // Correction : binômes de Tchaourou à cocher (pas ceux de Dagla), agents avec leur code, agent saisi coché.
        $page = $this->actingAs($admin)->get(route('queries.show', $query))->assertOk()
            ->assertSee('Cochez le bon agent')->assertSee('binôme du compte ODK')
            ->assertSee('name="corrections[62][new_value]" id="agent-46" value="46"', false)
            ->assertSee('id="agent-38" value="38" checked', false)
            ->assertDontSee('id="agent-20"', false); // agent de Dagla
        $this->assertLessThan(strpos($page->getContent(), 'id="agent-38"'), strpos($page->getContent(), 'id="agent-46"'), 'binôme du compte ODK en tête');

        $this->actingAs($admin)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => [
            62 => ['variable' => 'fieldworker', 'new_value' => '46', 'optional' => '1', 'reason' => 'appel'],
        ]])->assertSessionHasNoErrors();
        app(QualityEngine::class)->run('test');
        $this->assertTrue(in_array($query->fresh()->status, [DataQuery::STATUS_VALIDATED, DataQuery::STATUS_CORRECTED], true), $query->fresh()->status);
    }
}
