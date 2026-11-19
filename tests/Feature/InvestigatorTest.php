<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\ClusterFrame;
use App\Models\DataQuery;
use App\Models\DecisionCategory;
use App\Models\FieldDecision;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use App\Models\Village;
use App\Services\Dashboard\ClusterSelection;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SamplingFrameImporter;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InvestigatorTest extends TestCase
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
        foreach ([
            ['uuid:1', 'TCH-SEB-01-001', '1', '1', '5', '66', '46', '0', now()->subDay()->toDateString(), '9.31 2.49 0 5'],
            ['uuid:2', 'TCH-SEB-01-002', '1', '1', '5', '66', '47', '0', now()->toDateString(), '9.32 2.49 0 5'],
            ['uuid:3', 'TCH-SEB-01-003', '1', '1', '5', '66', '47', '3', now()->toDateString(), null],
            ['uuid:4', 'DAG-ASS-01-001', '2', '2', '13', '57', '33', '0', now()->toDateString(), '7.92 2.18 0 5'],
        ] as [$uuid, $code, $zone, $commune, $arr, $village, $collector, $status, $date, $gps]) {
            $processor->process(new NormalizedSubmission($uuid, array_filter([
                'today' => $date, 'l1' => $zone, 'l2' => $commune, 'l3' => $arr, 'village' => $village, 'fieldworker' => $collector,
                'household' => $code, 'household_survey_status' => $status, 'current_location' => $gps,
            ], fn ($value) => $value !== null), [], []), ['source_type' => 'demo']);
        }
        app(QualityEngine::class)->run('test');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_pi_lands_on_overview_with_charts(): void
    {
        $pi = $this->user(User::ROLE_PI);

        $this->actingAs($pi)->get(route('dashboard'))->assertRedirect(route('investigator.index'));
        $this->actingAs($pi)->get(route('dashboard', ['complet' => 1]))->assertOk();

        $response = $this->actingAs($pi)->get(route('investigator.index'))->assertOk()
            ->assertSee('Point journalier par binôme')->assertSee('Point général des queries');
        $charts = $response->viewData('charts');

        $this->assertCount(2, $charts['teams']['days']);
        $pair = collect($charts['teams']['series'])->firstWhere('label', 'Binôme 46-47 · Imorou & Djibril');
        $this->assertSame([1, 2], $pair['visited'], 'hier 1 ménage (agent 46), aujourd\'hui 2 (agent 47)');
        $this->assertSame([1, 1], $pair['surveyed']);
        $this->assertSame([1, 2], collect($charts['zones']['series'])->firstWhere('label', 'Tchaourou')['values'], 'cumul des enquêtés');
        $this->assertSame([0, 1], collect($charts['villages']['series'])->firstWhere('label', 'Assiyo')['values']);
        $this->assertCount(3, $charts['points'], 'ménages géolocalisés seulement');
        $this->assertSame([true, true, true], array_column($charts['points'], 'consented'));

        // Filtre par zone.
        $zone = $this->actingAs($pi)->get(route('investigator.index', ['health_zone_id' => $this->dagla->id]))->assertOk()->viewData('charts');
        $this->assertSame(['Dassa-Glazoué'], array_column($zone['zones']['series'], 'label'));
    }

    public function test_pi_menu_keeps_only_the_essentials(): void
    {
        $html = $this->actingAs($this->user(User::ROLE_PI))->get(route('investigator.index'))->assertOk()->getContent();
        preg_match('/<nav class="app-sidebar".*?<\/nav>/s', $html, $menu);
        preg_match_all('/<span class="label">\s*([^<]+?)\s*(?:<|$)/u', $menu[0], $labels);

        $this->assertSame(
            ['GAVI SIRI · Round 2', 'Vue d\'ensemble', 'Progression', 'Rapport de synthèse', 'Grappes par village', 'Carte des grappes', 'Soumissions', 'Queries', 'Décisions de terrain', 'Messages aux équipes', 'Incidents terrain', 'Documentation'],
            array_map('trim', $labels[1]),
        );
        $this->assertStringNotContainsString(route('supervision.index'), $menu[0]);
        $this->assertStringNotContainsString(route('team.space'), $menu[0]);

        // L'administrateur garde le menu complet.
        $admin = $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('investigator.index'))->getContent();
        $this->assertStringContainsString('Espace des binômes', $admin);
        $this->assertStringContainsString('Espace superviseur', $admin);
    }

    public function test_pi_sees_query_details_but_does_not_process_them(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $query = DataQuery::firstOrFail();

        $this->actingAs($pi)->get(route('queries.dashboard'))->assertOk();
        $this->actingAs($pi)->get(route('queries.show', $query))->assertOk()->assertSee($query->code);
        $this->actingAs($pi)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'comment' => 'x'])->assertSessionHasErrors('status');
        $this->actingAs($pi)->post(route('queries.assign', $query), ['assigned_to' => $pi->id])->assertForbidden();
        $this->assertSame(DataQuery::STATUS_OPEN, $query->fresh()->status);
    }

    public function test_collection_report_by_level(): void
    {
        $pi = $this->user(User::ROLE_PI);

        foreach ([
            ['niveau' => 'tous'],
            ['niveau' => 'zone', 'health_zone_id' => $this->tchaourou->id],
            ['niveau' => 'village', 'village_id' => Village::where('code', '66')->value('id')],
        ] as $params) {
            $response = $this->actingAs($pi)->get(route('investigator.report', $params))->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), json_encode($params));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
        $this->actingAs($pi)->get(route('investigator.report', ['niveau' => 'village']))->assertSessionHasErrors('village_id');

        $reader = $this->user(User::ROLE_READER);
        $this->actingAs($reader)->get(route('investigator.index'))->assertRedirect(route('dashboard'));
    }

    public function test_summary_report_by_zone_team_and_date_with_decisions(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $category = DecisionCategory::create(['name' => 'Collecte', 'color' => 'primary']);
        FieldDecision::create(['decision_category_id' => $category->id, 'decided_on' => now(), 'title' => 'Revisite des absents', 'decision' => 'Repasser le soir.',
            'decided_by' => 'PI', 'health_zone_id' => $this->tchaourou->id, 'status' => FieldDecision::STATUS_ACTIVE]);
        FieldDecision::create(['decision_category_id' => $category->id, 'decided_on' => now(), 'title' => 'Règle Dassa', 'decision' => 'x',
            'decided_by' => 'PI', 'health_zone_id' => $this->dagla->id, 'status' => FieldDecision::STATUS_ACTIVE]);

        $response = $this->actingAs($pi)->get(route('investigator.summary'))->assertOk()
            ->assertSee('Chiffres clés par zone sanitaire, par binôme et par date de collecte')->assertSee('Revisite des absents');
        $report = $response->viewData('report');
        $this->assertSame(['villages' => 2, 'visited' => 4, 'surveyed' => 3, 'children' => 0], $report['total']);

        $tch = collect($report['zones'])->firstWhere('name', 'Tchaourou');
        $this->assertSame(['villages' => 1, 'visited' => 3, 'surveyed' => 2, 'children' => 0], $tch['kpis']);
        $team = collect($tch['teams'])->firstWhere('label', 'Binôme 46-47 · Imorou & Djibril');
        $this->assertSame(3, $team['kpis']['visited']);
        $this->assertSame([now()->subDay()->format('d/m/Y'), now()->format('d/m/Y')], array_column($team['days'], 'label'));
        $this->assertSame([1, 2], array_map(fn ($d) => $d['kpis']['visited'], $team['days']));
        $this->assertSame([1, 1], array_map(fn ($d) => $d['kpis']['villages'], $team['days']));

        // Par zone et village.
        $this->assertCount(1, $tch['villages']);
        $sebou = $tch['villages'][0];
        $this->assertSame(Village::where('code', '66')->value('name'), $sebou['name']);
        $this->assertSame(['villages' => 1, 'visited' => 3, 'surveyed' => 2, 'children' => 0], $sebou['kpis']);
        $this->assertArrayNotHasKey('clusters', $sebou);
        $response->assertSee('Chiffres clés par zone sanitaire et par village')->assertDontSee('par village et par grappe');

        // Date du rapport bien visible : date d'édition, période couverte, dates de collecte.
        $response->assertSee('Situation au')->assertSee(now(config('app.timezone'))->translatedFormat('j F Y'))
            ->assertSee('Depuis le début de la collecte')
            ->assertSee('du '.now()->subDay()->format('d/m/Y').' au '.now()->format('d/m/Y'))->assertSee('2 jours de collecte');

        // Filtre zone + période : décisions générales et de la zone seulement.
        $zone = $this->actingAs($pi)->get(route('investigator.summary', ['health_zone_id' => $this->tchaourou->id, 'du' => now()->toDateString()]))->assertOk();
        $this->assertSame(2, $zone->viewData('report')['total']['visited']);
        $this->assertSame(['Revisite des absents'], $zone->viewData('decisions')->pluck('title')->all());
        $zone->assertSee('Du '.now()->format('d/m/Y').' au '.now()->format('d/m/Y'))->assertSee('Zone sanitaire de Tchaourou')->assertSee('1 jour de collecte');

        $pdf = $this->actingAs($pi)->get(route('investigator.summary.pdf', ['health_zone_id' => $this->tchaourou->id]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Décision masquée : absente du rapport (web et PDF), listée à part, réaffichable.
        $hidden = FieldDecision::create(['decision_category_id' => $category->id, 'decided_on' => now(), 'title' => 'Détail interne', 'decision' => 'y',
            'decided_by' => 'PI', 'status' => FieldDecision::STATUS_ACTIVE]);
        $this->assertTrue($hidden->fresh()->in_summary, 'affichée par défaut');
        $this->actingAs($pi)->post(route('decisions.summary.toggle', $hidden))->assertRedirect();
        $this->assertFalse($hidden->fresh()->in_summary);
        $page = $this->actingAs($pi)->get(route('investigator.summary'))->assertOk()->assertSee('1 décision(s) masquée(s)');
        $this->assertNotContains('Détail interne', $page->viewData('decisions')->pluck('title')->all());
        $this->assertSame(['Détail interne'], $page->viewData('hiddenDecisions')->pluck('title')->all());
        $this->actingAs($pi)->put(route('decisions.update', $hidden), ['decision_category_id' => $category->id, 'decided_on' => now()->toDateString(),
            'title' => 'Détail interne', 'decision' => 'y', 'status' => FieldDecision::STATUS_ACTIVE, 'in_summary' => '1'])->assertRedirect();
        $this->assertTrue($hidden->fresh()->in_summary, 'réaffichée depuis le formulaire');
        $this->actingAs($this->user(User::ROLE_READER))->post(route('decisions.summary.toggle', $hidden));
        $this->assertTrue($hidden->fresh()->in_summary, 'lecteur : pas de modification');

        // Accès permanent : menu latéral et fenêtre « Rapports (PDF) » de la vue d'ensemble.
        $this->actingAs($pi)->get(route('investigator.index'))->assertOk()
            ->assertSee(route('investigator.summary'), false)->assertSee('value="synthese"', false);
        $this->actingAs($pi)->get(route('investigator.report', ['type' => 'synthese', 'health_zone_id' => $this->tchaourou->id]))
            ->assertRedirect(route('investigator.summary.pdf', ['health_zone_id' => $this->tchaourou->id]));

        $this->actingAs($this->user(User::ROLE_READER))->get(route('investigator.summary'))->assertRedirect(route('dashboard'));
    }

    public function test_clusters_by_village_page_and_pdf(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $cluster = Cluster::whereHas('households', fn ($q) => $q->where('household_code', 'like', 'TCH-%'))->firstOrFail();
        // Réponse 6a : sub_circuits = 3, sauf une soumission discordante (4). Sous-grappe lue dans l'identifiant :
        // S2 pour le premier ménage, aucune pour les deux autres (NA), même si le formulaire en déclare une.
        Household::where('cluster_id', $cluster->id)->orderBy('id')->get()->each(function (Household $h, int $i) {
            $h->update([
                'household_code' => $i === 0 ? 'TCH-SEB-01-S2-001' : $h->household_code,
                'data' => array_merge($h->data, ['sub_circuits' => $i === 2 ? '4' : '3', 'selected_sub_circuit' => '1']),
            ]);
        });

        $report = $this->actingAs($pi)->get(route('investigator.clusters', ['health_zone_id' => $this->tchaourou->id]))->assertOk()
            ->assertSee('Grappes par village')->viewData('report');
        $this->assertCount(1, $report['zones']);
        $row = collect($report['zones'][0]['villages'])->flatMap(fn ($v) => $v['rows'])->firstWhere('cluster_id', $cluster->id);
        $this->assertSame([3, 4], $row['sub_clusters']);
        $this->assertSame([1], $row['cluster_numbers']);
        $this->assertSame([2, 'NA'], $row['selected']);
        $this->assertSame(4, $report['zones'][0]['totals']['sub_clusters']);
        $this->assertSame(3, $row['visited']);
        $this->assertSame(2, $row['surveyed']);
        $this->assertSame(3, $report['totals']['visited']);

        $all = $this->actingAs($pi)->get(route('investigator.clusters'))->assertOk()->viewData('report');
        $this->assertSame(4, $all['totals']['visited']);
        $this->assertCount(2, $all['zones']);

        $pdf = $this->actingAs($pi)->get(route('investigator.clusters.pdf', ['health_zone_id' => $this->tchaourou->id]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->actingAs($pi)->get(route('investigator.clusters.xlsx', ['health_zone_id' => $this->tchaourou->id]))->assertOk();
        $xlsx->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false);
        @unlink($path);
        $this->assertSame('Sous-grappes créées', $sheet[0][6]);
        $line = collect($sheet)->firstWhere(3, $cluster->code);
        $this->assertSame([1, '3, 4', '2, NA', 3, 2], [$line[2], $line[6], $line[7], $line[9], $line[12]]);

        $this->actingAs($this->user(User::ROLE_TEAM))->get(route('investigator.clusters'))->assertRedirect();
    }

    public function test_sampling_frame_gives_expected_households_per_cluster(): void
    {
        $report = (new SamplingFrameImporter)->import();
        $this->assertSame(404, $report['clusters']);
        $sebou = Village::where('code', '66')->firstOrFail();
        $frame = ClusterFrame::where('village_label', 'SEBOU')->where('cluster_number', 1)->firstOrFail();
        $this->assertContains($sebou->id, $frame->village_ids);
        $this->assertSame(288, $frame->households_enumerated);
        $this->assertSame(96, $frame->expected()); // 288 ménages ÷ 3 subdivisions

        // Correspondances confirmées par l'équipe (écritures différentes entre la liste et la plateforme).
        $dagla = HealthZone::where('code', '2')->firstOrFail();
        foreach (['Vêdji' => 'VEDJI', 'EROKOYA (ZANKOUMADON)' => 'ZANKOUMADON', 'Itchogué Sotré' => 'ITCHOGUESOTRE', 'FITA' => 'FITA'] as $label => $platform) {
            $village = Village::where('health_zone_id', $dagla->id)->get()->first(fn ($v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($v->name))) === $platform);
            $this->assertNotNull($village, "Village {$platform} absent du référentiel");
            $this->assertSame([$village->id], ClusterFrame::where('village_label', $label)->firstOrFail()->village_ids, "{$label} → {$village->name}");
        }
        $this->assertSame([], $report['unmatched'] ?? []);

        $pi = $this->user(User::ROLE_PI);
        $report = $this->actingAs($pi)->get(route('investigator.clusters', ['health_zone_id' => $this->tchaourou->id]))->assertOk()
            ->assertSee('Ménages dénombrés')->assertSee('Ménages attendus')->assertDontSee('non visitée')->viewData('report');
        $rows = collect($report['zones'][0]['villages'])->flatMap(fn ($v) => $v['rows']);
        $visited = $rows->firstWhere('cluster_code', 'TCH-SEB-01');
        $this->assertSame([288, 3, 96, 3, -93, 3], [$visited['enumerated'], $visited['subdivisions'], $visited['expected'], $visited['visited'], $visited['gap'], $visited['rate']]);
        // Seule la grappe tirée (identifiée par l'identifiant ménage) est confrontée ; les autres grappes de la liste sont laissées.
        $this->assertFalse($rows->contains(fn ($r) => $r['enumerated'] === 284));
        $this->assertSame(1, $report['zones'][0]['totals']['frame_clusters']);
        $this->assertSame(96, $report['zones'][0]['totals']['expected']);

        // Binôme : seulement ses villages.
        $team = Team::where('code', '46-47')->firstOrFail();
        $selection = (new ClusterSelection(null, $team->collectors()->pluck('id')->all()))->build();
        $villages = collect($selection['zones'])->flatMap(fn ($z) => collect($z['villages'])->pluck('name'))->unique()->values()->all();
        $this->assertSame(['Sébou'], array_map(fn ($v) => Str::ascii($v) === 'Sebou' ? 'Sébou' : $v, $villages));
    }

    public function test_messages_reach_the_right_supervisors(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $supTch = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->tchaourou->id]);
        $supDag = $this->user(User::ROLE_SUPERVISOR, ['health_zone_id' => $this->dagla->id]);
        $team = Team::where('code', '46-47')->firstOrFail();

        $this->actingAs($pi)->post(route('messages.store'), ['kind' => 'encouragement', 'recipient' => 'team:'.$team->id, 'body' => 'Bravo au binôme 46-47 !'])
            ->assertRedirect(route('messages.index'));
        $this->actingAs($pi)->post(route('messages.store'), ['kind' => 'avertissement', 'recipient' => 'supervisor:'.$supDag->id, 'body' => 'Trop de queries à Assiyo.']);
        $this->actingAs($pi)->post(route('messages.store'), ['kind' => 'information', 'recipient' => 'all:', 'body' => 'Réunion vendredi.']);
        $this->assertSame($this->tchaourou->id, TeamMessage::where('target_type', 'team')->value('health_zone_id'));

        // Superviseur de Tchaourou : message du binôme et message général, pas celui de Dagla.
        $this->actingAs($supTch)->get(route('supervision.index'))->assertOk()->assertSee('Bravo au binôme 46-47 !')->assertDontSee('Trop de queries');
        $this->actingAs($supTch)->get(route('messages.index'))->assertOk()->assertSee('Réunion vendredi.')->assertDontSee('Trop de queries');
        $this->actingAs($supDag)->get(route('messages.index'))->assertOk()->assertSee('Trop de queries')->assertDontSee('Bravo au binôme');

        // Lecture : un message, puis le reste ; un message d'une autre zone est refusé.
        $teamMessage = TeamMessage::where('target_type', 'team')->firstOrFail();
        $this->actingAs($supTch)->post(route('messages.read', $teamMessage))->assertRedirect();
        $this->assertSame(1, TeamMessage::visibleTo($supTch)->unreadBy($supTch)->count());
        $this->actingAs($supTch)->post(route('messages.read-all'));
        $this->assertSame(0, TeamMessage::visibleTo($supTch)->unreadBy($supTch)->count());
        $this->actingAs($supTch)->get(route('supervision.index'))->assertDontSee('Bravo au binôme 46-47 !');
        $this->actingAs($supTch)->post(route('messages.read', TeamMessage::where('target_type', 'supervisor')->firstOrFail()))->assertForbidden();

        $this->actingAs($pi)->get(route('messages.index'))->assertOk()->assertSee('Lu par 1 superviseur(s)');

        // Seuls l'investigateur et l'administrateur écrivent.
        $this->actingAs($supTch)->post(route('messages.store'), ['kind' => 'information', 'recipient' => 'all:', 'body' => 'x'])->assertRedirect(route('supervision.index'));
        $this->actingAs($pi)->post(route('messages.store'), ['kind' => 'information', 'recipient' => 'team:999', 'body' => 'x'])->assertSessionHasErrors('target_id');
        $this->assertSame(3, TeamMessage::count());
    }
}
