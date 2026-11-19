<?php

namespace App\Models;

use App\Services\Export\ExportPackager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export généré (administrateur) : base brute, base nettoyée, paquet d'archivage ou copie SQL chiffrée.
 * Fichier ZIP avec manifeste (empreintes SHA-256 de chaque fichier) ; empreinte du ZIP conservée ici.
 */
class DataExport extends Model
{
    public const TYPE_RAW = 'brute';

    public const TYPE_CLEAN = 'nettoyee';

    public const TYPE_ARCHIVE = 'archive';

    public const TYPE_SQL = 'sql';

    public const TYPES = [
        self::TYPE_RAW => 'Base brute (données source ODK)',
        self::TYPE_CLEAN => 'Base nettoyée (corrections validées appliquées)',
        self::TYPE_ARCHIVE => 'Paquet d\'archivage complet',
        self::TYPE_SQL => 'Copie complète de la base de données (SQL chiffré)',
    ];

    public const STATUS_PENDING = 'en_attente';

    public const STATUS_RUNNING = 'en_cours';

    public const STATUS_DONE = 'pret';

    public const STATUS_FAILED = 'echec';

    public const STATUS_EXPIRED = 'expire';

    public const STATUSES = [
        self::STATUS_PENDING => ['En attente', 'secondary'],
        self::STATUS_RUNNING => ['En cours', 'warning'],
        self::STATUS_DONE => ['Prêt', 'success'],
        self::STATUS_FAILED => ['Échec', 'danger'],
        self::STATUS_EXPIRED => ['Expiré (fichier supprimé)', 'light'],
    ];

    /** Durée de conservation des fichiers générés. */
    public const RETENTION_DAYS = 7;

    protected $fillable = [
        'type', 'status', 'file_path', 'file_name', 'file_size', 'sha256', 'manifest', 'error', 'requested_by',
        'started_at', 'finished_at', 'expires_at', 'download_count',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    /**
     * Emplacement actuel du fichier : retrouvé par son nom dans le dossier des exports. Le chemin absolu enregistré
     * pointe vers la release du moment de la génération, supprimée après quelques déploiements.
     */
    public function path(): ?string
    {
        if ($this->file_name) {
            $path = ExportPackager::directory().DIRECTORY_SEPARATOR.basename($this->file_name);
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->file_path && is_file($this->file_path) ? $this->file_path : null;
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_DONE && $this->path() !== null;
    }

    /** Marqué prêt mais fichier introuvable sur le serveur : à regénérer. */
    public function isMissing(): bool
    {
        return $this->status === self::STATUS_DONE && $this->path() === null;
    }

    /** En attente depuis plus de 5 minutes : la file de traitement en arrière-plan ne tourne probablement pas. */
    public function isStuck(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->created_at?->lt(now()->subMinutes(5));
    }
}
