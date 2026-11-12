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
        'code', 'name', 'description', 'category', 'severity', 'scope', 'variables', 'suggestion',
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
