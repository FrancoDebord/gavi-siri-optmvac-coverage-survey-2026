<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Étape du suivi d'un incident : commentaire et/ou changement de statut. */
class FieldIncidentUpdate extends Model
{
    protected $fillable = ['field_incident_id', 'user_id', 'from_status', 'to_status', 'comment'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(FieldIncident::class, 'field_incident_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
