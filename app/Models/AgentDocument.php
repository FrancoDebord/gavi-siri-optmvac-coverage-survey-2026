<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Facture ou rapport d'activité déposé par un agent dans un espace de documents (fichier sur le disque privé). */
class AgentDocument extends Model
{
    public const DISK = 'local';

    public const TYPES = ['facture' => 'Facture', 'rapport' => 'Rapport d\'activité'];

    public const STATUS_RECEIVED = 'recu';

    public const STATUS_VALID = 'valide';

    public const STATUS_REDO = 'a_refaire';

    public const STATUSES = [self::STATUS_RECEIVED => 'Reçu', self::STATUS_VALID => 'Validé', self::STATUS_REDO => 'À refaire'];

    /** Formats acceptés : extension → types MIME possibles (les fichiers Office sont parfois détectés comme ZIP). */
    public const FORMATS = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
    ];

    protected $fillable = [
        'document_space_id', 'collector_id', 'team_id', 'author_name', 'type', 'comment', 'path', 'original_name', 'mime', 'size',
        'status', 'review_note', 'reviewed_by', 'reviewed_at', 'uploader_ip',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Fichier supprimé avec le document.
        static::deleted(fn (AgentDocument $document) => Storage::disk(self::DISK)->delete($document->path));
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(DocumentSpace::class, 'document_space_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Agent affiché : agent de l'enquête (nom et code), sinon nom saisi. */
    public function authorLabel(): string
    {
        if ($this->collector) {
            return $this->collector->name.' ('.$this->collector->code.')';
        }

        return $this->author_name ?: 'Agent non renseigné';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION) ?: 'pdf');
    }

    /** Affichage dans la visionneuse : image, PDF, ou autre (Word, Excel : téléchargement). */
    public function viewer(): string
    {
        return match ($this->extension()) {
            'jpg', 'jpeg', 'png', 'webp' => 'image',
            'pdf' => 'pdf',
            default => 'other',
        };
    }

    /** Icône Bootstrap selon le format. */
    public function icon(): string
    {
        return match ($this->extension()) {
            'pdf' => 'file-earmark-pdf',
            'doc', 'docx' => 'file-earmark-word',
            'xls', 'xlsx' => 'file-earmark-excel',
            default => 'file-earmark-image',
        };
    }

    /** Nom de fichier lisible (téléchargement, archive ZIP) : type, agent, date, numéro. */
    public function downloadName(): string
    {
        $parts = array_filter([
            $this->type,
            $this->collector?->code,
            $this->collector?->name ?? $this->author_name,
            $this->created_at?->format('Ymd'),
            (string) $this->id,
        ]);
        $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', implode('-', $parts)) ?: (string) $this->id), '-');

        return $base.'.'.$this->extension();
    }

    /** Dossier de l'agent dans l'archive ZIP. */
    public function folderName(): string
    {
        $label = $this->collector ? $this->collector->code.'-'.$this->collector->name : ($this->author_name ?: 'sans-nom');

        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: 'agent'), '-') ?: 'agent';
    }
}
