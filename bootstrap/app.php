<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
