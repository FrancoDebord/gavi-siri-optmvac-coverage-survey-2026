<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envoie un e-mail de test (vérifie la configuration de la messagerie, ex. Microsoft Graph). */
class MailTestCommand extends Command
{
    protected $signature = 'gavi:mail-test {email : adresse destinataire}';

    protected $description = 'Envoie un e-mail de test pour vérifier la configuration de la messagerie (MAIL_MAILER, Microsoft Graph)';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $sender = $mailer === 'graph' ? config('services.ms_graph.sender') : config('mail.from.address');
        $this->line("Messagerie : {$mailer}".($sender ? " · expéditeur {$sender}" : ''));
        try {
            Mail::send(['html' => 'emails.test'], ['mailer' => $mailer, 'sender' => $sender], fn (Message $m) => $m->to($this->argument('email'))->subject('Test d\'envoi – '.config('app.name')));
        } catch (Throwable $e) {
            $this->error('Échec : '.$e->getMessage());

            return self::FAILURE;
        }
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("Mode « {$mailer} » : le message est écrit dans le journal, pas envoyé. Mettre MAIL_MAILER=graph pour envoyer.");
        } else {
            $this->info('E-mail envoyé à '.$this->argument('email').'.');
        }

        return self::SUCCESS;
    }
}
