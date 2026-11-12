<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catégorie de décision de terrain (administratives, sampling, collecte, lecture de carnet, autres ajoutées). */
class DecisionCategory extends Model
{
    public const COLORS = [
        'primary' => 'Bleu', 'success' => 'Vert', 'warning' => 'Orange', 'info' => 'Cyan',
        'danger' => 'Rouge', 'dark' => 'Noir', 'secondary' => 'Gris',
    ];

    protected $fillable = ['name', 'color', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(FieldDecision::class);
    }
}
