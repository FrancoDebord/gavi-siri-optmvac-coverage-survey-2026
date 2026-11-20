<?php

namespace App\Mail;

use App\Http\Controllers\MicrosoftLoginController;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Identifiants d'un compte (création, ou nouveau mot de passe provisoire) et instructions de première connexion,
 * adaptées au rôle. Le mot de passe n'est jamais conservé : il n'existe que dans ce message.
 */
class AccountAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $password,
        public bool $reset = false,
        public ?User $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->reset ? 'Vos accès à la plateforme GAVI SIRI R2 ont été mis à jour' : 'Votre compte sur la plateforme GAVI SIRI R2',
            replyTo: $this->sender?->email ? [new Address($this->sender->email, $this->sender->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-access', text: 'emails.account-access-text', with: $this->details());
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        $user = $this->user->loadMissing(['roles', 'team.collectors', 'healthZone', 'commune']);
        $role = $user->roles->first()?->name;
        $login = $user->username ?: $user->email;
        $loginUrl = route('login');
        $microsoft = MicrosoftLoginController::enabled();

        $steps = [
            'Ouvrez <a href="'.e($loginUrl).'" style="color:#c10202;">la page de connexion</a> sur votre ordinateur ou votre téléphone.',
            'Saisissez votre identifiant <strong>'.e($login).'</strong>'.($this->password ? ' et le mot de passe provisoire ci-dessus' : '').'.',
            'Choisissez <strong>votre propre mot de passe</strong> (10 caractères au moins, avec des lettres et des chiffres) : la plateforme vous le demande à la première connexion.',
        ];
        if ($microsoft && ! $user->username) {
            $steps[] = 'Vous pouvez aussi cliquer sur <strong>« Se connecter avec Microsoft 365 »</strong> avec votre adresse '.e($user->email).', sans mot de passe à retenir.';
        }

        return [
            'user' => $user,
            'password' => $this->password,
            'reset' => $this->reset,
            'sender' => $this->sender,
            'role' => User::ROLES[$role] ?? 'Utilisateur',
            'scope' => match (true) {
                $user->team !== null => 'binôme '.$user->team->label(),
                $user->commune !== null => 'commune de '.$user->commune->name,
                $user->healthZone !== null => 'zone sanitaire de '.$user->healthZone->name,
                default => null,
            },
            'login' => $login,
            'loginUrl' => $loginUrl,
            'steps' => $steps,
            'roleTips' => self::tips($role),
        ];
    }

    /** @return list<string> conseils selon le rôle (HTML simple) */
    private static function tips(?string $role): array
    {
        return match ($role) {
            User::ROLE_TEAM => [
                'Onglet <strong>Queries</strong> : les erreurs relevées sur vos soumissions. Vérifiez la valeur sur le carnet ou la photo, puis corrigez-la ou confirmez-la.',
                'Onglet <strong>Villages et agents</strong> : les ménages enregistrés avec un mauvais village ou un mauvais agent.',
                'Onglets <strong>Progression</strong> et <strong>Grappes</strong> : vos ménages visités et ceux qui restent à faire.',
            ],
            User::ROLE_SUPERVISOR => [
                'Suivi de la collecte de votre zone : progression, soumissions, carte des grappes.',
                'Traitement et validation des queries de vos binômes, observations de supervision.',
            ],
            User::ROLE_VALIDATOR => ['Validation des corrections proposées par les équipes sur les queries qui vous sont confiées.'],
            User::ROLE_DATA_MANAGER => ['Imports, règles de contrôle, affectation et validation des queries, registre des corrections et exports.'],
            User::ROLE_PI => ['Vue d\'ensemble, rapport de synthèse, grappes par village et messages aux équipes.'],
            User::ROLE_READER => ['Consultation des tableaux de bord de suivi de la collecte.'],
            User::ROLE_ADMIN => ['Administration complète : utilisateurs, paramètres, sources de données, exports.'],
            default => [],
        };
    }
}
