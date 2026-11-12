<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Child extends Model
{
    use HasFactory;

    protected $table = 'children';

    protected $fillable = [
        'household_id', 'caregiver_id', 'position', 'repeat_key', 'sex', 'date_of_birth',
        'age_months', 'eligible', 'card_seen', 'data',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'eligible' => 'boolean',
            'card_seen' => 'boolean',
            'data' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function caregiver(): BelongsTo
    {
        return $this->belongsTo(Caregiver::class);
    }
}
