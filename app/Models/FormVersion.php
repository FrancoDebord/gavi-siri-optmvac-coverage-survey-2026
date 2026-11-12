<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormVersion extends Model
{
    protected $fillable = [
        'form_id', 'version', 'title', 'default_language', 'source', 'data_source_id',
        'file_path', 'settings', 'is_active', 'variables_count',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->latest('id')->first();
    }

    public function variables(): HasMany
    {
        return $this->hasMany(FormVariable::class)->orderBy('position');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(FormChoice::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function activate(): void
    {
        static::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
