<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'active' => EnsureUserIsActive::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->appendToGroup('web', EnsurePasswordChanged::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Page expirée (jeton CSRF périmé) : pas d'écran d'erreur, retour au formulaire avec la saisie conservée.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Session expirée : rechargez la page.'], 419);
            }
            $previous = url()->previous();
            if ($request->routeIs('logout')) {
                return redirect()->route('login');
            }
            if (! $request->user()) {
                if ($previous && ! str_contains($previous, '/login')) {
                    $request->session()->put('url.intended', $previous);
                }

                return redirect()->route('login')->withInput($request->only('email'))
                    ->with('status', 'Votre session a expiré : reconnectez-vous pour continuer.');
            }

            return redirect()->to($previous)
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->with('warning', 'La page était restée ouverte trop longtemps : elle a été actualisée. Vérifiez votre saisie et renvoyez le formulaire.');
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Action non autorisée pour votre rôle.'], 403);
            }

            // Retour à la page d'accueil du profil ; un binôme qui arrive sur « / » (connexion) y va sans message d'erreur.
            $user = $request->user();
            $home = $user?->homeRoute() ?? route('dashboard');
            if ($user?->hasRole(User::ROLE_TEAM) && $request->routeIs('dashboard')) {
                return redirect()->to($home);
            }

            return redirect()->to($home)->with('danger', 'Action non autorisée pour votre rôle.');
        });
    })->create();
