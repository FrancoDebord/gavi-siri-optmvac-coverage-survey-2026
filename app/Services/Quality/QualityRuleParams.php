<?php

namespace App\Services\Quality;

use App\Models\QualityRule;

/** Accès typé aux paramètres (seuils) d'une règle, avec valeurs par défaut du catalogue. */
final class QualityRuleParams
{
    public function __construct(private readonly array $values) {}

    public static function of(QualityRule $rule): self
    {
        $defaults = RuleCatalog::find($rule->code)['parameters'] ?? [];

        return new self(array_merge($defaults, array_filter($rule->parameters ?? [], fn ($v) => $v !== null && $v !== '')));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function float(string $key, float $default = 0): float
    {
        return is_numeric($this->values[$key] ?? null) ? (float) $this->values[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return is_numeric($this->values[$key] ?? null) ? (int) $this->values[$key] : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->values[$key] ?? [];
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value);
        }

        return array_values(array_filter(array_map('trim', (array) $value)));
    }
}
