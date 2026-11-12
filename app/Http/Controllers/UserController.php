<?php

namespace App\Http\Controllers;

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
        $user = User::create($this->attributes($request) + ['password' => $request->validated('password')]);
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

    private function attributes(UserRequest $request): array
    {
        $data = $request->validated();
        $supervisor = $data['role'] === User::ROLE_SUPERVISOR;
        $communeId = $supervisor ? ($data['commune_id'] ?? null) : null;

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $request->boolean('is_active'),
            // La zone d'un superviseur de commune est déduite de la commune.
            'health_zone_id' => $supervisor ? ($communeId ? Commune::find($communeId)?->health_zone_id : ($data['health_zone_id'] ?? null)) : null,
            'commune_id' => $communeId,
            'team_id' => $data['role'] === User::ROLE_TEAM ? ($data['team_id'] ?? null) : null,
        ];
    }
}
