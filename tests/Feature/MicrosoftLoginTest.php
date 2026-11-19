<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class MicrosoftLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.microsoft' => ['client_id' => 'id', 'client_secret' => 'secret', 'tenant' => 'airid.example', 'redirect' => '/auth/microsoft/callback']]);
    }

    private function microsoftReturns(string $mail, string $upn): void
    {
        $account = (new SocialiteUser)->setRaw(['mail' => $mail, 'userPrincipalName' => $upn])->map(['id' => 'abc', 'email' => $upn]);
        Socialite::shouldReceive('driver->user')->andReturn($account);
    }

    public function test_button_only_shown_when_configured(): void
    {
        $this->get(route('login'))->assertSee('Se connecter avec Microsoft 365');

        config(['services.microsoft.client_id' => null]);
        $this->get(route('login'))->assertDontSee('Se connecter avec Microsoft 365');
        $this->get(route('auth.microsoft'))->assertNotFound();
    }

    public function test_redirects_to_the_organisation_tenant(): void
    {
        $location = $this->get(route('auth.microsoft'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/airid.example/oauth2/v2.0/authorize', $location);
        $this->assertStringContainsString(rawurlencode(url('/auth/microsoft/callback')), $location);
    }

    public function test_existing_active_account_logs_in_without_forced_password_change(): void
    {
        $user = User::factory()->create(['email' => 'agent@airid.example', 'must_change_password' => true]);
        $user->assignRole(User::ROLE_DATA_MANAGER);
        $this->microsoftReturns('Agent@airid.example', 'agent@airid.example');

        $this->get(route('auth.microsoft.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->get(route('queries.index'))->assertOk();
    }

    public function test_unknown_or_disabled_accounts_are_refused(): void
    {
        $this->microsoftReturns('inconnu@airid.example', 'inconnu@airid.example');
        $this->get(route('auth.microsoft.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        User::factory()->create(['email' => 'parti@airid.example', 'is_active' => false]);
        $this->microsoftReturns('parti@airid.example', 'parti@airid.example');
        $this->get(route('auth.microsoft.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
