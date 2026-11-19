<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MicrosoftLoginController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mot de passe initial (comptes binômes) ou fixé par l'administrateur : l'utilisateur doit le changer avant
 * d'accéder à la plateforme. Seuls le profil, le changement de mot de passe et la déconnexion restent ouverts.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Connexion par Microsoft 365 : le mot de passe local n'est pas utilisé.
        $viaMicrosoft = $request->hasSession() && $request->session()->get(MicrosoftLoginController::SESSION_KEY);
        if ($user?->must_change_password && ! $viaMicrosoft && ! $request->routeIs('profile.edit', 'user-password.update', 'logout', 'login', 'session.token')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Changez votre mot de passe avant de continuer.'], 403);
            }

            return redirect()->route('profile.edit')->with('warning', 'Première connexion : choisissez votre propre mot de passe pour continuer.');
        }

        return $next($request);
    }
}
