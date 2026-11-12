<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\DataQuery;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\User;
use App\Models\Village;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
    }

    private function submit(string $uuid, string $household, string $village, array $extra = []): Household
    {
        (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
            'today' => '2026-11-06', 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => $village, 'fieldworker' => '46',
            'household' => $household, 'household_survey_status' => '3',
        ] + $extra, [], []), ['source_type' => 'demo']);

        return Household::where('submission_uuid', $uuid)->firstOrFail();
    }

    public function test_village_codes_are_loaded_from_the_workbook(): void
    {
        $this->assertSame(100, Village::whereNotNull('short_code')->count());
        $this->assertSame('Sébou', Village::where('short_code', 'SEB')->value('name'));
        // « KPASSA 2 » dans le classeur, « Kapassa 2 » dans village.csv ; « Kpassa 1 » garde son propre code.
        $this->assertSame('Kapassa 2', Village::where('short_code', 'KAP')->value('name'));
        $this->assertSame('Kpassa 1', Village::where('short_code', 'KSA')->value('name'));
        $this->assertSame(0, Cluster::count(), 'plus de grappe « une par village »');
    }

    public function test_cluster_and_sub_cluster_come_from_the_household_code(): void
    {
        $first = $this->submit('uuid:1', 'TCH-SEB-01-001', '66', ['selected_sub_circuit' => 2]);
        $second = $this->submit('uuid:2', 'tch-seb-01-002', '66');
        $other = $this->submit('uuid:3', 'TCH-SEB-02-001', '66');

        $cluster = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $this->assertSame([$cluster->id, $cluster->id], [$first->cluster_id, $second->cluster_id]);
        $this->assertSame(1, $cluster->number);
        $this->assertSame('Sébou – grappe 01', $cluster->name);
        $this->assertSame(Village::where('short_code', 'SEB')->value('id'), $cluster->village_id);
        $this->assertSame(2, $first->sub_cluster);
        $this->assertNull($second->sub_cluster);
        $this->assertNotSame($cluster->id, $other->cluster_id);
    }

    public function test_sub_cluster_from_code_takes_precedence_over_the_form(): void
    {
        $withS = $this->submit('uuid:1', 'TCH-SEB-01-S2-001', '66', ['selected_sub_circuit' => 1]);
        $suffix = $this->submit('uuid:2', 'TCH-SEB-01-002-S3', '66');
        $plain = $this->submit('uuid:3', 'TCH-SEB-01-003', '66', ['selected_sub_circuit' => 1]);

        $this->assertSame([2, 3, 1], [$withS->sub_cluster, $suffix->sub_cluster, $plain->sub_cluster]);
        $this->assertSame(1, Cluster::count(), 'la composante S ne crée pas de grappe distincte');
        $this->assertSame($plain->cluster_id, $withS->cluster_id);
    }

    public function test_cluster_village_follows_the_code_even_if_the_declared_village_differs(): void
    {
        $household = $this->submit('uuid:1', 'TCH-SEB-01-001', '19');

        $this->assertSame((int) Village::where('code', '19')->value('id'), $household->village_id);
        $this->assertSame(Village::where('short_code', 'SEB')->value('id'), $household->cluster->village_id);
    }

    public function test_household_code_rule_flags_non_compliant_ids(): void
    {
        $this->seed(QualityRuleSeeder::class);
        $this->submit('uuid:ok', 'TCH-SEB-01-S1-001', '66', ['selected_sub_circuit' => 1]);
        $this->submit('uuid:format', 'DAG-HOI-01(S1)-002-01', '66');
        $this->submit('uuid:village', 'TCH-XYZ-01-001', '66');
        $this->submit('uuid:zone', 'DAG-ASS-01-001', '66');
        $this->submit('uuid:sub', 'TCH-SEB-01-S2-002', '66', ['selected_sub_circuit' => 1]);

        app(QualityEngine::class)->run('test');

        $messages = DataQuery::where('rule_code', 'HH_CODE')->pluck('message', 'submission_uuid');
        $this->assertArrayNotHasKey('uuid:ok', $messages->all());
        $this->assertStringContainsString('non conforme', $messages['uuid:format']);
        $this->assertStringContainsString('code village « XYZ » inconnu', $messages['uuid:village']);
        $this->assertStringContainsString('ne correspond pas à la zone sanitaire déclarée', $messages['uuid:zone']);
        $this->assertStringContainsString('sous-grappe S2 alors que la sous-grappe sélectionnée dans le formulaire est 1', $messages['uuid:sub']);
    }

    public function test_unparsable_code_falls_back_to_the_village(): void
    {
        $household = $this->submit('uuid:1', 'HH-A', '66');

        $this->assertSame('66', $household->cluster->code);
    }

    public function test_cluster_page_and_dashboard_link(): void
    {
        $this->submit('uuid:1', 'TCH-SEB-01-001', '66', ['selected_sub_circuit' => 1, 'current_location' => '9.31 2.49 0 5']);
        $cluster = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $cluster->update(['boundary' => ['type' => 'Polygon', 'coordinates' => [[[2.48, 9.30], [2.50, 9.30], [2.50, 9.32], [2.48, 9.30]]]]]);
        $cluster->subClusters()->create(['number' => 1, 'boundary' => ['type' => 'Polygon', 'coordinates' => [[[2.48, 9.30], [2.49, 9.30], [2.49, 9.31], [2.48, 9.30]]]]]);

        $admin = User::factory()->create();
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('clusters.show', $cluster))
            ->assertOk()->assertSee('Grappe TCH-SEB-01')->assertSee('TCH-SEB-01-001')->assertSee('Aucun enfant de moins de 5 ans');

        $data = $this->actingAs($admin)->getJson(route('dashboard.data'))->assertOk()->json();
        $row = collect($data['hierarchy'])->firstWhere('level', 3);
        $this->assertSame('Grappe 01 (TCH-SEB-01)', $row['label']);
        $this->assertSame(route('clusters.show', $cluster), $row['url']);
        $this->assertSame('TCH-SEB-01', $data['boundaries'][0]['code']);
        $this->assertSame(1, $data['boundaries'][0]['sub_clusters'][0]['number']);

        $supervisor = User::factory()->create(['health_zone_id' => HealthZone::where('code', '2')->value('id')]);
        $supervisor->assignRole(User::ROLE_SUPERVISOR);
        $this->actingAs($supervisor)->get(route('clusters.show', $cluster))->assertForbidden();
    }
}
