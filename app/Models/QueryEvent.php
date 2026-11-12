<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryEvent extends Model
{
    protected $fillable = ['data_query_id', 'user_id', 'type', 'from_status', 'to_status', 'comment'];

    public function dataQuery(): BelongsTo
    {
        return $this->belongsTo(DataQuery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
