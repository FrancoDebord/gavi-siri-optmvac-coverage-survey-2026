<?php

namespace App\Services\Quality;

/**
 * Portée d'évaluation : le ménage, une instance de caregiver, un enfant ou une
 * instance d'un autre repeat. Les ${variables} sont résolues dans la portée
 * courante puis dans les portées parentes (comme dans ODK Collect).
 */
final class Scope
{
    public const HOUSEHOLD = 'household';

    public const CAREGIVER = 'caregiver';

    public const CHILD = 'child';

    public const REPEAT = 'repeat';

    /** @var array<string, bool|null> mémo des conditions évaluées */
    public array $memo = [];

    public function __construct(
        public readonly string $level,
        public readonly ?string $repeat,
        public readonly array $data,
        public readonly ?Scope $parent = null,
        public readonly string $path = '',
        public readonly ?int $caregiverId = null,
        public readonly ?int $childId = null,
        public readonly int $position = 0,
    ) {}

    public function get(string $name): mixed
    {
        for ($scope = $this; $scope; $scope = $scope->parent) {
            if (array_key_exists($name, $scope->data)) {
                return $scope->data[$name];
            }
        }

        return null;
    }

    /** Valeur propre à cette portée (sans remonter aux parents). */
    public function own(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function filled(string $name): bool
    {
        $value = $this->get($name);

        return $value !== null && $value !== '' && $value !== [];
    }

    public function label(): string
    {
        return match ($this->level) {
            self::CAREGIVER => 'Caregiver n°'.$this->position,
            self::CHILD => 'Enfant n°'.$this->position.' (caregiver n°'.($this->parent?->position ?? '?').')',
            self::REPEAT => ($this->repeat ?? 'Repeat').' n°'.$this->position,
            default => 'Ménage',
        };
    }
}
