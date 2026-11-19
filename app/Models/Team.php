<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Binôme : deux agents de terrain qui travaillent ensemble (code = codes des agents, ex. 27-48). */
class Team extends Model
{
    protected $fillable = ['code', 'name', 'health_zone_id'];

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function collectors(): HasMany
    {
        return $this->hasMany(Collector::class)->orderBy('code');
    }

    /** Villages que le binôme doit couvrir (binomes_villages.csv) : base de la règle TEAM_VILLAGE. */
    public function villages(): BelongsToMany
    {
        return $this->belongsToMany(Village::class, 'team_village')->orderBy('name');
    }

    /**
     * Binôme dont l'un des agents porte ce nom (nom et prénom, dans n'importe quel ordre, sans accents ni casse) :
     * ex. « FAGBOHOUN Josias » → binôme 44-45 (agent « Fagbohoun, Josias »). Null si aucun ou plusieurs.
     */
    public static function forPerson(string $name): ?self
    {
        $tokens = fn (string $value) => collect(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($value))))->filter(fn ($t) => strlen($t) > 1)->values();
        $person = $tokens($name);
        $matches = self::with('collectors:id,team_id,name')->get()->filter(fn (self $team) => $team->collectors->contains(function ($collector) use ($tokens, $person) {
            $words = $tokens((string) $collector->name);

            return $words->count() >= 2 && $words->diff($person)->isEmpty();
        }));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Ex. « Binôme 27-48 · Akinlabi & Alagbe ». */
    public function label(): string
    {
        return self::labelFor($this->code, $this->name);
    }

    public static function labelFor(?string $code, ?string $name): string
    {
        return $code === null ? 'Sans binôme' : 'Binôme '.$code.($name ? ' · '.$name : '');
    }
}
