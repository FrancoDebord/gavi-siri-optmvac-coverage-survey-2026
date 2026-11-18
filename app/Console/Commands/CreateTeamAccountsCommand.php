<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Un compte par binôme (rôle « binome ») : identifiant = noms des deux agents (ex. « dossou.kpade »),
 * mot de passe initial « password ». Les comptes existants gardent leur mot de passe, sauf avec --reset-password.
 */
class CreateTeamAccountsCommand extends Command
{
    public const DEFAULT_PASSWORD = 'password';

    protected $signature = 'gavi:team-accounts {--reset-password : remet « password » sur les comptes binômes existants}';

    protected $description = 'Crée le compte de connexion de chaque binôme (identifiant : noms des deux agents ; mot de passe : password)';

    public function handle(): int
    {
        $rows = [];
        foreach (self::sync((bool) $this->option('reset-password')) as $row) {
            $rows[] = [$row['team'], $row['username'], $row['status']];
        }
        $this->table(['Binôme', 'Identifiant', 'Compte'], $rows);
        $this->info('Mot de passe des nouveaux comptes : '.self::DEFAULT_PASSWORD);

        return self::SUCCESS;
    }

    /** @return list<array{team: string, username: string, status: string}> */
    public static function sync(bool $resetPassword = false): array
    {
        $result = [];
        $teams = Team::with('collectors:id,name,team_id,code')->orderBy('code')->get();
        foreach ($teams as $team) {
            $account = User::role(User::ROLE_TEAM)->where('team_id', $team->id)->orderBy('id')->first();
            $username = $account?->username ?: self::username($team, $account?->id);
            if (! $account) {
                $account = User::create([
                    'name' => 'Binôme '.$team->code.($team->name ? ' · '.$team->name : ''),
                    'username' => $username,
                    'email' => $username.'@binome.local',
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'is_active' => true,
                    'team_id' => $team->id,
                    'health_zone_id' => $team->health_zone_id,
                ]);
                $account->assignRole(User::ROLE_TEAM);
                $status = 'créé';
            } else {
                $changes = $account->username ? [] : ['username' => $username];
                if ($resetPassword) {
                    $changes['password'] = Hash::make(self::DEFAULT_PASSWORD);
                }
                $account->forceFill($changes)->save();
                $status = $changes ? 'mis à jour' : 'existant';
            }
            $result[] = ['team' => $team->code.($team->name ? ' · '.$team->name : ''), 'username' => $account->username, 'status' => $status];
        }

        return $result;
    }

    /** Noms de famille des deux agents, en minuscules sans accents (« dossou.kpade »), unique. */
    public static function username(Team $team, ?int $ignore = null): string
    {
        $names = $team->collectors->sortBy('code')->map(fn ($c) => Str::slug(trim(explode(',', (string) $c->name)[0])))->filter()->values();
        $base = $names->isNotEmpty() ? $names->implode('.') : 'binome-'.Str::slug($team->code);
        $username = $base;
        for ($i = 2; User::where('username', $username)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $username = $base.'.'.$i;
        }

        return $username;
    }
}
