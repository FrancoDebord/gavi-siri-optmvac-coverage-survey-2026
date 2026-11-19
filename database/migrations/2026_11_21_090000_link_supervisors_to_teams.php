<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Les superviseurs sont aussi agents d'un binôme (village à gérer) : leur compte est rattaché à ce binôme,
 * retrouvé d'après leur nom, pour qu'ils voient tout ce que voit le binôme en plus de la supervision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Role::class) || ! Role::where('name', User::ROLE_SUPERVISOR)->exists()) {
            return;
        }
        foreach (User::role(User::ROLE_SUPERVISOR)->whereNull('team_id')->get() as $user) {
            if ($team = Team::forPerson($user->name)) {
                $user->forceFill(['team_id' => $team->id])->save();
            }
        }
    }

    public function down(): void {}
};
