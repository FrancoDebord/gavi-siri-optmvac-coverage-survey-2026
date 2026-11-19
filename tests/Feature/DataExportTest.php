<?php

namespace Tests\Feature;

use App\Models\DataCorrection;
use App\Models\DataExport;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\User;
use App\Services\Import\NormalizedSubmission;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Database\Seeders\QualityRuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/** Exclusion des doublons, exports des bases brute et nettoyée, paquet d'archivage, copie SQL chiffrée. */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);
        $this->artisan('gavi:load-dictionary')->assertSuccessful();
        // uuid:1 et uuid:3 : même ménage enregistré deux fois (doublon) ; uuid:2 : autre ménage.
        foreach ([['uuid:1', 'TCH-SEB-01-001'], ['uuid:2', 'TCH-SEB-01-002'], ['uuid:3', 'TCH-SEB-01-001']] as [$uuid, $code]) {
            (new SubmissionProcessor)->process(new NormalizedSubmission($uuid, [
                'today' => now()->toDateString(), 'l1' => '1', 'l2' => '1', 'l3' => '5', 'village' => '66', 'fieldworker' => '46',
                'household' => $code, 'household_survey_status' => '0', 'visit_num' => '1',
            ], [], ['uuid' => $uuid]), ['source_type' => 'demo']);
        }
        app(QualityEngine::class)->run('test');
        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);
        $this->manager = User::factory()->create();
        $this->manager->assignRole(User::ROLE_DATA_MANAGER);
        File::deleteDirectory(storage_path('app/private/exports'));
    }

    private function correct(string $uuid, string $rule, array $corrections): DataQuery
    {
        $query = DataQuery::where('submission_uuid', $uuid)->where('rule_code', $rule)->firstOrFail();
        $this->actingAs($this->manager)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_CORRECTED, 'corrections' => $corrections])->assertSessionHas('success');
        // Validation par un autre data manager (double validation possible selon la sévérité).
        $second = User::factory()->create();
        $second->assignRole(User::ROLE_DATA_MANAGER);
        $this->actingAs($second)->post(route('queries.status', $query), ['status' => DataQuery::STATUS_VALIDATED, 'password' => 'password'])->assertSessionHas('success');

        return $query->fresh();
    }

    private function zip(DataExport $export, ?string $password = null): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($export->file_path));
        if ($password) {
            $zip->setPassword($password);
        }

        return $zip;
    }

    private function csv(ZipArchive $zip, string $name): array
    {
        $content = $zip->getFromName($name);
        $this->assertNotFalse($content, $name);
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim(substr($content, 3))));
        $header = array_shift($lines);

        return array_map(fn ($line) => array_combine($header, $line), $lines);
    }

    public function test_duplicate_is_excluded_from_clean_base_but_kept_in_raw(): void
    {
        $this->assertSame(2, DataQuery::where('rule_code', 'DUP_HOUSEHOLD')->count());
        $query = $this->correct('uuid:3', 'DUP_HOUSEHOLD', [['variable' => DataCorrection::EXCLUSION, 'new_value' => '1', 'reason' => 'superviseur', 'comment' => 'Doublon de uuid:1, conservée']]);

        $this->assertNotNull(Household::withoutGlobalScope('included')->where('submission_uuid', 'uuid:3')->value('excluded_at'));
        $this->assertSame(2, Household::count(), 'Base nettoyée sans le doublon');
        $this->assertSame(3, Household::withoutGlobalScope('included')->count());
        // Queries du doublon fermées ; le doublon disparaît aussi chez la soumission conservée.
        $this->assertSame(DataQuery::STATUS_VALIDATED, $query->status);
        $this->assertSame(0, DataQuery::where('rule_code', 'DUP_HOUSEHOLD')->whereIn('status', DataQuery::UNRESOLVED)->count());
        // La fiche reste consultable.
        $household = Household::withoutGlobalScope('included')->where('submission_uuid', 'uuid:3')->firstOrFail();
        $this->actingAs($this->manager)->get(route('households.show', $household))->assertOk()->assertSee('exclue de la base nettoyée');
        // Une resynchronisation ne réintègre pas la soumission.
        (new SubmissionProcessor)->process(new NormalizedSubmission('uuid:3', $household->source_data['data'], [], ['uuid' => 'uuid:3']), ['source_type' => 'demo']);
        $this->assertNotNull($household->fresh()->excluded_at);
    }

    public function test_raw_and_clean_exports_with_manifest_and_hashes(): void
    {
        $this->correct('uuid:1', 'GPS_MISSING', [['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite']]);
        $this->correct('uuid:3', 'DUP_HOUSEHOLD', [['variable' => DataCorrection::EXCLUSION, 'new_value' => '1', 'reason' => 'superviseur', 'comment' => 'Doublon de uuid:1']]);

        foreach ([DataExport::TYPE_RAW, DataExport::TYPE_CLEAN] as $type) {
            $this->actingAs($this->admin)->post(route('exports.store'), ['type' => $type])->assertRedirect(route('exports.index'));
        }
        [$raw, $clean] = [DataExport::where('type', DataExport::TYPE_RAW)->firstOrFail(), DataExport::where('type', DataExport::TYPE_CLEAN)->firstOrFail()];
        $this->assertSame(DataExport::STATUS_DONE, $raw->status, (string) $raw->error);
        $this->assertSame(hash_file('sha256', $raw->file_path), $raw->sha256);

        // Base brute : 3 soumissions telles que reçues (pas de GPS), doublon signalé.
        $rows = collect($this->csv($zip = $this->zip($raw), 'menages.csv'))->keyBy('submission_uuid');
        $this->assertCount(3, $rows);
        $this->assertSame('', $rows['uuid:1']['current_location']);
        $this->assertSame('oui', $rows['uuid:3']['exclue_base_nettoyee']);
        // Manifeste : empreinte de chaque fichier.
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $file = collect($manifest['fichiers'])->firstWhere('fichier', 'menages.csv');
        $this->assertSame(hash('sha256', $zip->getFromName('menages.csv')), $file['sha256']);
        $this->assertSame(3, $file['lignes']);
        $this->assertNotFalse($zip->getFromName('LISEZMOI.txt'));

        // Base nettoyée : correction appliquée, doublon écarté, registre joint.
        $zip = $this->zip($clean);
        $rows = collect($this->csv($zip, 'menages.csv'))->keyBy('submission_uuid');
        $this->assertSame(['uuid:1', 'uuid:2'], $rows->keys()->sort()->values()->all());
        $this->assertSame('9.31 2.49 0 5', $rows['uuid:1']['current_location']);
        $this->assertSame('1', $rows['uuid:1']['nb_corrections']);
        $this->assertCount(2, $this->csv($zip, 'registre_corrections.csv'));

        // Téléchargement : tracé.
        $this->actingAs($this->admin)->get(route('exports.download', $clean))->assertOk()->assertDownload($clean->file_name);
        $this->assertSame(1, $clean->fresh()->download_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.base_telechargement']);
    }

    public function test_archive_package_contains_all_datasets(): void
    {
        $this->actingAs($this->admin)->post(route('exports.store'), ['type' => DataExport::TYPE_ARCHIVE]);
        $export = DataExport::firstOrFail();
        $this->assertSame(DataExport::STATUS_DONE, $export->status, (string) $export->error);
        $zip = $this->zip($export);
        foreach (['base_brute/menages.csv', 'base_nettoyee/menages.csv', 'registre_corrections.csv', 'queries.csv', 'historique_queries.csv',
            'observations_supervision.csv', 'dictionnaire.csv', 'manifest.json', 'LISEZMOI.txt'] as $name) {
            $this->assertNotFalse($zip->getFromName($name), $name);
        }
        $this->assertNotEmpty($this->csv($zip, 'queries.csv'));
    }

    public function test_download_survives_deleted_release_and_reports_missing_files(): void
    {
        $this->actingAs($this->admin)->post(route('exports.store'), ['type' => DataExport::TYPE_RAW]);
        $export = DataExport::firstOrFail();
        // Chemin enregistré sous une release supprimée depuis (déploiements successifs) : le fichier est dans storage partagé.
        $export->update(['file_path' => '/home/app/releases/20261101000000-old/storage/app/private/exports/'.$export->file_name]);

        $this->actingAs($this->admin)->get(route('exports.index'))->assertOk()->assertSee('Télécharger');
        $this->actingAs($this->admin)->get(route('exports.download', $export))->assertOk()->assertDownload($export->file_name);

        unlink($export->path());
        $this->actingAs($this->admin)->get(route('exports.index'))->assertSee('Fichier introuvable')->assertDontSee('Télécharger');

        $stuck = DataExport::create(['type' => DataExport::TYPE_CLEAN, 'status' => DataExport::STATUS_PENDING, 'requested_by' => $this->admin->id]);
        $stuck->forceFill(['created_at' => now()->subMinutes(20)])->save();
        $this->actingAs($this->admin)->get(route('exports.index'))->assertSee('ne semble pas tourner');
    }

    public function test_sql_copy_is_admin_only_signed_and_encrypted(): void
    {
        $this->actingAs($this->manager)->get(route('exports.index'))->assertRedirect();
        $this->actingAs($this->manager)->post(route('exports.store'), ['type' => DataExport::TYPE_RAW])->assertRedirect();
        $this->assertSame(0, DataExport::count());

        $secret = 'Copie-OMS-2026!';
        $this->actingAs($this->admin)->post(route('exports.store'), ['type' => DataExport::TYPE_SQL, 'zip_password' => $secret, 'zip_password_confirmation' => $secret, 'account_password' => 'mauvais'])
            ->assertSessionHasErrors('account_password');
        $this->actingAs($this->admin)->post(route('exports.store'), ['type' => DataExport::TYPE_SQL, 'zip_password' => 'court', 'zip_password_confirmation' => 'court', 'account_password' => 'password'])
            ->assertSessionHasErrors('zip_password');
        $this->actingAs($this->admin)->post(route('exports.store'), ['type' => DataExport::TYPE_SQL, 'zip_password' => $secret, 'zip_password_confirmation' => $secret, 'account_password' => 'password'])
            ->assertRedirect(route('exports.index'));

        $export = DataExport::firstOrFail();
        $this->assertSame(DataExport::STATUS_DONE, $export->status, (string) $export->error);
        $this->assertFalse($this->zip($export)->getFromName('base.sql'), 'Illisible sans le mot de passe');
        $sql = $this->zip($export, $secret)->getFromName('base.sql');
        $this->assertStringContainsString('CREATE TABLE "households"', $sql);
        $this->assertStringContainsString('INSERT INTO "households"', $sql);
        $this->assertStringContainsString('uuid:2', $sql);
        $this->assertStringNotContainsString('INSERT INTO "sessions"', $sql);
        $this->assertStringNotContainsString($secret, json_encode($export->toArray()));
    }

    public function test_registry_excel_export(): void
    {
        $this->correct('uuid:1', 'GPS_MISSING', [['variable' => 'current_location', 'new_value' => '9.31 2.49 0 5', 'reason' => 'revisite']]);
        $this->actingAs($this->manager)->get(route('corrections.export'))->assertOk()->assertDownload();
    }
}
