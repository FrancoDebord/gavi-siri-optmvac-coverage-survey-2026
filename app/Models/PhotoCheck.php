<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écart relevé entre une saisie et la photo du document (carnet, acte de naissance).
 * expected : valeur lue sur la photo ; '' = doit être vide ; null = non vérifiable (clôture manuelle).
 */
class PhotoCheck extends Model
{
    protected $fillable = ['submission_uuid', 'instance_path', 'variable', 'expected', 'photo', 'message', 'suggestion', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
