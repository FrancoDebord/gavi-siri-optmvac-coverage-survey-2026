<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Message de l'investigateur principal aux équipes de terrain. Les messages à un binôme ou à un agent
 * sont lus par le compte du binôme (s'il en a un) et par les superviseurs de la zone, qui les relaient.
 */
class TeamMessage extends Model
{
    public const KINDS = [
        'encouragement' => ['Encouragement', 'success', 'hand-thumbs-up'],
        'avertissement' => ['Avertissement', 'danger', 'exclamation-triangle'],
        'information' => ['Information', 'primary', 'info-circle'],
    ];

    public const TARGETS = [
        'all' => 'Toutes les équipes',
        'zone' => 'Superviseurs d\'une zone',
        'supervisor' => 'Un superviseur',
        'team' => 'Un binôme',
        'collector' => 'Un agent',
    ];

    protected $fillable = ['sender_id', 'target_type', 'target_id', 'health_zone_id', 'kind', 'body'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_message_reads')->withPivot('read_at');
    }

    /** Messages qu'un utilisateur peut lire : tous pour l'équipe centrale, ceux de sa zone (ou à lui) pour un superviseur. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Binôme : messages à toutes les équipes, à son binôme ou à l'un de ses agents.
        if ($user->hasRole(User::ROLE_TEAM)) {
            return $query->where(fn ($q) => $q->where('target_type', 'all')
                ->orWhere(fn ($q) => $q->where('target_type', 'team')->where('target_id', $user->team_id ?? 0))
                ->orWhere(fn ($q) => $q->where('target_type', 'collector')->whereIn('target_id', Collector::where('team_id', $user->team_id ?? 0)->select('id'))));
        }
        if (! $user->hasRole(User::ROLE_SUPERVISOR)) {
            return $query;
        }
        $zoneId = $user->supervisedZoneId();

        return $query->where(fn ($q) => $q->where('target_type', 'all')
            ->orWhere(fn ($q) => $q->where('target_type', 'supervisor')->where('target_id', $user->id))
            ->orWhere(fn ($q) => $q->whereIn('target_type', ['zone', 'team', 'collector'])->where('health_zone_id', $zoneId ?? 0)));
    }

    public function scopeUnreadBy(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('readers', fn ($q) => $q->whereKey($user->id));
    }

    public function isReadBy(User $user): bool
    {
        return $this->relationLoaded('readers')
            ? $this->readers->contains('id', $user->id)
            : $this->readers()->whereKey($user->id)->exists();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function kindColor(): string
    {
        return self::KINDS[$this->kind][1] ?? 'secondary';
    }

    public function kindIcon(): string
    {
        return self::KINDS[$this->kind][2] ?? 'chat-left-text';
    }

    /** Ex. « Binôme 46-47 · Imorou & Djibril », « Superviseurs · Tchaourou ». */
    public function targetLabel(): string
    {
        return match ($this->target_type) {
            'all' => 'Toutes les équipes',
            'zone' => 'Superviseurs · '.($this->healthZone?->name ?? '—'),
            'supervisor' => 'Superviseur · '.(User::find($this->target_id)?->name ?? '—'),
            'team' => ($team = Team::find($this->target_id)) ? $team->label() : 'Binôme supprimé',
            'collector' => 'Agent · '.(Collector::find($this->target_id)?->name ?? '—'),
            default => $this->target_type,
        };
    }
}
