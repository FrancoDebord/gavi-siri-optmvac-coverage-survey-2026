<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormVariable extends Model
{
    protected $fillable = [
        'form_version_id', 'position', 'name', 'type', 'base_type', 'list_name', 'label', 'hint',
        'required', 'constraint', 'constraint_message', 'relevant', 'calculation', 'appearance',
        'choice_filter', 'group_path', 'repeat_name',
    ];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function isStructural(): bool
    {
        return in_array($this->base_type, ['begin group', 'end group', 'begin repeat', 'end repeat'], true);
    }
}
