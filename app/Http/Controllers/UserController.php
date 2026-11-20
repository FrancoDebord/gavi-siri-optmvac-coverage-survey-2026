<?php

namespace App\Http\Controllers;

use App\Console\Commands\CreateTeamAccountsCommand;
use App\Http\Requests\UserRequest;
use App\Mail\AccountAccessMail;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q')),
            'role' => array_key_exists((string) $request->input('role'), User::ROLES) ? (string) $request->input('role') : null,
            'statut' => in_array($request->input('statut'), ['actif', 'desactive'], true) ? $request->input('statut') : null,
        ];
        $like = '%'.$filters['q'].'%';
        $users = User::with(['roles', 'healthZone', 'commune', 'team'])
            // Recherche rapide : nom, identifiant, e-mail, binôme, zone sanitaire ou commune du périmètre.
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('username', 'like', $like)->orWhere('email', 'like', $like)
                ->orWhereHas('team', fn ($t) => $t->where('code', 'like', $like)->orWhere('name', 'like', $like))
                ->orWhereHas('healthZone', fn ($z) => $z->where('name', 'like', $like))
                ->orWhereHas('commune', fn ($c) => $c->where('name', 'like', $like))))
            ->when($filters['role'], fn ($q, $role) => $q->role($role))
            ->when($filters['statut'], fn ($q, $statut) => $q->where('is_active', $statut === 'actif'))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('users.index', ['users' => $users, 'filters' => $filters, 'total' => User::count()]);
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

        return $this->afterSave($request, $user, "Utilisateur {$user->name} créé.", $request->validated('password'), false);
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

        return $this->afterSave($request, $user, "Utilisateur {$user->name} mis à jour.", $request->filled('password') ? $request->validated('password') : null, true);
    }

    /**
     * Après enregistrement : identifiants envoyés par e-mail si demandé (création, ou nouveau mot de passe provisoire).
     * Le mot de passe n'est pas conservé en clair : il n'existe que dans le message.
     */
    private function afterSave(UserRequest $request, User $user, string $message, ?string $password, bool $reset): RedirectResponse
    {
        $redirect = redirect()->route('users.index');
        if (! $request->boolean('send_access') || $password === null) {
            return $redirect->with('success', $message);
        }
        try {
            Mail::to($user->email, $user->name)->send(new AccountAccessMail($user->fresh(), $password, $reset, $request->user()));
        } catch (Throwable $e) {
            report($e);

            return $redirect->with('warning', $message.' Mais l\'e-mail n\'a pas pu être envoyé : '.$e->getMessage());
        }
        AuditLog::record('user.acces_envoyes', $user, ['email' => $user->email, 'reinitialisation' => $reset]);
        // En local (MAIL_MAILER=log) le message est seulement écrit dans le journal.
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return $redirect->with('warning', $message.' E-mail préparé mais non envoyé : la messagerie est en mode « '.config('mail.default').' » (MAIL_MAILER=graph pour envoyer).');
        }

        return $redirect->with('success', $message.' Identifiants envoyés à '.$user->email.'.');
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
