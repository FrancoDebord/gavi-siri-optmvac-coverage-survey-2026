<?php

namespace App\Models;

use App\Models\Concerns\HasGeographicScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Incident de terrain (sécurité, accès, matériel…) signalé par un superviseur, suivi jusqu'à sa résolution. */
class FieldIncident extends Model
{
    use HasGeographicScope;

    public const STATUS_OPEN = 'ouvert';

    public const STATUS_IN_PROGRESS = 'en_cours';

    public const STATUS_RESOLVED = 'resolu';

    public const STATUSES = [
        self::STATUS_OPEN => 'Ouvert',
        self::STATUS_IN_PROGRESS => 'En cours de traitement',
        self::STATUS_RESOLVED => 'Résolu',
    ];

    public const STATUS_COLORS = [
        self::STATUS_OPEN => 'danger',
        self::STATUS_IN_PROGRESS => 'warning',
        self::STATUS_RESOLVED => 'success',
    ];

    /** Transitions autorisées ; un incident résolu peut être rouvert. */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED],
        self::STATUS_IN_PROGRESS => [self::STATUS_RESOLVED, self::STATUS_OPEN],
        self::STATUS_RESOLVED => [self::STATUS_OPEN],
    ];

    public const UNRESOLVED = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    public const CATEGORIES = [
        'securite' => ['Sécurité', 'shield-exclamation'],
        'acces' => ['Accès / route impraticable', 'sign-stop'],
        'refus' => ['Refus communautaire / autorités', 'people'],
        'materiel' => ['Tablette / matériel', 'tablet'],
        'reseau' => ['Réseau / synchronisation', 'wifi-off'],
        'formulaire' => ['Problème de formulaire', 'ui-checks'],
        'sante' => ['Santé d\'un enquêteur', 'heart-pulse'],
        'logistique' => ['Transport / logistique', 'truck'],
        'meteo' => ['Intempéries', 'cloud-rain-heavy'],
        'autre' => ['Autre', 'three-dots'],
    ];

    public const SEVERITIES = [
        'faible' => ['Faible', 'secondary'],
        'moyenne' => ['Moyenne', 'warning'],
        'elevee' => ['Élevée', 'danger'],
    ];

    protected $fillable = [
        'health_zone_id', 'commune_id', 'village_id', 'cluster_id', 'collector_id', 'occurred_on',
        'category', 'severity', 'title', 'description', 'impact', 'status', 'reported_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'resolved_at' => 'datetime',
        ];
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

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(FieldIncidentUpdate::class)->latest('id');
    }

    public function getCodeAttribute(): string
    {
        return 'INC-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'secondary';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? $this->category;
    }

    public function categoryIcon(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'exclamation-circle';
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity][0] ?? $this->severity;
    }

    public function severityColor(): string
    {
        return self::SEVERITIES[$this->severity][1] ?? 'secondary';
    }
}
