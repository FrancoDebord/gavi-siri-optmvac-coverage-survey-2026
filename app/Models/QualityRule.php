<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityRule extends Model
{
    public const CATEGORIES = [
        'auto' => 'Automatique (dictionnaire)',
        'metier' => 'Métier',
        'expression' => 'Expression personnalisée',
    ];

    public const KIND_QUERY = 'query';

    public const KIND_OBSERVATION = 'observation';

    /** Nature : query (valeur à corriger ou confirmer) ou observation de supervision (fait à examiner, hors cycle de résolution). */
    public const KINDS = [
        self::KIND_QUERY => 'Query de données',
        self::KIND_OBSERVATION => 'Observation de supervision',
    ];

    public const SEVERITIES = [
        'critique' => 'Critique',
        'majeure' => 'Majeure',
        'mineure' => 'Mineure',
    ];

    public const SEVERITY_COLORS = [
        'critique' => 'danger',
        'majeure' => 'warning',
        'mineure' => 'secondary',
    ];

    public const SCOPES = [
        'household' => 'Ménage',
        'caregiver' => 'Caregiver',
        'child' => 'Enfant',
    ];

    protected $fillable = [
        'code', 'name', 'description', 'category', 'kind', 'severity', 'scope', 'variables', 'suggestion',
        'expression', 'message', 'parameters', 'handler', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function queries(): HasMany
    {
        return $this->hasMany(DataQuery::class);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}
