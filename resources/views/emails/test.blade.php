@extends('emails.layout')

@section('title', 'Test d\'envoi')
@section('preheader', 'La plateforme peut envoyer des e-mails.')

@section('content')
    <p style="margin:0 0 6px; font-size:13px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:#c10202;">Test d'envoi</p>
    <h1 style="margin:0 0 16px; font-size:22px; line-height:30px;">La messagerie fonctionne</h1>
    <p style="margin:0 0 12px; font-size:15px; line-height:24px;">Ce message confirme que la plateforme peut envoyer des e-mails (mode « {{ $mailer }} »{{ $sender ? ', expéditeur '.$sender : '' }}).</p>
    <p style="margin:0; font-size:13px; color:#706d6b;">Envoyé le {{ now()->format('d/m/Y à H:i') }}.</p>
@endsection

@section('footer')
    Message de test envoyé depuis la ligne de commande.
@endsection
