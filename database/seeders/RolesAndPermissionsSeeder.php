<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'view dashboards' => [User::ROLE_ADMIN, User::ROLE_PI, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_READER, User::ROLE_VALIDATOR],
        'view pi dashboard' => [User::ROLE_ADMIN, User::ROLE_PI, User::ROLE_DATA_MANAGER],
        'send messages' => [User::ROLE_ADMIN, User::ROLE_PI],
        'view team space' => [User::ROLE_ADMIN, User::ROLE_TEAM],
        'manage decisions' => [User::ROLE_ADMIN, User::ROLE_PI, User::ROLE_DATA_MANAGER],
        'process queries' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR],
        // Affectation des queries ; validation (fermeture) réservée au data manager (GCP : revue indépendante).
        'assign queries' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR],
        'validate queries' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER],
        // Valider ou rouvrir les queries dont la validation est confiée (superviseurs, validateurs).
        'review queries' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_VALIDATOR],
        'export data' => [User::ROLE_ADMIN, User::ROLE_PI, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR],
        'report incidents' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER, User::ROLE_SUPERVISOR],
        'import data' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER],
        'manage dictionary' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER],
        'manage rules' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER],
        'run quality checks' => [User::ROLE_ADMIN, User::ROLE_DATA_MANAGER],
        'manage sources' => [User::ROLE_ADMIN],
        'manage users' => [User::ROLE_ADMIN],
        'view audit' => [User::ROLE_ADMIN],
        'manage settings' => [User::ROLE_ADMIN],
        // Exports des bases (brute, nettoyée, archive) et copie SQL complète : administrateur seul.
        'export databases' => [User::ROLE_ADMIN],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(User::ROLES) as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach (self::PERMISSIONS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web')->syncRoles($roles);
        }
    }
}
