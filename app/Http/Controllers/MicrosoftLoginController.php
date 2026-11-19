<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Connexion par Microsoft 365 (Entra ID), limitée au tenant configuré. Le compte doit déjà exister dans la plateforme
 * (même adresse e-mail) et être actif : aucun compte n'est créé, rôles et périmètres restant attribués par l'administrateur.
 */
class MicrosoftLoginController extends Controller
{
    /** Session : connexion faite par Microsoft (pas de mot de passe local à changer). */
    public const SESSION_KEY = 'auth_via_microsoft';

    public static function enabled(): bool
    {
        return filled(config('services.microsoft.client_id')) && filled(config('services.microsoft.client_secret'))
            && filled(config('services.microsoft.tenant'));
    }

    public function redirect(): SymfonyRedirect
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('microsoft')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        if ($request->filled('error')) {
            return $this->fail('Connexion Microsoft annulée ou refusée.');
        }
        try {
            $account = Socialite::driver('microsoft')->user();
        } catch (Throwable $e) {
            Log::warning('Connexion Microsoft échouée : '.$e->getMessage());

            return $this->fail('Connexion Microsoft impossible. Réessayez ou connectez-vous avec votre mot de passe.');
        }

        // Adresse de messagerie et identifiant Microsoft (UPN) : l'un des deux doit être celui du compte de la plateforme.
        $raw = $account->getRaw();
        $emails = collect([$raw['mail'] ?? null, $raw['userPrincipalName'] ?? null, $account->getEmail()])
            ->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values();
        $user = $emails->isEmpty() ? null : User::whereIn('email', $emails->all())->first();
        if (! $user) {
            AuditLog::create(['user_id' => null, 'action' => 'auth.microsoft_inconnu', 'ip_address' => $request->ip(), 'properties' => ['email' => $emails->first()]]);

            return $this->fail('Aucun compte de la plateforme n\'est associé à '.($emails->first() ?? 'ce compte Microsoft').'. Demandez à l\'administrateur de vous créer un compte avec cette adresse.');
        }
        if (! $user->is_active) {
            return $this->fail('Ce compte est désactivé.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, true);
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::create(['user_id' => $user->id, 'action' => 'auth.login', 'ip_address' => $request->ip(), 'properties' => ['methode' => 'microsoft']]);

        return redirect()->intended(config('fortify.home'));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
