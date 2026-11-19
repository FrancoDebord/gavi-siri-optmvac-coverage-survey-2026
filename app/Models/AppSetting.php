<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres de l'application modifiables par l'administrateur (valeurs JSON), avec valeurs par défaut.
 * Chaque modification est tracée dans le journal d'audit (SettingsController).
 */
class AppSetting extends Model
{
    public const AUTO_ASSIGN = 'queries.auto_assign';

    public const DUE_DAYS = 'queries.due_days';

    public const DOUBLE_VALIDATION = 'queries.double_validation';

    /** Corrections appliquées immédiatement si elles lèvent l'erreur sans en créer (revue a posteriori). */
    public const AUTO_APPLY = 'queries.auto_apply';

    /** Lecture des photos par l'IA (envoi des photos à l'API Anthropic) : désactivée tant que l'administrateur ne l'a pas autorisée. */
    public const AI_READING = 'queries.ai_reading';

    /** Valeurs par défaut : pas d'affectation automatique, échéance 3 jours, double validation des seules queries critiques. */
    public const DEFAULTS = [
        self::AUTO_ASSIGN => 'none',
        self::DUE_DAYS => 3,
        self::DOUBLE_VALIDATION => ['critique'],
        self::AUTO_APPLY => false,
        self::AI_READING => false,
    ];

    /** Affectation automatique des nouvelles queries. */
    public const AUTO_ASSIGN_OPTIONS = [
        'none' => 'Aucune (affectation manuelle par le data manager)',
        'team' => 'Au compte du binôme qui a collecté le ménage',
    ];

    private const CACHE_KEY = 'gavi.app-settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key): mixed
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => self::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $stored) ? $stored[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value, ?User $user = null): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user?->id]);
        Cache::forget(self::CACHE_KEY);
    }

    /** La query exige-t-elle une double validation (4 yeux et signature électronique) ? */
    public static function requiresDoubleValidation(string $severity): bool
    {
        return in_array($severity, (array) self::get(self::DOUBLE_VALIDATION), true);
    }
}
