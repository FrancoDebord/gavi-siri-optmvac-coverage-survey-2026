{!! $reset ? 'ACCÈS MIS À JOUR' : 'BIENVENUE' !!} — GAVI SIRI · Round 2

Bonjour {!! $user->name !!},

{!! $reset ? 'Un nouveau mot de passe provisoire a été défini pour votre compte sur la plateforme de suivi de l\'enquête de couverture vaccinale.' : 'Un compte a été créé pour vous sur la plateforme de suivi de l\'enquête de couverture vaccinale GAVI SIRI · Round 2.' !!}
Votre rôle : {!! $role !!}{!! $scope ? ' — '.$scope : '' !!}.

VOS IDENTIFIANTS
Adresse : {!! $loginUrl !!}
Identifiant : {!! $login !!}@if ($user->username) (ou votre adresse e-mail {!! $user->email !!})@endif

@if ($password)
Mot de passe provisoire : {!! $password !!}
@endif

POUR VOTRE PREMIÈRE CONNEXION
@foreach ($steps as $i => $step)
{!! $i + 1 !!}. {!! html_entity_decode(strip_tags($step), ENT_QUOTES, 'UTF-8') !!}
@endforeach
@if ($roleTips)

CE QUE VOUS FEREZ SUR LA PLATEFORME
@foreach ($roleTips as $tip)
- {!! html_entity_decode(strip_tags($tip), ENT_QUOTES, 'UTF-8') !!}
@endforeach
@endif

Sécurité : ce mot de passe est provisoire et personnel. Ne le communiquez à personne ; la plateforme vous demandera d'en choisir un nouveau dès la connexion.

{!! $sender ? 'Compte '.($reset ? 'mis à jour' : 'créé').' par '.$sender->name.'. Pour toute question, répondez à ce message.' : 'Message envoyé automatiquement par la plateforme.' !!}
AIRID — {!! config('app.name') !!}
