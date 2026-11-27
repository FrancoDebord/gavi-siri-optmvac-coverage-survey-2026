<?php

namespace App\Mail;

use App\Models\AgentDocument;
use App\Models\DocumentSpace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Factures et rapports d'activité envoyés par e-mail : récapitulatif par agent, lien de téléchargement sécurisé
 * (ZIP, durée limitée) et, si la sélection est légère, les fichiers en pièces jointes.
 */
class AgentDocumentsMail extends Mailable
{
    use Queueable;

    /** @param Collection<int, AgentDocument> $documents */
    public function __construct(
        public DocumentSpace $space,
        public Collection $documents,
        public string $link,
        public CarbonInterface $expires,
        public string $subjectLine,
        public ?string $note,
        public bool $attachFiles,
        public ?User $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->sender?->email ? [new Address($this->sender->email, $this->sender->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-documents', text: 'emails.agent-documents-text', with: $this->details());
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->attachFiles) {
            return [];
        }

        return $this->documents->map(fn (AgentDocument $document) => Attachment::fromStorageDisk(AgentDocument::DISK, $document->path)
            ->as($document->downloadName()))->all();
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        // Une ligne par agent : nombre de fichiers et statut le plus récent de chaque type.
        $rows = $this->documents->groupBy(fn (AgentDocument $d) => $d->authorLabel())->map(function (Collection $docs, string $agent) {
            $row = ['agent' => $agent, 'team' => $docs->first()->team?->code];
            foreach (array_keys(AgentDocument::TYPES) as $type) {
                $ofType = $docs->where('type', $type);
                $row[$type] = $ofType->isEmpty() ? null : ['count' => $ofType->count(), 'status' => $ofType->sortBy('id')->last()->statusLabel()];
            }

            return $row;
        })->sortKeys()->values();

        return [
            'space' => $this->space,
            'rows' => $rows,
            'note' => $this->note,
            'link' => $this->link,
            'expires' => $this->expires,
            'attached' => $this->attachFiles,
            'sender' => $this->sender,
            'counts' => collect(AgentDocument::TYPES)->map(fn ($label, $type) => $this->documents->where('type', $type)->count()),
            'size' => $this->documents->sum('size'),
        ];
    }
}
