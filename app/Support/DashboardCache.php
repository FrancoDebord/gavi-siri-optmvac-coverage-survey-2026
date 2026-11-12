<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache court des agrégations de dashboard. Chaque synchro, import ou exécution
 * du moteur incrémente une version, ce qui invalide toutes les entrées d'un coup
 * (compatible avec les drivers sans tags : database, file).
 */
class DashboardCache
{
    public static function remember(string $key, array $context, Closure $callback): mixed
    {
        $version = Cache::get('dashboard.version', 0);
        $fullKey = 'dashboard.'.$version.'.'.$key.'.'.md5(json_encode($context));

        return Cache::remember($fullKey, config('gavi.dashboard.cache_seconds'), $callback);
    }

    public static function bump(): void
    {
        Cache::forever('dashboard.version', Cache::get('dashboard.version', 0) + 1);
    }
}
