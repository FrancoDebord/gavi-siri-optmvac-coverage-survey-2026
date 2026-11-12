<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sous-grappe (selected_sub_circuit du formulaire), avec son contour GeoJSON éventuel. */
class SubCluster extends Model
{
    protected $fillable = ['cluster_id', 'number', 'boundary', 'boundary_file', 'boundary_date'];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'boundary_date' => 'date',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }
}
