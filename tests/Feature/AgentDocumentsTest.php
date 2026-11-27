<?php

namespace Tests\Feature;

use App\Mail\AgentDocumentsMail;
use App\Models\AgentDocument;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\DocumentSpace;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Collector $agent;

    private Collector $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $zone = HealthZone::create(['code' => '1', 'name' => 'Tchaourou']);
        $this->team = Team::create(['code' => '27-48', 'name' => 'Akinlabi & Alagbe', 'health_zone_id' => $zone->id]);
        $this->agent = Collector::create(['code' => '27', 'name' => 'Akinlabi Rachidi', 'team_id' => $this->team->id, 'health_zone_id' => $zone->id, 'is_active' => true]);
        $this->partner = Collector::create(['code' => '48', 'name' => 'Alagbe Kafayath', 'team_id' => $this->team->id, 'health_zone_id' => $zone->id, 'is_active' => true]);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function space(array $attributes = []): DocumentSpace
    {
        return DocumentSpace::create($attributes + ['name' => 'Paiement Round 2', 'linked_to_survey' => true]);
    }

    private function unlock(DocumentSpace $space): void
    {
        $this->post(route('documents.public.unlock', $space->token), ['pin' => $space->pin]);
    }

    private function pdf(string $name = 'facture.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_pi_creates_a_space_other_roles_cannot(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('document-spaces.index'))->assertOk()->assertSee('Nouvel espace de dépôt');
        $this->actingAs($pi)->post(route('document-spaces.store'), ['name' => 'Paiement', 'linked_to_survey' => '1'])->assertRedirect();

        $space = DocumentSpace::firstOrFail();
        $this->assertSame(40, strlen($space->token));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $space->pin);
        $this->actingAs($pi)->get(route('document-spaces.show', $space))->assertOk()
            ->assertSee($space->publicUrl())->assertSee($space->pin)->assertSee('Suivi par agent');

        $this->actingAs($this->user(User::ROLE_READER))->get(route('document-spaces.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->user(User::ROLE_READER))->post(route('document-spaces.store'), ['name' => 'X'])->assertRedirect();
        $this->assertSame(1, DocumentSpace::count());
    }

    public function test_agent_unlocks_with_pin_and_uploads_invoice_and_report(): void
    {
        $space = $this->space();
        $url = route('documents.public.show', $space->token);

        $this->get($url)->assertOk()->assertSee('Code PIN de l\'espace', false)->assertDontSee('Déposer mes documents');
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [$this->pdf()]])->assertForbidden();

        $this->unlock($space);
        $this->get($url)->assertOk()->assertSee('Déposer mes documents')->assertSee('Akinlabi Rachidi (27)');

        // Agent et type obligatoires ; format refusé.
        $this->from($url)->post(route('documents.public.upload', $space->token), ['type' => 'facture', 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('collector_id');
        $this->from($url)->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('type');
        $this->from($url)->post(route('documents.public.upload', $space->token), [
            'collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('files.0');

        $this->post(route('documents.public.upload', $space->token), [
            'collector_id' => $this->agent->id, 'type' => 'facture', 'comment' => 'Novembre', 'files' => [$this->pdf()],
        ])->assertRedirect($url)->assertSessionHas('success');
        $this->post(route('documents.public.upload', $space->token), [
            'collector_id' => $this->agent->id, 'type' => 'rapport',
            'files' => [UploadedFile::fake()->image('page1.jpg'), UploadedFile::fake()->create('rapport.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ])->assertRedirect($url);

        $this->assertSame(3, AgentDocument::count());
        $invoice = AgentDocument::where('type', 'facture')->firstOrFail();
        $this->assertSame([$this->agent->id, $this->team->id, 'Novembre', 'recu'], [$invoice->collector_id, $invoice->team_id, $invoice->comment, $invoice->status]);
        Storage::disk('local')->assertExists($invoice->path);

        // L'agent revoit ses dépôts sur cet appareil ; un autre appareil ne voit rien.
        $this->get($url)->assertSee('Mes dépôts sur cet appareil')->assertSee('facture.pdf')->assertSee('rapport.docx');
        $this->flushSession();
        $this->travel(2)->minutes(); // limite de requêtes par minute (throttle) remise à zéro
        $this->unlock($space);
        $this->get($url)->assertDontSee('facture.pdf')->assertSee('Aucun document déposé depuis cet appareil');
    }

    public function test_person_outside_the_list_gives_a_free_name(): void
    {
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['author_name' => 'Superviseur Dossou', 'type' => 'rapport', 'files' => [$this->pdf('r.pdf')]])
            ->assertSessionHasNoErrors();

        $document = AgentDocument::firstOrFail();
        $this->assertSame([null, 'Superviseur Dossou'], [$document->collector_id, $document->author_name]);
    }

    public function test_viewer_data_inline_files_and_review_from_the_viewer(): void
    {
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [$this->pdf()]]);
        $this->post(route('documents.public.upload', $space->token), [
            'collector_id' => $this->agent->id, 'type' => 'rapport',
            'files' => [UploadedFile::fake()->image('page.jpg'), UploadedFile::fake()->create('rapport.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ]);
        [$pdf, $image, $word] = AgentDocument::orderBy('id')->get()->all();
        $pi = $this->user(User::ROLE_PI);

        $this->actingAs($pi)->get(route('document-spaces.show', $space))->assertOk()
            ->assertSee('data-doc-viewer', false)->assertSee('data-kind="pdf"', false)->assertSee('data-kind="image"', false)
            ->assertSee('data-kind="other"', false)->assertSee('Visionner les 3 documents');

        // PDF et image affichés (visionneuse), Word téléchargé ; téléchargement forcé sur demande.
        $this->assertStringStartsWith('inline', $this->actingAs($pi)->get(route('document-spaces.file', [$space, $pdf]))->headers->get('content-disposition'));
        $this->assertStringStartsWith('inline', $this->actingAs($pi)->get(route('document-spaces.file', [$space, $image]))->headers->get('content-disposition'));
        $this->assertStringStartsWith('attachment', $this->actingAs($pi)->get(route('document-spaces.file', [$space, $word]))->headers->get('content-disposition'));
        $this->assertStringStartsWith('attachment', $this->actingAs($pi)->get(route('document-spaces.file', [$space, $pdf, 'telecharger' => 1]))->headers->get('content-disposition'));

        // Revue depuis la visionneuse : réponse JSON.
        $this->actingAs($pi)->putJson(route('document-spaces.review', [$space, $pdf]), ['status' => 'valide'])->assertOk()
            ->assertJson(['status' => 'valide', 'label' => 'Validé']);
        $this->actingAs($pi)->putJson(route('document-spaces.review', [$space, $image]), ['status' => 'a_refaire'])->assertUnprocessable()
            ->assertJsonValidationErrors('review_note');
        $this->actingAs($pi)->putJson(route('document-spaces.review', [$space, $image]), ['status' => 'a_refaire', 'review_note' => 'Illisible'])->assertOk()
            ->assertJson(['status' => 'a_refaire', 'note' => 'Illisible']);
    }

    public function test_documents_are_sent_by_email_with_a_signed_download_link(): void
    {
        Mail::fake();
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [$this->pdf()]]);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->partner->id, 'type' => 'rapport', 'files' => [$this->pdf('rapport.pdf')]]);
        $pi = $this->user(User::ROLE_PI);
        $page = route('document-spaces.show', $space);

        $this->actingAs($pi)->get($page)->assertOk()->assertSee('Envoyer par e-mail');
        // Adresse invalide : rien n'est envoyé.
        $this->actingAs($pi)->from($page)->post(route('document-spaces.email', $space), ['recipients' => 'compta@exemple.org, pas-une-adresse', 'subject' => 'Factures'])
            ->assertSessionHasErrors('recipients');
        Mail::assertNothingSent();

        // Factures seulement (filtre), deux destinataires.
        $this->actingAs($pi)->from($page)->post(route('document-spaces.email', $space), [
            'recipients' => "compta@exemple.org;\nCoordination@Exemple.org", 'subject' => 'Factures novembre', 'message' => 'Bonjour', 'type' => 'facture',
        ])->assertRedirect($page);

        $sent = null;
        Mail::assertSent(AgentDocumentsMail::class, function (AgentDocumentsMail $mail) use (&$sent) {
            $sent = $mail;

            return $mail->hasTo('compta@exemple.org') && $mail->hasTo('coordination@exemple.org') && $mail->documents->count() === 1;
        });
        $this->assertTrue($sent->attachFiles);
        $this->assertCount(1, $sent->attachments());
        $sent->assertSeeInHtml('Factures et rapports d\'activité')->assertSeeInHtml('Akinlabi Rachidi (27)')->assertDontSeeInHtml('Alagbe');
        $this->assertSame('documents.envoi_mail', AuditLog::latest('id')->value('action'));
        $this->actingAs($pi)->get($page)->assertSee('compta@exemple.org, coordination@exemple.org');
        auth()->logout();

        // Lien signé : ZIP sans compte ; lien modifié ou expiré : refusé.
        $this->get($sent->link)->assertOk()->assertDownload();
        $this->get(str_replace('type=facture', 'type=rapport', $sent->link))->assertForbidden();
        $this->travel(8)->days();
        $this->get($sent->link)->assertForbidden();
    }

    public function test_heavy_selection_is_sent_as_a_link_only(): void
    {
        Mail::fake();
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [UploadedFile::fake()->create('lourde.pdf', 3000, 'application/pdf')]]);

        $this->actingAs($this->user(User::ROLE_PI))->post(route('document-spaces.email', $space), ['recipients' => 'compta@exemple.org', 'subject' => 'Factures']);
        Mail::assertSent(AgentDocumentsMail::class, fn (AgentDocumentsMail $mail) => ! $mail->attachFiles && $mail->attachments() === []);
    }

    public function test_documents_are_not_downloadable_without_manager_rights(): void
    {
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [$this->pdf()]]);
        $document = AgentDocument::firstOrFail();

        $this->get(route('document-spaces.file', [$space, $document]))->assertRedirect(route('login'));
        $team = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);
        $this->actingAs($team)->get(route('document-spaces.file', [$space, $document]))->assertRedirect();
        $this->actingAs($this->user(User::ROLE_PI))->get(route('document-spaces.file', [$space, $document]))->assertOk();
    }

    public function test_manager_tracks_reviews_and_downloads(): void
    {
        $space = $this->space();
        $this->unlock($space);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'facture', 'files' => [$this->pdf()]]);
        $this->post(route('documents.public.upload', $space->token), ['collector_id' => $this->agent->id, 'type' => 'rapport', 'files' => [$this->pdf('rapport.pdf')]]);
        [$invoice, $report] = AgentDocument::orderBy('id')->get()->all();

        $pi = $this->user(User::ROLE_PI);
        // Suivi : dossier complet pour l'agent 27, manquant pour son binôme.
        $this->actingAs($pi)->get(route('document-spaces.show', $space))->assertOk()
            ->assertSee('1 / 2 avec facture et rapport')->assertSee('Manquant');
        $this->actingAs($pi)->get(route('document-spaces.show', [$space, 'incomplets' => 1]))->assertOk()
            ->assertSee('Alagbe Kafayath');

        // Validation ; correction demandée avec motif obligatoire.
        $this->actingAs($pi)->put(route('document-spaces.review', [$space, $invoice]), ['status' => 'valide'])->assertRedirect();
        $this->assertSame([AgentDocument::STATUS_VALID, $pi->id], [$invoice->refresh()->status, $invoice->reviewed_by]);
        $this->actingAs($pi)->put(route('document-spaces.review', [$space, $report]), ['status' => 'a_refaire'])->assertSessionHasErrors('review_note');
        $this->actingAs($pi)->put(route('document-spaces.review', [$space, $report]), ['status' => 'a_refaire', 'review_note' => 'Signature manquante'])->assertRedirect();
        auth()->logout();

        // L'agent voit le motif sur son appareil.
        $this->get(route('documents.public.show', $space->token))->assertSee('À corriger : Signature manquante');

        $this->actingAs($pi)->get(route('document-spaces.download', $space))->assertOk()->assertDownload();
        $this->actingAs($pi)->get(route('document-spaces.download', [$space, 'type' => 'facture', 'status' => 'a_refaire']))->assertRedirect();

        $this->actingAs($pi)->delete(route('document-spaces.documents.destroy', [$space, $report]))->assertRedirect();
        Storage::disk('local')->assertMissing($report->path);
        $this->assertSame(1, AgentDocument::count());
    }

    public function test_team_account_opens_without_pin_and_only_sees_its_agents(): void
    {
        $other = Team::create(['code' => '33-43', 'name' => 'Autre', 'health_zone_id' => $this->team->health_zone_id]);
        $stranger = Collector::create(['code' => '33', 'name' => 'Zinsou Paul', 'team_id' => $other->id, 'health_zone_id' => $this->team->health_zone_id, 'is_active' => true]);
        $account = $this->user(User::ROLE_TEAM, ['team_id' => $this->team->id]);

        $this->actingAs($account)->get(route('team.documents'))->assertRedirect(route('team.space'))->assertSessionHas('info');

        $space = $this->space();
        $this->actingAs($account)->get(route('team.documents'))->assertRedirect($space->publicUrl());
        $this->actingAs($account)->get($space->publicUrl())->assertOk()
            ->assertSee('Akinlabi Rachidi')->assertSee('Alagbe Kafayath')->assertDontSee('Zinsou Paul');
        // Agent d'un autre binôme refusé.
        $this->actingAs($account)->post(route('documents.public.upload', $space->token), ['collector_id' => $stranger->id, 'type' => 'facture', 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('collector_id');
    }

    public function test_closing_the_space_or_renewing_the_pin_locks_it(): void
    {
        $space = $this->space();
        $pi = $this->user(User::ROLE_PI);
        $this->unlock($space);
        $this->get(route('documents.public.show', $space->token))->assertSee('Déposer mes documents');

        $this->actingAs($pi)->post(route('document-spaces.regenerate', $space), ['what' => 'pin'])->assertRedirect();
        auth()->logout();
        $this->get(route('documents.public.show', $space->token))->assertSee('Code PIN de l\'espace', false);

        $this->actingAs($pi)->put(route('document-spaces.update', $space), ['name' => $space->name, 'linked_to_survey' => '1'])->assertRedirect();
        auth()->logout();
        $this->assertFalse($space->refresh()->active);
        $this->get(route('documents.public.show', $space->token))->assertNotFound();
    }
}
