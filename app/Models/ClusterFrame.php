<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grappe de la base de sondage : ménages dénombrés et nombre de subdivisions (sous-grappes) à faire ; une seule
 * subdivision est tirée et enquêtée, d'où les ménages attendus = dénombrés / subdivisions.
 */
class ClusterFrame extends Model
{
    protected $fillable = ['health_zone_id', 'arrondissement', 'village_label', 'village_ids', 'cluster_number', 'single_cluster', 'households_enumerated', 'subdivisions'];

    protected function casts(): array
    {
        return ['village_ids' => 'array', 'single_cluster' => 'boolean'];
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    /** Ménages attendus dans la subdivision tirée. */
    public function expected(): int
    {
        return (int) round($this->households_enumerated / max(1, $this->subdivisions));
    }
}
