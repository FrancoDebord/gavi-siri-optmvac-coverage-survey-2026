<?php

use App\Console\Commands\CreateTeamAccountsCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifiant de connexion (en plus de l'e-mail), puis un compte par binôme : identifiant = noms des deux agents,
 * mot de passe initial « password » (les comptes binômes existants gardent leur mot de passe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->unique()->after('email');
            // Mot de passe initial ou fixé par l'administrateur : à changer à la première connexion.
            $table->boolean('must_change_password')->default(false)->after('password');
        });
        if (Schema::hasTable('teams')) {
            CreateTeamAccountsCommand::sync();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'must_change_password']);
        });
    }
};
