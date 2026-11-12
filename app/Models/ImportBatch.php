<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $fillable = [
        'user_id', 'type', 'format', 'status', 'files', 'total', 'processed',
        'new_count', 'updated_count', 'message',
    ];

    protected function casts(): array
    {
        return ['files' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progress(): int
    {
        if ($this->status === 'done') {
            return 100;
        }

        return $this->total > 0 ? (int) floor($this->processed * 100 / $this->total) : 0;
    }
}
