<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Socle : rôles, règles qualité, dictionnaire par défaut + référentiel, compte administrateur.
     * Données fictives : php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, QualityRuleSeeder::class]);

        Artisan::call('gavi:load-dictionary');
        $this->command?->info(trim(Artisan::output()));

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL') ?: 'admin@gavi.local'],
            ['name' => 'Administrateur', 'password' => env('ADMIN_PASSWORD') ?: 'ChangeMoi!2026'],
        );
        $admin->syncRoles([User::ROLE_ADMIN]);
    }
}
