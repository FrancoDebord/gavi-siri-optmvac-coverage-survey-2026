<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthZone extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'country'];

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
