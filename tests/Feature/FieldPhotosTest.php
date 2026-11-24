<?php

namespace Tests\Feature;

use App\Models\FieldPhoto;
use App\Models\HealthZone;
use App\Models\PhotoProject;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $zone = HealthZone::create(['code' => '1', 'name' => 'Tchaourou']);
        $this->team = Team::create(['code' => '27-48', 'name' => 'Akinlabi & Alagbe', 'health_zone_id' => $zone->id]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function project(array $attributes = []): PhotoProject
    {
        return PhotoProject::create($attributes + ['name' => 'GAVI Round 2', 'linked_to_survey' => true]);
    }

    public function test_pi_creates_a_space_with_link_and_pin_other_roles_cannot(): void
    {
        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('photo-projects.index'))->assertOk()->assertSee('Nouvel espace photos');
        $this->actingAs($pi)->post(route('photo-projects.store'), ['name' => 'Supervision Dassa', 'linked_to_survey' => '1'])->assertRedirect();

        $project = PhotoProject::firstOrFail();
        $this->assertSame(40, strlen($project->token));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $project->pin);
        $this->actingAs($pi)->get(route('photo-projects.show', $project))->assertOk()
            ->assertSee($project->publicUrl())->assertSee($project->pin);

        // Accès refusé : renvoi vers l'accueil (comme les autres pages protégées).
        $this->actingAs($this->user(User::ROLE_READER))->get(route('photo-projects.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->user(User::ROLE_READER))->post(route('photo-projects.store'), ['name' => 'X'])->assertRedirect();
        $this->assertSame(1, PhotoProject::count());
    }

    public function test_public_link_asks_the_pin_then_accepts_photos_and_shows_the_gallery(): void
    {
        $project = $this->project();
        $url = route('photos.public.show', $project->token);

        // Sans PIN : formulaire de code, pas de dépôt.
        $this->get($url)->assertOk()->assertSee('Code PIN de l\'espace', false)->assertDontSee('Déposer des photos');
        $this->postJson(route('photos.public.upload', $project->token), ['team_id' => $this->team->id, 'photos' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();
        $this->from($url)->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin === '000000' ? '111111' : '000000'])
            ->assertRedirect($url)->assertSessionHasErrors('pin');

        $this->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Déposer des photos')->assertSee($this->team->label());

        // Binôme obligatoire pour un projet de l'enquête.
        $this->postJson(route('photos.public.upload', $project->token), ['photos' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors('team_id');
        // Fichier qui n'est pas une photo : refusé.
        $this->postJson(route('photos.public.upload', $project->token), ['team_id' => $this->team->id, 'photos' => [UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]])
            ->assertUnprocessable();

        $this->postJson(route('photos.public.upload', $project->token), [
            'team_id' => $this->team->id, 'caption' => 'Séance de vaccination',
            'photos' => [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.png', 800, 600)],
        ])->assertOk()->assertJsonPath('saved', 2);

        $photo = FieldPhoto::orderBy('id')->firstOrFail();
        $this->assertSame($this->team->id, $photo->team_id);
        $this->assertSame('Séance de vaccination', $photo->caption);
        $this->assertNotNull($photo->thumb_path);
        Storage::disk('local')->assertExists([$photo->path, $photo->thumb_path]);

        $this->get($url)->assertSee('Séance de vaccination')->assertSee('2 photo(s)');
        $this->get(route('photos.public.file', [$project->token, $photo, 'vignette']))->assertOk();
        $this->get(route('photos.public.file', [$project->token, $photo, 'original']))->assertOk();
        // Les binômes ne peuvent pas supprimer.
        $this->delete(route('photo-projects.photos.destroy', [$project, $photo]))->assertRedirect(route('login'));

        // Autre appareil (session neuve) : PIN requis pour voir les fichiers.
        $this->flushSession();
        $this->get(route('photos.public.file', [$project->token, $photo, 'original']))->assertForbidden();
    }

    public function test_other_project_asks_a_free_name_and_place(): void
    {
        $project = $this->project(['name' => 'Projet paludisme', 'linked_to_survey' => false]);
        $this->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin]);

        $this->get(route('photos.public.show', $project->token))->assertSee('Votre nom')->assertDontSee('Choisir votre binôme');
        $this->postJson(route('photos.public.upload', $project->token), ['photos' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors('author_name');
        $this->postJson(route('photos.public.upload', $project->token), [
            'author_name' => 'Kossi', 'place' => 'Glazoué centre', 'team_id' => $this->team->id, 'photos' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertOk();

        $photo = FieldPhoto::firstOrFail();
        $this->assertSame(['Kossi', 'Glazoué centre', null], [$photo->author_name, $photo->place, $photo->team_id]);
    }

    public function test_closing_or_renewing_the_link_and_pin_locks_the_space(): void
    {
        $project = $this->project();
        $pi = $this->user(User::ROLE_PI);
        $this->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin]);
        $this->get(route('photos.public.show', $project->token))->assertSee('Déposer des photos');

        // Nouveau PIN : l'appareil doit le saisir de nouveau.
        $this->actingAs($pi)->post(route('photo-projects.regenerate', $project), ['what' => 'pin'])->assertRedirect();
        auth()->logout();
        $this->get(route('photos.public.show', $project->token))->assertSee('Code PIN de l\'espace', false);

        // Nouveau lien : l'ancien n'existe plus.
        $old = $project->token;
        $this->actingAs($pi)->post(route('photo-projects.regenerate', $project), ['what' => 'lien']);
        auth()->logout();
        $this->get(route('photos.public.show', $old))->assertNotFound();

        // Lien fermé.
        $project->refresh();
        $this->actingAs($pi)->put(route('photo-projects.update', $project), ['name' => $project->name, 'linked_to_survey' => '1'])->assertRedirect();
        auth()->logout();
        $this->assertFalse($project->refresh()->active);
        $this->get(route('photos.public.show', $project->token))->assertNotFound();
    }

    public function test_too_many_wrong_pins_are_blocked(): void
    {
        $project = $this->project();
        $wrong = $project->pin === '999999' ? '888888' : '999999';
        foreach (range(1, 5) as $ignored) {
            $this->post(route('photos.public.unlock', $project->token), ['pin' => $wrong]);
        }
        $this->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin])->assertSessionHasErrors('pin');
        $this->get(route('photos.public.show', $project->token))->assertSee('Code PIN de l\'espace', false);
    }

    public function test_manager_deletes_photos_and_downloads_a_zip(): void
    {
        $project = $this->project();
        $this->post(route('photos.public.unlock', $project->token), ['pin' => $project->pin]);
        $this->postJson(route('photos.public.upload', $project->token), ['team_id' => $this->team->id, 'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->assertOk();
        [$first, $second] = FieldPhoto::orderBy('id')->get()->all();

        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('photo-projects.show', $project))->assertOk()->assertSee('2 photo(s)', false);
        // Le gestionnaire voit les fichiers sans PIN.
        $this->flushSession();
        $this->actingAs($pi)->get(route('photos.public.file', [$project->token, $first, 'original']))->assertOk();

        $zip = $this->actingAs($pi)->get(route('photo-projects.download', $project))->assertOk();
        $this->assertStringContainsString('.zip', $zip->headers->get('content-disposition'));

        $this->actingAs($pi)->delete(route('photo-projects.photos.destroy', [$project, $first]))->assertRedirect();
        $this->assertModelMissing($first);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($second->path);

        $this->actingAs($pi)->delete(route('photo-projects.destroy', $project))->assertRedirect(route('photo-projects.index'));
        $this->assertSame(0, FieldPhoto::count());
        Storage::disk('local')->assertMissing($second->path);
    }
}
