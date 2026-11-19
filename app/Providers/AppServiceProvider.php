<?php

namespace App\Providers;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));

        // L'administrateur a toutes les permissions.
        // Connexion Microsoft 365 (Socialite).
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        Gate::before(fn (User $user) => $user->hasRole(User::ROLE_ADMIN) ? true : null);
    }
}
