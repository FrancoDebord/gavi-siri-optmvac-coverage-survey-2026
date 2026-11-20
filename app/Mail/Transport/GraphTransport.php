<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envoi des e-mails par Microsoft Graph (Microsoft 365) : jeton d'application (client credentials, mis en cache),
 * puis POST /users/{expéditeur}/sendMail. L'application Entra ID doit avoir la permission d'application Mail.Send.
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $sender,
        private readonly ?string $senderName = null,
    ) {
        parent::__construct();
    }

    public static function configured(): bool
    {
        return filled(config('services.ms_graph.tenant_id')) && filled(config('services.ms_graph.client_id'))
            && filled(config('services.ms_graph.client_secret')) && filled(config('services.ms_graph.sender'));
    }

    public static function fromConfig(): self
    {
        $config = config('services.ms_graph');
        if (! self::configured()) {
            throw new RuntimeException('Microsoft Graph : MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET et MS_GRAPH_SENDER_EMAIL sont requis.');
        }

        return new self($config['tenant_id'], $config['client_id'], $config['client_secret'], $config['sender'], $config['sender_name'] ?? null);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $response = Http::withToken($this->token())->acceptJson()->timeout(30)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->sender).'/sendMail', [
                'message' => $this->payload($email),
                'saveToSentItems' => true,
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph : envoi refusé ('.$response->status().') '.($response->json('error.message') ?? $response->body()));
        }
    }

    /** Jeton d'application, réutilisé jusqu'à 5 minutes avant son expiration. */
    public function token(): string
    {
        $key = 'ms-graph-token:'.sha1($this->tenantId.$this->clientId);
        if ($token = Cache::get($key)) {
            return $token;
        }
        $response = Http::asForm()->timeout(20)->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Microsoft Graph : jeton refusé ('.$response->status().') '.($response->json('error_description') ?? $response->body()));
        }
        Cache::put($key, $response->json('access_token'), max(60, (int) $response->json('expires_in', 3600) - 300));

        return $response->json('access_token');
    }

    private function payload(Email $email): array
    {
        $addresses = fn (array $list) => array_map(fn (Address $a) => ['emailAddress' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName() ?: null])], $list);
        $html = $email->getHtmlBody();

        return array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null
                ? ['contentType' => 'HTML', 'content' => is_resource($html) ? stream_get_contents($html) : (string) $html]
                : ['contentType' => 'Text', 'content' => (string) (is_resource($email->getTextBody()) ? stream_get_contents($email->getTextBody()) : $email->getTextBody())],
            // Expéditeur : la boîte autorisée (Graph envoie toujours depuis elle), avec le nom configuré.
            'from' => ['emailAddress' => array_filter(['address' => $this->sender, 'name' => $this->senderName ?: ($email->getFrom()[0] ?? null)?->getName()])],
            'toRecipients' => $addresses($email->getTo()),
            'ccRecipients' => $addresses($email->getCc()),
            'bccRecipients' => $addresses($email->getBcc()),
            'replyTo' => $addresses($email->getReplyTo()),
            'attachments' => array_map(fn ($part) => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?? 'piece-jointe',
                'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'contentBytes' => base64_encode($part->getBody()),
                'isInline' => $part->getDisposition() === 'inline',
                'contentId' => $part->getDisposition() === 'inline' ? $part->getContentId() : null,
            ], $email->getAttachments()),
        ], fn ($value) => $value !== [] && $value !== null);
    }

    public function __toString(): string
    {
        return 'graph://'.$this->sender;
    }
}
