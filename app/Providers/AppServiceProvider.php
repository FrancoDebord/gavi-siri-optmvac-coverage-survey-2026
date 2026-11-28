<?php

namespace App\Providers;

use App\Mail\Transport\GraphTransport;
use App\Models\SupervisionObservation;
use App\Models\User;
use App\Policies\DataQueryPolicy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
        // Envoi des e-mails par Microsoft Graph (MAIL_MAILER=graph).
        Mail::extend('graph', fn () => GraphTransport::fromConfig());

        // Connexion Microsoft 365 (Socialite).
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        Gate::before(fn (User $user) => $user->hasRole(User::ROLE_ADMIN) ? true : null);
        // Observations de supervision : mêmes droits que les queries (périmètre, validation).
        Gate::policy(SupervisionObservation::class, DataQueryPolicy::class);
    }
}
