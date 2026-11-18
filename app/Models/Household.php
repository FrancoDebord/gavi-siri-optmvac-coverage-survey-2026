<?php

namespace App\Models;

use App\Models\Concerns\HasGeographicScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une soumission du formulaire = une visite de ménage. */
class Household extends Model
{
    use HasFactory, HasGeographicScope;

    protected $fillable = [
        'submission_uuid', 'data_source_id', 'import_batch_id', 'form_version_id', 'source_type',
        'health_zone_id', 'commune_id', 'arrondissement_id', 'village_id', 'cluster_id', 'sub_cluster', 'collector_id',
        'household_code', 'visit_number', 'survey_status', 'consented', 'interview_date',
        'started_at', 'ended_at', 'submitted_at', 'duration_minutes', 'latitude', 'longitude',
        'gps_accuracy', 'form_version', 'review_state', 'content_hash', 'data', 'raw_data', 'source_data', 'excluded_at',
    ];

    protected function casts(): array
    {
        return [
            'consented' => 'boolean',
            'interview_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'submitted_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'gps_accuracy' => 'float',
            'data' => 'array',
            'raw_data' => 'array',
            'source_data' => 'array',
            'excluded_at' => 'datetime',
        ];
    }

    /**
     * Base nettoyée : les soumissions exclues (doublons, par correction validée) sont écartées partout
     * (tableaux de bord, moteur, exports nettoyés) ; elles restent dans la base brute.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('included', fn (Builder $query) => $query->whereNull($query->qualifyColumn('excluded_at')));
    }

    /** Les fiches restent consultables, même exclues (piste d'audit). */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutGlobalScope('included')->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public function caregivers(): HasMany
    {
        return $this->hasMany(Caregiver::class)->orderBy('position');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class)->orderBy('caregiver_id')->orderBy('position');
    }

    public function queries(): HasMany
    {
        return $this->hasMany(DataQuery::class);
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
