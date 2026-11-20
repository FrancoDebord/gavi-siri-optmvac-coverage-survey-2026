<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Envoi des identifiants d'un compte par e-mail, et transport Microsoft Graph. */
class AccountMailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Data Admin', 'email' => 'admin@airid.example']);
        $this->admin->assignRole(User::ROLE_ADMIN);
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('users.store'), $extra + [
            'name' => 'Rodrigue Akpovi', 'email' => 'rakpovi@airid.example', 'role' => User::ROLE_DATA_MANAGER, 'is_active' => '1',
            'password' => 'Provisoire2026', 'password_confirmation' => 'Provisoire2026', 'send_access' => '1',
        ]);
    }

    public function test_new_account_receives_its_access_by_email(): void
    {
        Mail::fake();
        $this->create()->assertRedirect(route('users.index'));

        Mail::assertSent(AccountAccessMail::class, function (AccountAccessMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('rakpovi@airid.example') && $mail->hasReplyTo('admin@airid.example')
                && $mail->envelope()->subject === 'Votre compte sur la plateforme GAVI SIRI R2'
                && str_contains($html, 'Provisoire2026') && str_contains($html, 'Bonjour Rodrigue Akpovi')
                && str_contains($html, route('login')) && str_contains($html, 'Data manager')
                && str_contains($html, 'Choisissez <strong>votre propre mot de passe</strong>');
        });
        $this->assertTrue(User::where('email', 'rakpovi@airid.example')->value('must_change_password'));
    }

    public function test_no_email_unless_requested_and_reset_sends_new_password(): void
    {
        Mail::fake();
        $this->create(['send_access' => '0']);
        Mail::assertNothingSent();

        $user = User::where('email', 'rakpovi@airid.example')->firstOrFail();
        $update = fn (array $extra) => $this->actingAs($this->admin)->put(route('users.update', $user), $extra + [
            'name' => $user->name, 'email' => $user->email, 'role' => User::ROLE_DATA_MANAGER, 'is_active' => '1', 'send_access' => '1',
        ]);
        $update([]); // sans nouveau mot de passe : rien à envoyer
        Mail::assertNothingSent();

        $update(['password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        Mail::assertSent(AccountAccessMail::class, fn (AccountAccessMail $mail) => $mail->reset
            && str_contains($mail->render(), 'Nouveau2026x') && str_contains($mail->envelope()->subject, 'mis à jour'));
    }

    public function test_team_account_email_explains_team_tabs(): void
    {
        $html = (new AccountAccessMail(User::factory()->create(['username' => 'kora.barakatou'])->assignRole(User::ROLE_TEAM), 'Abc123defg'))->render();
        $this->assertStringContainsString('kora.barakatou', $html);
        $this->assertStringContainsString('Villages et agents', $html);
    }

    public function test_graph_transport_sends_through_microsoft_graph(): void
    {
        config([
            'mail.default' => 'graph',
            'services.ms_graph' => ['tenant_id' => 'tenant-x', 'client_id' => 'client-x', 'client_secret' => 'secret-x', 'sender' => 'noreply@airid.example', 'sender_name' => 'GAVI SIRI R2'],
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'jeton-test', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        $this->create()->assertSessionHas('success', fn ($message) => str_contains($message, 'Identifiants envoyés à rakpovi@airid.example'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'login.microsoftonline.com/tenant-x/oauth2/v2.0/token')
            && $request['grant_type'] === 'client_credentials' && $request['scope'] === 'https://graph.microsoft.com/.default');
        Http::assertSent(function (Request $request) {
            $message = $request['message'] ?? [];

            return $request->url() === 'https://graph.microsoft.com/v1.0/users/noreply%40airid.example/sendMail'
                && $request->hasHeader('Authorization', 'Bearer jeton-test')
                && $message['subject'] === 'Votre compte sur la plateforme GAVI SIRI R2'
                && $message['body']['contentType'] === 'HTML' && str_contains($message['body']['content'], 'Provisoire2026')
                && $message['toRecipients'][0]['emailAddress']['address'] === 'rakpovi@airid.example'
                && $message['from']['emailAddress'] === ['address' => 'noreply@airid.example', 'name' => 'GAVI SIRI R2']
                && $message['replyTo'][0]['emailAddress']['address'] === 'admin@airid.example';
        });
    }

    public function test_graph_failure_is_reported_without_losing_the_account(): void
    {
        config([
            'mail.default' => 'graph',
            'services.ms_graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'sender' => 'noreply@airid.example', 'sender_name' => null],
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'jeton', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Access is denied.']], 403),
        ]);

        $this->create()->assertSessionHas('warning', fn ($message) => str_contains($message, 'Access is denied'));
        $this->assertDatabaseHas('users', ['email' => 'rakpovi@airid.example']);
    }
}
