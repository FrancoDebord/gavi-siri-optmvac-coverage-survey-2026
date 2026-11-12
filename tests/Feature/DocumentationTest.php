<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_reads_every_document_rendered_from_markdown(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('documentation.index'))->assertOk()
            ->assertSee('Guide de la plateforme')->assertSee('Règles qualité')->assertSee('Déploiement (CI/CD)');

        $page = $this->actingAs($admin)->get(route('documentation.show', 'deploiement'))->assertOk();
        $html = $page->getContent();
        // Titres avec ancres, sommaire, tableaux Bootstrap, blocs de code.
        $this->assertStringContainsString('id="1-preparer-le-serveur-une-seule-fois"', $html);
        $this->assertStringContainsString('href="#1-preparer-le-serveur-une-seule-fois"', $html);
        $this->assertStringContainsString('<table class="table table-sm table-bordered align-middle">', $html);
        $this->assertStringContainsString('<pre><code', $html);
        // Lien vers un fichier du dépôt : affiché sans lien ; le titre h1 n'est pas répété dans le corps.
        $this->assertStringContainsString('<span class="doc-file" title="../.github/workflows/ci-cd.yml">', $html);
        $this->assertStringNotContainsString('<h1>', $html);

        // Lien entre documents réécrit vers le site.
        $guide = $this->actingAs($admin)->get(route('documentation.show', 'plateforme'))->assertOk()->getContent();
        $this->assertStringContainsString('href="/documentation/deploiement"', $guide);
        $this->assertStringContainsString('href="/documentation/regles-qualite"', $guide);

        $this->actingAs($admin)->get(route('documentation.show', 'inconnue'))->assertNotFound();
    }

    public function test_access_depends_on_the_role(): void
    {
        $this->get(route('documentation.index'))->assertRedirect(route('login'));

        $reader = $this->user(User::ROLE_READER);
        $this->actingAs($reader)->get(route('documentation.index'))->assertOk()
            ->assertSee('Guide de la plateforme')->assertDontSee('Déploiement (CI/CD)');
        $this->actingAs($reader)->get(route('documentation.show', 'regles-qualite'))->assertOk();
        $this->actingAs($reader)->get(route('documentation.show', 'deploiement'))->assertForbidden();

        $pi = $this->user(User::ROLE_PI);
        $this->actingAs($pi)->get(route('documentation.show', 'plateforme'))->assertOk();
        $this->actingAs($pi)->get(route('documentation.show', 'deploiement'))->assertForbidden();

        // Lien du menu présent pour le lecteur.
        $this->assertStringContainsString(route('documentation.index'), $this->actingAs($reader)->get(route('documentation.index'))->getContent());
    }
}
