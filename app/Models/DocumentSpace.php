<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Espace de dépôt des factures et rapports d'activité : lien public secret (token) et code PIN, sans compte. Lié à
 * l'enquête, l'agent se choisit dans la liste des agents de terrain ; sinon, nom libre. Contrairement aux espaces
 * photos, les agents ne voient pas les documents des autres : seuls les gestionnaires les consultent.
 */
class DocumentSpace extends Model
{
    protected $fillable = ['name', 'description', 'linked_to_survey', 'active', 'created_by'];

    protected $hidden = ['pin', 'token'];

    protected function casts(): array
    {
        return ['pin' => 'encrypted', 'linked_to_survey' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (DocumentSpace $space) {
            $space->token ??= PhotoProject::newToken();
            $space->pin ??= PhotoProject::newPin();
        });
    }

    /** Espaces ouverts de l'enquête, proposés dans l'espace des binômes. */
    public static function openForTeams(): Builder
    {
        return self::query()->where('active', true)->where('linked_to_survey', true)->latest('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AgentDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Lien public à partager aux agents. */
    public function publicUrl(): string
    {
        return route('documents.public.show', $this->token);
    }

    /** Clé de session : PIN déjà saisi sur cet appareil (révoquée en changeant le lien ou le PIN). */
    public function sessionKey(): string
    {
        return 'document_space.'.$this->id.'.'.substr(hash('sha256', $this->token.'|'.$this->pin), 0, 16);
    }

    /** Clé de session : documents déposés depuis cet appareil (seuls documents que l'agent revoit). */
    public function uploadsKey(): string
    {
        return 'document_space_uploads.'.$this->id;
    }
}
