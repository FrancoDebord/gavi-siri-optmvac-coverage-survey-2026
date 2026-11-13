<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grappe d'enquête, avec ses cibles de ménages et d'enfants. */
class Cluster extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'number', 'village_id', 'commune_id', 'health_zone_id',
        'target_households', 'target_children', 'latitude', 'longitude', 'boundary', 'boundary_file', 'boundary_date',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'boundary' => 'array',
            'boundary_date' => 'date',
        ];
    }

    public function subClusters(): HasMany
    {
        return $this->hasMany(SubCluster::class)->orderBy('number');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    /** Contours pour les cartes (resources/js/lib/map.js › drawBoundaries) ; charger village et subClusters au préalable. */
    public function mapBoundary(bool $withUrl = true): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'number' => $this->number,
            'name' => $this->name,
            'village' => $this->village?->name,
            'url' => $withUrl ? route('clusters.show', $this) : null,
            'boundary' => $this->boundary,
            'sub_clusters' => $this->subClusters->whereNotNull('boundary')
                ->map(fn ($s) => ['number' => $s->number, 'boundary' => $s->boundary])->values()->all(),
        ];
    }

    /** La grappe ou l'une de ses sous-grappes a un contour relevé. */
    public function hasMapBoundary(): bool
    {
        return $this->boundary || $this->subClusters->whereNotNull('boundary')->isNotEmpty();
    }
}
