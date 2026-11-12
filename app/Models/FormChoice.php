<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormChoice extends Model
{
    public $timestamps = false;

    protected $fillable = ['form_version_id', 'list_name', 'name', 'label', 'extra'];

    protected function casts(): array
    {
        return ['extra' => 'array'];
    }
}
