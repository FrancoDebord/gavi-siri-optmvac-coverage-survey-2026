<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caregiver extends Model
{
    use HasFactory;

    protected $fillable = ['household_id', 'position', 'repeat_key', 'consented', 'initials', 'sex', 'age_years', 'data'];

    protected function casts(): array
    {
        return [
            'consented' => 'boolean',
            'data' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class)->orderBy('position');
    }
}
