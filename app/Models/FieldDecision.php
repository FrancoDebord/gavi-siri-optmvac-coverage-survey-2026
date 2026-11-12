<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Décision prise sur le terrain : quoi, pourquoi, qui, quand, pour quelle portée, et si elle est toujours en vigueur. */
class FieldDecision extends Model
{
    public const STATUS_ACTIVE = 'en_vigueur';

    public const STATUS_REPLACED = 'remplacee';

    public const STATUS_CANCELLED = 'annulee';

    public const STATUSES = [
        self::STATUS_ACTIVE => ['En vigueur', 'success'],
        self::STATUS_REPLACED => ['Remplacée', 'secondary'],
        self::STATUS_CANCELLED => ['Annulée', 'danger'],
    ];

    protected $fillable = [
        'decision_category_id', 'decided_on', 'effective_from', 'title', 'context', 'decision', 'decided_by',
        'health_zone_id', 'village_id', 'cluster_id', 'status', 'replaced_by_id', 'user_id', 'in_summary',
    ];

    protected $attributes = ['in_summary' => true];

    protected function casts(): array
    {
        return [
            'decided_on' => 'date',
            'effective_from' => 'date',
            'in_summary' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DecisionCategory::class, 'decision_category_id');
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** Décisions que celle-ci remplace. */
    public function replaces(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /** Superviseur : décisions générales et celles de sa zone ; autres profils : toutes. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $zoneId = $user->supervisedZoneId();

        return $user->hasRole(User::ROLE_SUPERVISOR)
            ? $query->where(fn ($q) => $q->whereNull('health_zone_id')->orWhere('health_zone_id', $zoneId ?? 0))
            : $query;
    }

    public function getCodeAttribute(): string
    {
        return 'DEC-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }

    /** Ex. « Toutes les zones », « Tchaourou › Sébou › TCH-SEB-01 ». */
    public function scopeLabel(): string
    {
        return collect([$this->healthZone?->name, $this->village?->name, $this->cluster?->code])->filter()->implode(' › ') ?: 'Toutes les zones';
    }
}
