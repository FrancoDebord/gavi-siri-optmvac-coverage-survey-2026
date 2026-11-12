<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arrondissement extends Model
{
    protected $fillable = ['code', 'name', 'commune_id'];

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class);
    }
}
