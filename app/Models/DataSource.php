<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataSource extends Model
{
    use HasFactory;

    public const TYPE_ODK = 'odk_central';

    public const TYPE_KOBO = 'kobotoolbox';

    public const TYPES = [
        self::TYPE_ODK => 'ODK Central',
        self::TYPE_KOBO => 'KoboToolbox',
    ];

    protected $fillable = [
        'name', 'type', 'server_url', 'credentials', 'project_id', 'form_id', 'asset_uid',
        'sync_interval_minutes', 'is_active', 'auth_token', 'auth_token_expires_at',
        'last_synced_at', 'last_submission_at', 'last_status',
    ];

    protected $hidden = ['credentials', 'auth_token'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'auth_token' => 'encrypted',
            'auth_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_submission_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function latestSyncLog(): HasOne
    {
        return $this->hasOne(SyncLog::class)->latestOfMany('started_at');
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isDueForSync(): bool
    {
        return $this->is_active
            && (! $this->last_synced_at || $this->last_synced_at->lte(now()->subMinutes($this->sync_interval_minutes)));
    }
}
