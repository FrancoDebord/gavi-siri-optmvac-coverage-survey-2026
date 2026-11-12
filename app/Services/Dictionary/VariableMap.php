<?php

namespace App\Services\Dictionary;

use App\Models\VariableMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Accès au mapping « champ métier → variable du XLSForm ».
 * La configuration (config/gavi.php) fournit les valeurs par défaut,
 * la table variable_mappings les surcharges faites dans l'interface.
 */
class VariableMap
{
    private const CACHE_KEY = 'gavi.variable-map';

    /** @var array<string, array{variable: string|null, value: string|null}>|null */
    private static ?array $memo = null;

    /** Nom de la variable XLSForm associée à une clé métier. */
    public static function var(string $key): ?string
    {
        $variable = self::all()[$key]['variable'] ?? null;

        return $variable === '' ? null : $variable;
    }

    /** Valeur attendue associée (ex. consentement = '0'). */
    public static function value(string $key, ?string $default = null): ?string
    {
        $value = self::all()[$key]['value'] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** Variable de date d'un antigène (ex. penta1 → Penta1date). */
    public static function vaccineDate(string $code): ?string
    {
        return self::var($code.'_date');
    }

    /**
     * @return array<string, array{variable: string|null, value: string|null}>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $map = [];
        foreach (self::defaults() as $key => $row) {
            $map[$key] = ['variable' => $row['variable_name'], 'value' => $row['value']];
        }

        if (Schema::hasTable('variable_mappings')) {
            $stored = Cache::remember(self::CACHE_KEY, 300, fn () => VariableMapping::all(['key', 'variable_name', 'value'])->toArray());
            foreach ($stored as $row) {
                $map[$row['key']] = ['variable' => $row['variable_name'], 'value' => $row['value']];
            }
        }

        return self::$memo = $map;
    }

    /**
     * Valeurs par défaut, y compris une clé « <antigène>_date » par vaccin.
     *
     * @return array<string, array{label: string, category: string, variable_name: string|null, value: string|null, sort: int}>
     */
    public static function defaults(): array
    {
        $defaults = [];
        $sort = 0;
        foreach (config('gavi.mapping') as $key => [$label, $category, $variable, $value]) {
            $defaults[$key] = ['label' => $label, 'category' => $category, 'variable_name' => $variable, 'value' => $value, 'sort' => ++$sort];
        }
        foreach (config('gavi.vaccines') as $code => $vaccine) {
            $defaults[$code.'_date'] = [
                'label' => 'Date '.$vaccine['label'],
                'category' => 'vaccin',
                'variable_name' => $vaccine['var'].'date',
                'value' => null,
                'sort' => ++$sort,
            ];
        }

        return $defaults;
    }

    /** Crée les lignes manquantes en base avec les valeurs par défaut. */
    public static function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $row) {
            VariableMapping::firstOrCreate(['key' => $key], $row);
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
