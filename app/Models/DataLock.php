<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Gel / dégel de la base nettoyée. Base gelée : aucune correction ni exclusion, et les synchronisations
 * n'écrivent plus dans la base (les changements d'ODK sont repris au dégel). Chaque gel ouvre une version numérotée.
 */
class DataLock extends Model
{
    public const FREEZE = 'gel';

    public const UNFREEZE = 'degel';

    private const CACHE_KEY = 'gavi.data-lock';

    protected $fillable = ['version', 'action', 'reason', 'user_id', 'signed', 'data_export_id', 'stats'];

    protected function casts(): array
    {
        return ['signed' => 'boolean', 'stats' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(DataExport::class, 'data_export_id');
    }

    /** Dernier gel en vigueur, ou null si la base n'est pas gelée. */
    public static function current(): ?self
    {
        if (! Schema::hasTable('data_locks')) {
            return null;
        }
        $id = Cache::remember(self::CACHE_KEY, 60, fn () => optional(self::latest('id')->first(), fn ($lock) => $lock->action === self::FREEZE ? $lock->id : 0) ?? 0);

        return $id ? self::find($id) : null;
    }

    public static function isFrozen(): bool
    {
        return self::current() !== null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function label(): string
    {
        return 'V'.$this->version;
    }
}
