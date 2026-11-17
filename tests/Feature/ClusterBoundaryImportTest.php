<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\SubCluster;
use App\Models\User;
use App\Services\Import\ClusterBoundaryImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClusterBoundaryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $settings;

    private const HEADER = "Point:, Latitude:, Longitude:, Altitude: (m.), E: (m.), N: (m.), Z: (m.), Height (MSL): (m.), \n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();

        // Dossier de paramètres temporaire pour les relevés GPS et la compilation.
        $this->settings = storage_path('framework/testing/settings-'.uniqid());
        File::ensureDirectoryExists($this->settings.'/points_gps');
        config(['gavi.settings_path' => $this->settings]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->settings);
        parent::tearDown();
    }

    /** Carré d'environ 220 m de côté autour de (lat, lng), points nommés $prefix-Pn. */
    private function square(string $prefix, float $lat, float $lng, float $size = 0.002): string
    {
        $corners = [[$lat, $lng], [$lat, $lng + $size], [$lat + $size, $lng + $size], [$lat + $size, $lng]];
        $lines = '';
        foreach ($corners as $i => [$y, $x]) {
            $lines .= sprintf("%s-P%d, %.8f, %.8f, 200.00, 0, 0, 200, 175\n", $prefix, $i + 1, $y, $x);
        }

        return $lines;
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->settings.'/points_gps/'.$file, self::HEADER.$content);
    }

    public function test_imports_clusters_sub_clusters_and_hamlets(): void
    {
        // Assiyo (DAG-ASS) : deux hameaux, points nommés avec des « _ ».
        $this->write('DAG-ASS-01-06-11-2026.csv', str_replace('-', '_', $this->square('DAG-ASS-01-H1', 7.570, 2.282)).$this->square('DAG-ASS-01-H2', 7.580, 2.290));
        // Bétérou : sous-grappe 2, points mal nommés (grappe 01, S sans numéro).
        $this->write('TCH-BEE-03-S2-07-11-2026.csv', $this->square('TCH-BEE-01-S', 9.202, 2.272));
        // Deux relevés de la même grappe : le plus récent l'emporte.
        $this->write('TCH-SEB-01-04-11-2026.csv', $this->square('TCH-SEB-01', 9.300, 2.480));
        $this->write('TCH-SEB-01-06-11-2026.csv', $this->square('TCH-SEB-01', 9.310, 2.490, 0.004));
        $this->write('TCH-SEB-02-06-11-2026.csv', "TCH-SEB-02-P1, 9.31, 2.49, 1, 0, 0, 1, 1\nTCH-SEB-02-P2, 9.311, 2.49, 1, 0, 0, 1, 1\n");
        $this->write('grappe-sebou.csv', $this->square('X', 9.3, 2.4));

        $report = app(ClusterBoundaryImporter::class)->run();
        $files = collect($report['files'])->keyBy('file');

        $assiyo = Cluster::where('code', 'DAG-ASS-01')->firstOrFail();
        $this->assertSame('Assiyo', $assiyo->village->name, 'grappe créée et rattachée au village du code');
        $this->assertSame('FeatureCollection', $assiyo->boundary['type']);
        $this->assertSame([1, 2], array_column(array_column($assiyo->boundary['features'], 'properties'), 'hameau'));
        $this->assertSame('Polygon', $assiyo->boundary['features'][0]['geometry']['type']);
        $this->assertCount(5, $assiyo->boundary['features'][0]['geometry']['coordinates'][0], 'contour fermé');
        $this->assertSame(2, $files['DAG-ASS-01-06-11-2026.csv']['hamlets']);
        $this->assertSame([], $files['DAG-ASS-01-06-11-2026.csv']['warnings'], 'séparateurs « _ » acceptés');
        $this->assertEqualsWithDelta(2 * 4.9, $files['DAG-ASS-01-06-11-2026.csv']['area_ha'], 1.0);
        $this->assertSame('2026-11-06', $assiyo->boundary_date->toDateString());
        $this->assertNotNull($assiyo->latitude, 'centre du contour enregistré');

        $sub = SubCluster::firstOrFail();
        $this->assertSame(['TCH-BEE-03', 2], [$sub->cluster->code, $sub->number]);
        $this->assertNull($sub->cluster->boundary);
        $this->assertStringContainsString('TCH-BEE-01-S', $files['TCH-BEE-03-S2-07-11-2026.csv']['warnings'][0]);

        $sebou = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $this->assertSame('TCH-SEB-01-06-11-2026.csv', $sebou->boundary_file);
        $this->assertSame('remplacé', $files['TCH-SEB-01-04-11-2026.csv']['status']);
        $this->assertStringContainsString('2 point(s) seulement', $files['TCH-SEB-02-06-11-2026.csv']['warnings'][0]);
        $this->assertSame('Point', Cluster::where('code', 'TCH-SEB-02')->value('boundary')['features'][0]['geometry']['type'] ?? null);
        $this->assertSame('erreur', $files['grappe-sebou.csv']['status']);
        $this->assertSame(3, $report['clusters'], 'DAG-ASS-01, TCH-SEB-01 (relevé récent) et TCH-SEB-02');
        $this->assertSame(1, $report['sub_clusters']);

        // Compilation : un point par ligne, avec village et numéro de grappe ; relevé remplacé inclus.
        $rows = array_map(fn ($line) => str_getcsv($line, ';', '"', '\\'), file(app(ClusterBoundaryImporter::class)->compilationPath(), FILE_IGNORE_NEW_LINES));
        $header = array_shift($rows);
        $this->assertSame(['zone_sanitaire', 'prefixe_zone', 'village'], array_slice(array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', $h), $header), 0, 3));
        $this->assertCount(8 + 4 + 4 + 4 + 2, $rows);
        $first = array_combine(array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', $h), $header), $rows[0]);
        $this->assertSame(['Dassa-Glazoué', 'Assiyo', '1', '1', '1'], [$first['zone_sanitaire'], $first['village'], $first['numero_grappe'], $first['hameau'], $first['point']]);
        $this->assertContains('remplacé', array_column($rows, 15));

        // Fichier retiré du dossier : son contour disparaît au prochain import.
        unlink($this->settings.'/points_gps/TCH-SEB-01-06-11-2026.csv');
        app(ClusterBoundaryImporter::class)->run();
        $this->assertSame('TCH-SEB-01-04-11-2026.csv', $sebou->fresh()->boundary_file, 'le relevé restant prend le relais');
    }

    public function test_one_file_per_hamlet_is_combined_into_the_cluster_contour(): void
    {
        // Sébou 06 : un fichier par hameau, H01 relevé deux fois, points de H02 mal nommés (H3).
        $this->write('TCH-SEB-06-H01-07-11-2026.csv', $this->square('TCH-SEB-06-H01', 9.300, 2.480));
        $this->write('TCH-SEB-06-H01-09-11-2026.csv', $this->square('TCH-SEB-06-H01', 9.302, 2.482));
        $this->write('TCH-SEB-06-H02-09-11-2026.csv', $this->square('TCH-SEB-06-H3', 9.310, 2.490));
        // Sous-grappe avec hameau.
        $this->write('TCH-SEB-07-S1-H01-09-11-2026.csv', $this->square('TCH-SEB-07-S1-H01', 9.320, 2.500));
        // Sébou 08 : ancien relevé sans hameau, puis relevé d'un hameau plus récent qui le remplace.
        $this->write('TCH-SEB-08-05-11-2026.csv', $this->square('TCH-SEB-08', 9.330, 2.510));
        $this->write('TCH-SEB-08-H01-09-11-2026.csv', $this->square('TCH-SEB-08-H01', 9.331, 2.511));

        $this->assertTrue(app(ClusterBoundaryImporter::class)->isValidFileName('TCH-KPR-06-H01-09-11-2026.csv'));
        $report = app(ClusterBoundaryImporter::class)->run();
        $files = collect($report['files'])->keyBy('file');

        $cluster = Cluster::where('code', 'TCH-SEB-06')->firstOrFail();
        $features = $cluster->boundary['features'];
        $this->assertSame([1, 2], array_column(array_column($features, 'properties'), 'hameau'));
        $this->assertSame(['TCH-SEB-06-H01-09-11-2026.csv', 'TCH-SEB-06-H02-09-11-2026.csv'], array_column(array_column($features, 'properties'), 'fichier'));
        $this->assertSame('TCH-SEB-06-H01-09-11-2026.csv + TCH-SEB-06-H02-09-11-2026.csv', $cluster->boundary_file);
        $this->assertSame('2026-11-09', $cluster->boundary_date->toDateString());
        $this->assertSame(1, $files['TCH-SEB-06-H01-09-11-2026.csv']['hamlet']);
        $this->assertSame('remplacé', $files['TCH-SEB-06-H01-07-11-2026.csv']['status']);
        $this->assertSame('appliqué', $files['TCH-SEB-06-H02-09-11-2026.csv']['status']);
        $this->assertStringContainsString('Points nommés H3', implode(' ', $files['TCH-SEB-06-H02-09-11-2026.csv']['warnings']));
        $this->assertSame([], $files['TCH-SEB-06-H01-09-11-2026.csv']['warnings'], 'points H01 conformes au fichier');

        $sub = SubCluster::whereHas('cluster', fn ($q) => $q->where('code', 'TCH-SEB-07'))->firstOrFail();
        $this->assertSame([1, 1], [$sub->number, $sub->boundary['features'][0]['properties']['hameau']]);

        $this->assertSame('remplacé', $files['TCH-SEB-08-05-11-2026.csv']['status']);
        $this->assertSame([1], array_column(array_column(Cluster::where('code', 'TCH-SEB-08')->value('boundary')['features'], 'properties'), 'hameau'));
        $this->assertSame(2, $report['clusters'], 'TCH-SEB-06 et TCH-SEB-08, comptées une fois');
        $this->assertSame(1, $report['sub_clusters']);

        // Hameau retiré du dossier : la grappe garde les autres hameaux.
        unlink($this->settings.'/points_gps/TCH-SEB-06-H02-09-11-2026.csv');
        app(ClusterBoundaryImporter::class)->run();
        $this->assertSame([1], array_column(array_column($cluster->fresh()->boundary['features'], 'properties'), 'hameau'));
        $this->assertSame('TCH-SEB-06-H01-09-11-2026.csv', $cluster->fresh()->boundary_file);
    }

    public function test_points_are_projected_correctly_whatever_the_file_layout(): void
    {
        // Points relevés hors de l'ordre du tour : P1, P2, P3, P4 forment un « nœud papillon ».
        $this->write('TCH-KPK-03-09-11-2026.csv',
            "TCH-KPK-03-P1, 8.82000000, 2.60700000, 300, 0, 0, 300, 275\n"
            ."TCH-KPK-03-P2, 8.82200000, 2.60900000, 300, 0, 0, 300, 275\n"
            ."TCH-KPK-03-P3, 8.82000000, 2.60900000, 300, 0, 0, 300, 275\n"
            ."TCH-KPK-03-P4, 8.82200000, 2.60700000, 300, 0, 0, 300, 275\n");
        // Fichier sans en-tête, avec BOM, comme TCH-BAN-01-S3.
        file_put_contents($this->settings.'/points_gps/TCH-BAN-01-S3-07-11-2026.csv', "\xEF\xBB\xBF".$this->square('TCH-BAN-01-S3', 9.156, 2.314));
        // Latitude et longitude inversées sur un point.
        $this->write('TCH-SEB-01-08-11-2026.csv', str_replace('9.30000000, 2.48000000', '2.48000000, 9.30000000', $this->square('TCH-SEB-01', 9.300, 2.480)));
        // Latitude/longitude vides : position calculée depuis E/N (UTM 31N) ; « ; » et virgule décimale (Excel).
        file_put_contents($this->settings.'/points_gps/DAG-ASS-01-06-11-2026.csv',
            "Point:;Latitude:;Longitude:;Altitude: (m.);E: (m.);N: (m.)\n"
            ."DAG-ASS-01-P1;;;218,07;420838,750;836829,084\n"
            ."DAG-ASS-01-P2;7,56520527;2,28573766;181,40;421205,746;836295,779\n"
            ."DAG-ASS-01-P3;7,56517919;2,28481962;167,65;421104,462;836293,062\n"
            ."DAG-ASS-01-P4;7,56868480;2,28204166;193,60;420798,633;836681,140\n");

        $report = app(ClusterBoundaryImporter::class)->run();
        $files = collect($report['files'])->keyBy('file');

        // Contour démêlé : P1, P3, P2, P4 ; aucun côté ne se croise et la superficie est celle du carré.
        $kpk = Cluster::where('code', 'TCH-KPK-03')->firstOrFail();
        $ring = $kpk->boundary['features'][0]['geometry']['coordinates'][0];
        $this->assertSame([[2.607, 8.82], [2.609, 8.82], [2.609, 8.822], [2.607, 8.822], [2.607, 8.82]], $ring);
        $this->assertStringContainsString('contour tracé dans l\'ordre P1, P3, P2, P4', implode(' ', $files['TCH-KPK-03-09-11-2026.csv']['warnings']));
        $this->assertEqualsWithDelta(4.83, $files['TCH-KPK-03-09-11-2026.csv']['area_ha'], 0.1);

        $ban = SubCluster::where('number', 3)->whereHas('cluster', fn ($q) => $q->where('code', 'TCH-BAN-01'))->firstOrFail();
        $this->assertCount(5, $ban->boundary['features'][0]['geometry']['coordinates'][0]);
        $this->assertSame([], $files['TCH-BAN-01-S3-07-11-2026.csv']['warnings']);

        $seb = Cluster::where('code', 'TCH-SEB-01')->firstOrFail();
        $this->assertSame([2.48, 9.3], $seb->boundary['features'][0]['geometry']['coordinates'][0][0], 'GeoJSON en [lng, lat]');
        $this->assertStringContainsString('Latitude et longitude inversées', implode(' ', $files['TCH-SEB-01-08-11-2026.csv']['warnings']));

        $ass = $files['DAG-ASS-01-06-11-2026.csv'];
        $this->assertSame('appliqué', $ass['status'], json_encode($ass, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('calculée depuis les coordonnées UTM (E, N) : DAG-ASS-01-P1', implode(' ', $ass['warnings']));
        $this->assertStringNotContainsString('ne désignent pas le même endroit', implode(' ', $ass['warnings']), 'virgule décimale bien lue');
        $p1 = Cluster::where('code', 'DAG-ASS-01')->firstOrFail()->boundary['features'][0]['geometry']['coordinates'][0][0];
        $this->assertEqualsWithDelta(2.28240309, $p1[0], 0.000002, 'longitude depuis UTM');
        $this->assertEqualsWithDelta(7.57002357, $p1[1], 0.000002, 'latitude depuis UTM');
        $this->assertSame(4, $ass['points_count']);
        $this->assertStringContainsString('DAG-ASS-01;;;1;DAG-ASS-01-P1;7.570023', file_get_contents($this->settings.'/compilation_points_gps.csv'));
    }

    public function test_utm_conversion_matches_the_gps_receiver(): void
    {
        // Valeurs de l'export du GPS (DAG-AKO-02-P1, TCH-AMA-01-P1).
        [$lat, $lng] = ClusterBoundaryImporter::utmToLatLng(415708.682, 869619.735);
        $this->assertEqualsWithDelta(7.86653152, $lat, 0.0000005);
        $this->assertEqualsWithDelta(2.23536899, $lng, 0.0000005);
        [$lat, $lng] = ClusterBoundaryImporter::utmToLatLng(432492.325, 1023050.344);
        $this->assertEqualsWithDelta(9.25452650, $lat, 0.0000005);
        $this->assertEqualsWithDelta(2.38538921, $lng, 0.0000005);
    }

    public function test_suspicious_contours_are_flagged(): void
    {
        // Contour minuscule (quelques mètres) : relevé au même endroit.
        $this->write('TCH-BAD-03-04-11-2026.csv', $this->square('TCH-BAD-03', 9.21, 2.55, 0.00005));
        $this->write('XYZ-BAD-01-04-11-2026.csv', $this->square('XYZ-BAD-01', 9.21, 2.55));
        $this->write('TCH-ZZZ-01-04-11-2026.csv', $this->square('TCH-ZZZ-01', 9.21, 2.55));
        $this->write('TCH-BAD-05-04-11-2026.csv', "TCH-BAD-05-P1, 48.85, 2.35, 1, 0, 0, 1, 1\n");

        $files = collect(app(ClusterBoundaryImporter::class)->run()['files'])->keyBy('file');

        $this->assertStringContainsString('Contour très petit', implode(' ', $files['TCH-BAD-03-04-11-2026.csv']['warnings']));
        $this->assertStringContainsString('Préfixe de zone « XYZ » inconnu', $files['XYZ-BAD-01-04-11-2026.csv']['error']);
        $this->assertStringContainsString('Village « ZZZ » inconnu', $files['TCH-ZZZ-01-04-11-2026.csv']['error']);
        $this->assertStringContainsString('hors du Bénin', $files['TCH-BAD-05-04-11-2026.csv']['warnings'][0]);
        $this->assertSame('erreur', $files['TCH-BAD-05-04-11-2026.csv']['status']);
    }

    public function test_upload_delete_and_export_everything(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $file = fn (string $name, string $content) => UploadedFile::fake()->createWithContent($name, self::HEADER.$content);

        $this->actingAs($manager)->post(route('imports.boundaries.upload'), ['files' => [
            $file('DAG-ASS-01-06-11-2026.csv', $this->square('DAG-ASS-01', 7.570, 2.282)),
            $file('TCH-SEB-01-S2-07-11-2026.csv', $this->square('TCH-SEB-01-S2', 9.31, 2.49)),
            $file('releve sebou.csv', $this->square('X', 9.3, 2.4)),
        ]])->assertRedirect()->assertSessionHas('warning', fn ($m) => str_contains($m, '2 relevé(s) déposé(s)') && str_contains($m, 'releve sebou.csv'));

        $this->assertEqualsCanonicalizing(['DAG-ASS-01-06-11-2026.csv', 'TCH-SEB-01-S2-07-11-2026.csv'], app(ClusterBoundaryImporter::class)->files());
        $this->assertNotNull(Cluster::where('code', 'DAG-ASS-01')->value('boundary'));
        $this->assertSame(2, SubCluster::where('number', 2)->firstOrFail()->number);

        // Export d'ensemble : GeoJSON avec village et numéro de grappe, archive ZIP complète.
        $geo = $this->actingAs($manager)->get(route('imports.boundaries.geojson'))->assertOk()->json();
        $this->assertCount(2, $geo['features']);
        $this->assertSame(['Assiyo', 1, null], [$geo['features'][0]['properties']['village'], $geo['features'][0]['properties']['numero_grappe'], $geo['features'][0]['properties']['sous_grappe']]);
        $zipPath = app(ClusterBoundaryImporter::class)->exportArchive();
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        unlink($zipPath);
        $this->assertEqualsCanonicalizing(['compilation_points_gps.csv', 'contours_grappes.geojson', 'releves/DAG-ASS-01-06-11-2026.csv', 'releves/TCH-SEB-01-S2-07-11-2026.csv'], $names);
        $this->actingAs($manager)->get(route('imports.boundaries.archive'))->assertOk()->assertDownload();

        // Suppression d'un relevé : son contour disparaît.
        $this->actingAs($manager)->delete(route('imports.boundaries.destroy', 'DAG-ASS-01-06-11-2026.csv'))->assertRedirect();
        $this->assertNull(Cluster::where('code', 'DAG-ASS-01')->value('boundary'));
        $this->actingAs($manager)->delete(route('imports.boundaries.destroy', 'INCONNU-01-01-11-2026.csv'))->assertNotFound();

        // Lecteur : pas d'accès aux dépôts.
        $reader = User::factory()->create();
        $reader->assignRole(User::ROLE_READER);
        $this->actingAs($reader)->post(route('imports.boundaries.upload'), ['files' => [$file('DAG-MIN-03-06-11-2026.csv', $this->square('DAG-MIN-03', 7.8, 2.3))]])->assertRedirect(route('dashboard'));
        $this->assertNotContains('DAG-MIN-03-06-11-2026.csv', app(ClusterBoundaryImporter::class)->files());
    }

    public function test_upload_with_progress_reports_each_step_and_answers_in_json(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);
        $token = (string) Str::uuid();
        $file = fn (string $name, string $content) => UploadedFile::fake()->createWithContent($name, self::HEADER.$content);

        // Étapes publiées pendant le traitement, dans l'ordre (lecture : un appel par fichier du dossier).
        $steps = [];
        $this->write('TCH-SEB-01-07-11-2026.csv', $this->square('TCH-SEB-01', 9.31, 2.49));
        app(ClusterBoundaryImporter::class)->run(function (string $step, int $done, int $total) use (&$steps) {
            $steps[] = "{$step} {$done}/{$total}";
        });
        $this->assertSame(['lecture 0/1', 'application 1/1', 'compilation 1/1'], $steps);

        // Envoi depuis la page (XHR) : réponse JSON avec la page à recharger, message flashé, avancement « terminé ».
        $this->actingAs($manager)->postJson(route('imports.boundaries.upload'), [
            'progress_token' => $token,
            'files' => [$file('DAG-ASS-01-06-11-2026.csv', $this->square('DAG-ASS-01', 7.570, 2.282))],
        ])->assertOk()->assertJson(['redirect' => route('imports.index')])->assertSessionHas('success');
        $this->actingAs($manager)->getJson(route('imports.boundaries.progress', $token))->assertOk()->assertJson(['step' => 'termine']);

        // Réimport du dossier, même principe.
        $other = (string) Str::uuid();
        $this->actingAs($manager)->postJson(route('imports.boundaries'), ['progress_token' => $other])->assertOk()->assertJsonStructure(['redirect']);
        $this->actingAs($manager)->getJson(route('imports.boundaries.progress', $other))->assertJson(['step' => 'termine']);

        // L'avancement n'est lisible que par l'auteur de l'import ; jeton inconnu : en attente.
        $colleague = User::factory()->create();
        $colleague->assignRole(User::ROLE_DATA_MANAGER);
        $this->actingAs($colleague)->getJson(route('imports.boundaries.progress', $token))->assertOk()->assertExactJson(['step' => 'attente']);

        // Fichier refusé (validation) : erreur 422 en JSON, affichée dans la barre.
        $this->actingAs($manager)->postJson(route('imports.boundaries.upload'), ['progress_token' => (string) Str::uuid(), 'files' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_import_page_and_compilation_download(): void
    {
        $this->write('DAG-MIN-03-06-11-2026.csv', $this->square('DAG-MIN-03', 7.836, 2.296));
        $manager = User::factory()->create();
        $manager->assignRole(User::ROLE_DATA_MANAGER);

        $this->actingAs($manager)->post(route('imports.boundaries'))->assertRedirect()->assertSessionHas('success');
        $this->actingAs($manager)->get(route('imports.index'))->assertOk()->assertSee('DAG-MIN-03-06-11-2026.csv')->assertSee('Miniffi');
        $this->actingAs($manager)->get(route('imports.boundaries.compilation'))->assertOk()->assertDownload('compilation_points_gps_'.now()->format('Ymd').'.csv');
        $this->assertStringContainsString('DAG-MIN-03-P1', file_get_contents(app(ClusterBoundaryImporter::class)->compilationPath()));

        $cluster = Cluster::where('code', 'DAG-MIN-03')->firstOrFail();
        $this->actingAs($manager)->get(route('clusters.show', $cluster))->assertOk()->assertSee('relevé du 06/11/2026');
    }
}
