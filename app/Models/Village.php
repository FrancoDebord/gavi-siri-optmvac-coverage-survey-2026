<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Village extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'short_code', 'name', 'arrondissement_id', 'commune_id', 'health_zone_id'];

    public function arrondissement(): BelongsTo
    {
        return $this->belongsTo(Arrondissement::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    /** Nom suivi du code à 3 lettres affecté au village (identifiant ménage), ex. « Sebou (SEB) ». */
    public function displayName(): string
    {
        return self::nameWithCode($this->name, $this->short_code);
    }

    public static function nameWithCode(?string $name, ?string $shortCode): string
    {
        return trim((string) $name).($shortCode ? ' ('.strtoupper($shortCode).')' : '');
    }
}
