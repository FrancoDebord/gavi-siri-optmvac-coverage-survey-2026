<?php

namespace App\Models;

use App\Models\Concerns\HasGeographicScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Query de données (valeur suspecte à corriger ou confirmer), selon un cycle conforme aux BPC/GCP :
 * Ouverte → Assignée → Réponse (correction proposée, valeur confirmée) → Validation par le data manager → Fermée.
 * Les observations de supervision (paradonnées) partagent la table mais sont exclues par le scope global « kind »
 * (voir SupervisionObservation).
 */
class DataQuery extends Model
{
    use HasFactory, HasGeographicScope;

    public const KIND = 'query';

    protected static function booted(): void
    {
        $kind = static::KIND;
        static::addGlobalScope('kind', fn (Builder $query) => $query->where($query->qualifyColumn('kind'), $kind));
        static::creating(fn (self $model) => $model->kind ??= $kind);
    }

    public const STATUS_OPEN = 'ouverte';

    public const STATUS_SENT = 'envoyee';

    public const STATUS_JUSTIFIED = 'justifiee';

    public const STATUS_CORRECTED = 'corrigee';

    public const STATUS_VALIDATED = 'validee';

    /** La donnée ne peut être ni corrigée ni confirmée (ménage injoignable, carnet perdu…). */
    public const STATUS_UNRESOLVABLE = 'irresoluble';

    public const STATUSES = [
        self::STATUS_OPEN => 'Ouverte',
        self::STATUS_SENT => 'Assignée',
        self::STATUS_JUSTIFIED => 'Valeur confirmée – à valider',
        self::STATUS_CORRECTED => 'Correction proposée – à valider',
        self::STATUS_VALIDATED => 'Fermée (validée)',
        self::STATUS_UNRESOLVABLE => 'Irrésoluble (fermée)',
    ];

    public const STATUS_COLORS = [
        self::STATUS_OPEN => 'danger',
        self::STATUS_SENT => 'warning',
        self::STATUS_JUSTIFIED => 'secondary',
        self::STATUS_CORRECTED => 'dark',
        self::STATUS_VALIDATED => 'success',
        self::STATUS_UNRESOLVABLE => 'irresoluble',
    ];

    /**
     * Transitions autorisées du cycle de vie (l'affectation fait passer Ouverte ↔ Assignée, voir QueryWorkflow::assign).
     * Réponse : Ouverte/Assignée → Valeur confirmée / Correction proposée. Validation (data manager) : réponse → Fermée,
     * ou rejet → Ouverte (Assignée si un assigné existe). Irrésoluble : décision du data manager. Réouverture possible.
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_JUSTIFIED, self::STATUS_CORRECTED, self::STATUS_UNRESOLVABLE],
        self::STATUS_SENT => [self::STATUS_JUSTIFIED, self::STATUS_CORRECTED, self::STATUS_UNRESOLVABLE],
        self::STATUS_JUSTIFIED => [self::STATUS_VALIDATED, self::STATUS_OPEN],
        self::STATUS_CORRECTED => [self::STATUS_VALIDATED, self::STATUS_OPEN],
        self::STATUS_VALIDATED => [self::STATUS_OPEN],
        self::STATUS_UNRESOLVABLE => [self::STATUS_OPEN],
    ];

    /** Réponse de l'assigné (à valider). */
    public const RESPONSES = [self::STATUS_JUSTIFIED, self::STATUS_CORRECTED];

    /** Décisions réservées au data manager (permission « validate queries »). */
    public const DECISIONS = [self::STATUS_VALIDATED, self::STATUS_UNRESOLVABLE, self::STATUS_OPEN];

    /** Statuts considérés comme « non résolus ». */
    public const UNRESOLVED = [self::STATUS_OPEN, self::STATUS_SENT];

    /** Statut de résolution simplifié (exports, filtres) : regroupement des statuts détaillés. */
    public const RESOLUTION_UNRESOLVED = 'non_resolu';

    public const RESOLUTION_PENDING = 'a_valider';

    public const RESOLUTION_RESOLVED = 'resolu';

    public const RESOLUTION_UNRESOLVABLE = 'irresoluble';

    public const RESOLUTIONS = [
        self::RESOLUTION_UNRESOLVED => ['label' => 'Non résolu', 'statuses' => [self::STATUS_OPEN, self::STATUS_SENT]],
        self::RESOLUTION_PENDING => ['label' => 'Réponse à valider', 'statuses' => [self::STATUS_JUSTIFIED, self::STATUS_CORRECTED]],
        self::RESOLUTION_RESOLVED => ['label' => 'Résolu', 'statuses' => [self::STATUS_VALIDATED]],
        self::RESOLUTION_UNRESOLVABLE => ['label' => 'Irrésoluble', 'statuses' => [self::STATUS_UNRESOLVABLE]],
    ];

    protected $fillable = [
        'fingerprint', 'quality_rule_id', 'rule_code', 'label', 'severity', 'household_id', 'caregiver_id',
        'child_id', 'submission_uuid', 'household_code', 'health_zone_id', 'commune_id', 'village_id',
        'cluster_id', 'collector_id', 'collection_date', 'variable', 'variable_label', 'instance_path', 'observed_value',
        'expected', 'message', 'suggestion', 'status', 'kind', 'assigned_to', 'assigned_by', 'assigned_at', 'due_on',
        'responded_by', 'responded_at', 'validated_by', 'detected_at', 'last_detected_at',
        'resolved_at', 'closed_at', 'auto_closed',
    ];

    /** Code lisible de la query, ex. Q-000123 (dérivé de l'identifiant, donc stable). */
    public static function codeFor(int $id): string
    {
        return 'Q-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** Identifiant à partir d'un code saisi (« Q-000123 », « q123 », « 123 »). */
    public static function idFromCode(string $code): ?int
    {
        return preg_match('/^\s*Q?-?0*(\d+)\s*$/i', $code, $m) ? (int) $m[1] : null;
    }

    public static function resolutionOf(string $status): string
    {
        foreach (self::RESOLUTIONS as $key => $group) {
            if (in_array($status, $group['statuses'], true)) {
                return $key;
            }
        }

        return self::RESOLUTION_UNRESOLVED;
    }

    public function getCodeAttribute(): string
    {
        return self::codeFor($this->id);
    }

    public function resolution(): string
    {
        return self::resolutionOf($this->status);
    }

    public function resolutionLabel(): string
    {
        return self::RESOLUTIONS[$this->resolution()]['label'];
    }

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'responded_at' => 'datetime',
            'due_on' => 'date',
            'auto_closed' => 'boolean',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(QualityRule::class, 'quality_rule_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class)->withoutGlobalScope('included');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /** Échéance dépassée pour une query non résolue. */
    public function isOverdue(): bool
    {
        return $this->due_on !== null && ! $this->isResolved() && $this->due_on->lt(today());
    }

    public function events(): HasMany
    {
        return $this->hasMany(QueryEvent::class, 'data_query_id')->latest('id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isResolved(): bool
    {
        return ! in_array($this->status, self::UNRESOLVED, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'secondary';
    }
}
