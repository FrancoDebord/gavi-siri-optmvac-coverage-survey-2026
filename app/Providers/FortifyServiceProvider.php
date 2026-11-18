<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Vues Blade / Bootstrap (pas de starter kit).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));

        // Les comptes désactivés ne peuvent pas se connecter.
        Fortify::authenticateUsing(function (Request $request) {
            // Connexion par e-mail ou par identifiant (comptes binômes : « nom1.nom2 »).
            $login = mb_strtolower(trim((string) $request->input(Fortify::username())));
            $user = User::where('email', $login)->orWhere('username', $login)->first();
            if ($user && $user->is_active && Hash::check($request->input('password'), $user->password)) {
                $user->forceFill(['last_login_at' => now()])->save();
                AuditLog::create(['user_id' => $user->id, 'action' => 'auth.login', 'ip_address' => $request->ip()]);

                return $user;
            }

            return null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
