<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Photo de terrain déposée dans un espace photos (fichier original et vignette sur le disque privé). */
class FieldPhoto extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'photo_project_id', 'team_id', 'village_id', 'author_name', 'place', 'caption', 'path', 'thumb_path', 'original_name',
        'mime', 'size', 'width', 'height', 'taken_at', 'latitude', 'longitude', 'uploader_ip',
    ];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    protected static function booted(): void
    {
        // Fichiers supprimés avec la photo.
        static::deleted(function (FieldPhoto $photo) {
            Storage::disk(self::DISK)->delete(array_filter([$photo->path, $photo->thumb_path]));
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(PhotoProject::class, 'photo_project_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    /** Auteur affiché : binôme de l'enquête, sinon nom saisi. */
    public function authorLabel(): string
    {
        return $this->team?->label() ?? ($this->author_name ?: 'Auteur non renseigné');
    }

    /** Lieu affiché : village de l'enquête, sinon lieu saisi. */
    public function placeLabel(): ?string
    {
        return $this->village?->name ?? $this->place;
    }

    /** Nom de fichier lisible (téléchargement, archive ZIP). */
    public function downloadName(): string
    {
        $parts = array_filter([
            ($this->taken_at ?? $this->created_at)?->format('Ymd-His'),
            $this->team?->code ?? $this->author_name,
            $this->placeLabel(),
            (string) $this->id,
        ]);
        $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', implode('-', $parts)) ?: (string) $this->id), '-');

        return $base.'.'.(pathinfo($this->path, PATHINFO_EXTENSION) ?: 'jpg');
    }
}
