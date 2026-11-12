<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Association d'un champ métier (zone_sanitaire, penta1_date…) à une variable du XLSForm. */
class VariableMapping extends Model
{
    public const CATEGORIES = [
        'geo' => 'Géographie et agents',
        'menage' => 'Ménage',
        'caregiver' => 'Caregiver',
        'enfant' => 'Enfant',
        'vaccin' => 'Dates de vaccination',
        'para' => 'Paradonnées',
    ];

    protected $fillable = ['key', 'label', 'category', 'variable_name', 'value', 'sort'];
}
