<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Espace photos de terrain d'un projet : lien public secret (token) et code PIN, sans compte. Lié à l'enquête, le
 * formulaire de dépôt propose les binômes et villages ; sinon, nom et lieu libres.
 */
class PhotoProject extends Model
{
    protected $fillable = ['name', 'description', 'linked_to_survey', 'active', 'created_by'];

    protected $hidden = ['pin', 'token'];

    protected function casts(): array
    {
        return ['pin' => 'encrypted', 'linked_to_survey' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (PhotoProject $project) {
            $project->token ??= self::newToken();
            $project->pin ??= self::newPin();
        });
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public static function newPin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Espaces ouverts de l'enquête, proposés dans l'espace des binômes. */
    public static function openForTeams(): Builder
    {
        return self::query()->where('active', true)->where('linked_to_survey', true)->latest('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(FieldPhoto::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Lien public à partager aux binômes. */
    public function publicUrl(): string
    {
        return route('photos.public.show', $this->token);
    }

    /** Clé de session : PIN déjà saisi sur cet appareil (révoquée en changeant le lien ou le PIN). */
    public function sessionKey(): string
    {
        return 'photo_space.'.$this->id.'.'.substr(hash('sha256', $this->token.'|'.$this->pin), 0, 16);
    }
}
