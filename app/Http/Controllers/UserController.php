<?php

namespace App\Http\Controllers;

use App\Console\Commands\CreateTeamAccountsCommand;
use App\Http\Requests\UserRequest;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::with(['roles', 'healthZone', 'commune', 'team'])->orderBy('name')->paginate(25)]);
    }

    public function create(): View
    {
        return $this->form(new User(['is_active' => true]));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create($this->attributes($request) + ['password' => $request->validated('password'), 'must_change_password' => true]);
        $user->syncRoles([$request->validated('role')]);
        AuditLog::record('user.created', $user, ['email' => $user->email, 'role' => $request->validated('role')]);

        return redirect()->route('users.index')->with('success', "Utilisateur {$user->name} créé.");
    }

    public function edit(User $user): View
    {
        return $this->form($user);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        if ($user->is($request->user()) && (! $request->boolean('is_active') || $request->validated('role') !== User::ROLE_ADMIN)) {
            return back()->with('danger', 'Vous ne pouvez pas retirer votre propre rôle administrateur ni désactiver votre compte.');
        }
        $attributes = $this->attributes($request);
        if ($request->filled('password')) {
            $attributes['password'] = $request->validated('password');
            $attributes['must_change_password'] = ! $user->is($request->user());
        }
        $user->update($attributes);
        $user->syncRoles([$request->validated('role')]);
        AuditLog::record('user.updated', $user, ['email' => $user->email, 'role' => $request->validated('role'), 'actif' => $user->is_active]);

        return redirect()->route('users.index')->with('success', "Utilisateur {$user->name} mis à jour.");
    }

    private function form(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'zones' => HealthZone::orderBy('name')->get(),
            'communes' => Commune::with('healthZone')->orderBy('name')->get(),
            'teams' => Team::with('healthZone:id,name')->orderBy('code')->get(),
        ]);
    }

    /** Comptes de connexion des binômes qui n'en ont pas (identifiant : noms des deux agents, mot de passe : password). */
    public function teamAccounts(): RedirectResponse
    {
        $rows = collect(CreateTeamAccountsCommand::sync());
        $created = $rows->where('status', 'créé');
        AuditLog::record('user.comptes_binomes', null, ['crees' => $created->pluck('username')->all()]);

        return back()->with('success', $created->count().' compte(s) binôme créé(s)'.($created->isNotEmpty() ? ' : '.$created->pluck('username')->implode(', ') : '').'. Mot de passe des nouveaux comptes : '.CreateTeamAccountsCommand::DEFAULT_PASSWORD.'.');
    }

    private function attributes(UserRequest $request): array
    {
        $data = $request->validated();
        $supervisor = $data['role'] === User::ROLE_SUPERVISOR;
        $communeId = $supervisor ? ($data['commune_id'] ?? null) : null;

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => isset($data['username']) && $data['username'] !== '' ? mb_strtolower($data['username']) : null,
            'is_active' => $request->boolean('is_active'),
            // La zone d'un superviseur de commune est déduite de la commune.
            'health_zone_id' => $supervisor ? ($communeId ? Commune::find($communeId)?->health_zone_id : ($data['health_zone_id'] ?? null)) : null,
            'commune_id' => $communeId,
            // Binôme : compte binôme, ou superviseur agent d'un binôme (il voit alors aussi l'espace de son binôme).
            'team_id' => in_array($data['role'], [User::ROLE_TEAM, User::ROLE_SUPERVISOR], true) ? ($data['team_id'] ?? null) : null,
        ];
    }
}
