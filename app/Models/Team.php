<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Binôme : deux agents de terrain qui travaillent ensemble (code = codes des agents, ex. 27-48). */
class Team extends Model
{
    protected $fillable = ['code', 'name', 'health_zone_id'];

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function collectors(): HasMany
    {
        return $this->hasMany(Collector::class)->orderBy('code');
    }

    /** Ex. « Binôme 27-48 · Akinlabi & Alagbe ». */
    public function label(): string
    {
        return self::labelFor($this->code, $this->name);
    }

    public static function labelFor(?string $code, ?string $name): string
    {
        return $code === null ? 'Sans binôme' : 'Binôme '.$code.($name ? ' · '.$name : '');
    }
}
