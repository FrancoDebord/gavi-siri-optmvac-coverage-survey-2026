<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_list_filters_by_text_role_and_status(): void
    {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['name' => 'Admin Principal', 'email' => 'admin@airid.example']);
        $admin->assignRole(User::ROLE_ADMIN);
        User::factory()->create(['name' => 'Akpovi Rodrigue', 'email' => 'rakpovi@airid.example'])->assignRole(User::ROLE_SUPERVISOR);
        User::factory()->create(['name' => 'Dossou Mireille', 'email' => 'mdossou@airid.example', 'is_active' => false])->assignRole(User::ROLE_DATA_MANAGER);
        User::factory()->create(['name' => 'Agent 50%', 'username' => 'houngbo.tossou', 'email' => 'equipe@airid.example'])->assignRole(User::ROLE_TEAM);

        $list = fn (array $params) => $this->actingAs($admin)->get(route('users.index', $params))->assertOk();

        $list(['q' => 'akpo'])->assertSee('Akpovi Rodrigue')->assertDontSee('Dossou Mireille')->assertSee('1 utilisateur(s) sur 4');
        $list(['q' => 'houngbo'])->assertSee('Agent 50%')->assertDontSee('Akpovi Rodrigue');
        $list(['role' => User::ROLE_DATA_MANAGER])->assertSee('Dossou Mireille')->assertDontSee('Akpovi Rodrigue');
        $list(['statut' => 'desactive'])->assertSee('Dossou Mireille')->assertDontSee('Akpovi Rodrigue');
        $list(['q' => 'introuvable'])->assertSee('Aucun utilisateur ne correspond');
    }
}
