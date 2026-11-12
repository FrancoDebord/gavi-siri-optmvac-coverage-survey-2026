<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Agent de terrain (liste l1_fieldworkers du formulaire), membre d'un binôme. */
class Collector extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'initials', 'phone', 'health_zone_id', 'team_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
