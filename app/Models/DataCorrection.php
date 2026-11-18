<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correction d'une valeur dans la base nettoyée (registre / piste d'audit) : valeur reçue d'ODK, valeur remplacée,
 * nouvelle valeur, motif et source, auteur, décision du data manager. Les valeurs ne sont jamais réécrites :
 * une erreur se rectifie par une nouvelle correction qui remplace la précédente.
 */
class DataCorrection extends Model
{
    public const STATUS_PROPOSED = 'proposee';

    public const STATUS_VALIDATED = 'validee';

    public const STATUS_REJECTED = 'rejetee';

    public const STATUS_SUPERSEDED = 'remplacee';

    /** La valeur reçue d'ODK a changé depuis la proposition : correction suspendue, à revoir. */
    public const STATUS_CONFLICT = 'conflit';

    /** Pseudo-variable : exclusion de la soumission de la base nettoyée (doublon). */
    public const EXCLUSION = '__exclusion';

    public const STATUSES = [
        self::STATUS_PROPOSED => 'Proposée',
        self::STATUS_VALIDATED => 'Validée (appliquée)',
        self::STATUS_REJECTED => 'Rejetée',
        self::STATUS_SUPERSEDED => 'Remplacée',
        self::STATUS_CONFLICT => 'En conflit avec la source',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PROPOSED => 'warning',
        self::STATUS_VALIDATED => 'success',
        self::STATUS_REJECTED => 'secondary',
        self::STATUS_SUPERSEDED => 'light',
        self::STATUS_CONFLICT => 'danger',
    ];

    public const REASONS = [
        'saisie' => 'Erreur de saisie (document source relu)',
        'carnet' => 'Vérifié sur le carnet ou sa photo',
        'acte' => 'Vérifié sur l\'acte de naissance ou un autre document',
        'revisite' => 'Revisite du ménage',
        'appel' => 'Appel au répondant',
        'superviseur' => 'Vérification par le superviseur',
        'autre' => 'Autre (préciser dans le commentaire)',
    ];

    protected $fillable = [
        'submission_uuid', 'household_id', 'data_query_id', 'variable', 'instance_path', 'source_value', 'old_value',
        'new_value', 'reason', 'source_reference', 'comment', 'status', 'proposed_by', 'proposed_at', 'decided_by',
        'decided_at', 'signed', 'decision_comment', 'superseded_by', 'applied_at', 'auto_applied', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'signed' => 'boolean',
            'auto_applied' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class)->withoutGlobalScope('included');
    }

    public function dataQuery(): BelongsTo
    {
        return $this->belongsTo(DataQuery::class)->withoutGlobalScope('kind');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Corrections appliquées à la base nettoyée, dans l'ordre de validation (la dernière l'emporte). */
    public function scopeApplicable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATED)->orderBy('decided_at')->orderBy('id');
    }

    /** Clé du champ corrigé : variable et membre. */
    public function fieldKey(): string
    {
        return $this->variable.'@'.($this->instance_path ?? '');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public function variableLabel(): string
    {
        return $this->variable === self::EXCLUSION ? 'Exclusion de la soumission' : $this->variable;
    }

    public static function display(?string $value): string
    {
        return $value === null || $value === '' ? '(vide)' : $value;
    }
}
