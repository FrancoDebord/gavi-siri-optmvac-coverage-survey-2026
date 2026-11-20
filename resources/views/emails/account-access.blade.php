@extends('emails.layout')

@section('title', $reset ? 'Vos accès ont été mis à jour' : 'Votre compte est prêt')
@section('preheader', ($reset ? 'Nouveau mot de passe provisoire' : 'Vos identifiants de connexion').' pour la plateforme GAVI SIRI Round 2.')

@section('content')
    <p style="margin:0 0 6px; font-size:13px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:#c10202;">{{ $reset ? 'Accès mis à jour' : 'Bienvenue' }}</p>
    <h1 style="margin:0 0 16px; font-size:22px; line-height:30px; color:#1f1f1f;">Bonjour {{ $user->name }},</h1>
    <p style="margin:0 0 16px; font-size:15px; line-height:24px;">
        @if ($reset)
            Un nouveau mot de passe provisoire a été défini pour votre compte sur la plateforme de suivi de l'enquête de couverture vaccinale.
        @else
            Un compte a été créé pour vous sur la plateforme de suivi de l'enquête de couverture vaccinale <strong>GAVI SIRI · Round 2</strong>.
        @endif
        Votre rôle : <strong>{{ $role }}</strong>@if ($scope) — {{ $scope }}@endif.
    </p>

    {{-- Identifiants --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fbf3f3; border:1px solid #f0d2d2; border-radius:10px; margin:8px 0 24px;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:12px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:#7a0101; padding-bottom:10px;">Vos identifiants</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px; line-height:22px;">
                    <tr>
                        <td style="color:#706d6b; padding:4px 12px 4px 0; width:150px; vertical-align:top;">Adresse</td>
                        <td style="padding:4px 0;"><a href="{{ $loginUrl }}" style="color:#c10202; font-weight:600;">{{ preg_replace('#^https?://#', '', $loginUrl) }}</a></td>
                    </tr>
                    <tr>
                        <td style="color:#706d6b; padding:4px 12px 4px 0; vertical-align:top;">Identifiant</td>
                        <td style="padding:4px 0;"><span style="font-family:Consolas, 'Courier New', monospace; font-size:15px; font-weight:700; color:#1f1f1f;">{{ $login }}</span>
                            @if ($user->username)<div style="font-size:12px; color:#706d6b;">ou votre adresse e-mail {{ $user->email }}</div>@endif</td>
                    </tr>
                    @if ($password)
                        <tr>
                            <td style="color:#706d6b; padding:4px 12px 4px 0; vertical-align:top;">Mot de passe provisoire</td>
                            <td style="padding:4px 0;"><span style="display:inline-block; font-family:Consolas, 'Courier New', monospace; font-size:16px; font-weight:700; letter-spacing:0.5px; color:#1f1f1f; background-color:#ffffff; border:1px dashed #c10202; border-radius:6px; padding:4px 10px;">{{ $password }}</span></td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Bouton --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
        <tr>
            <td style="border-radius:8px; background-color:#c10202;">
                <a href="{{ $loginUrl }}" style="display:inline-block; padding:13px 28px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:8px;">Se connecter à la plateforme</a>
            </td>
        </tr>
    </table>

    {{-- Instructions --}}
    <h2 style="margin:0 0 12px; font-size:16px; color:#1f1f1f;">Pour votre première connexion</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px; line-height:22px; margin-bottom:20px;">
        @foreach ($steps as $i => $step)
            <tr>
                <td style="width:34px; vertical-align:top; padding:0 0 12px;">
                    <span style="display:inline-block; width:24px; height:24px; line-height:24px; text-align:center; border-radius:12px; background-color:#c10202; color:#ffffff; font-size:13px; font-weight:700;">{{ $i + 1 }}</span>
                </td>
                <td style="vertical-align:top; padding:2px 0 12px;">{!! $step !!}</td>
            </tr>
        @endforeach
    </table>

    @if ($roleTips)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-left:4px solid #c10202; background-color:#faf8f7; margin:0 0 20px;">
            <tr>
                <td style="padding:14px 18px; font-size:14px; line-height:22px;">
                    <div style="font-weight:700; padding-bottom:6px;">Ce que vous ferez sur la plateforme</div>
                    @foreach ($roleTips as $tip)<div style="padding:2px 0;">• {!! $tip !!}</div>@endforeach
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:0; font-size:13px; line-height:20px; color:#706d6b;">
        <strong style="color:#1f1f1f;">Sécurité :</strong> ce mot de passe est provisoire et personnel. Ne le communiquez à personne ;
        la plateforme vous demandera d'en choisir un nouveau dès la connexion. Vous pouvez ensuite supprimer ce message.
    </p>
@endsection

@section('footer')
    @if ($sender)
        Compte {{ $reset ? 'mis à jour' : 'créé' }} par {{ $sender->name }}. Pour toute question, répondez simplement à ce message.
    @else
        Message envoyé automatiquement par la plateforme.
    @endif
@endsection
