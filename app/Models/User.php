<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const ROLE_ADMIN = 'administrateur';

    public const ROLE_DATA_MANAGER = 'data-manager';

    public const ROLE_SUPERVISOR = 'superviseur';

    public const ROLE_READER = 'lecteur';

    /** Investigateur principal : vue nationale, rapports, messages aux équipes ; ne traite pas les queries. */
    public const ROLE_PI = 'investigateur';

    /** Binôme d'agents de terrain : ne voit que sa propre progression (users.team_id). */
    public const ROLE_TEAM = 'binome';

    /** Compte de validation : valide ou rouvre les queries dont la validation lui est confiée. */
    public const ROLE_VALIDATOR = 'validateur';

    public const ROLES = [
        self::ROLE_ADMIN => 'Administrateur',
        self::ROLE_PI => 'Investigateur principal',
        self::ROLE_DATA_MANAGER => 'Data manager',
        self::ROLE_SUPERVISOR => 'Superviseur',
        self::ROLE_READER => 'Lecteur',
        self::ROLE_TEAM => 'Binôme (agents de terrain)',
        self::ROLE_VALIDATOR => 'Validateur des queries',
    ];

    /**
     * Zone sanitaire du superviseur (directe, ou celle de sa commune) ; null pour les autres profils.
     */
    public function supervisedZoneId(): ?int
    {
        $scope = $this->geographicScope();

        return match ($scope['column'] ?? null) {
            'health_zone_id' => (int) $scope['id'],
            'commune_id' => (int) Commune::whereKey($scope['id'])->value('health_zone_id'),
            default => null,
        };
    }

    protected $fillable = [
        'name',
        'email',
        'username',
        'must_change_password',
        'password',
        'is_active',
        'health_zone_id',
        'commune_id',
        'team_id',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function healthZone(): BelongsTo
    {
        return $this->belongsTo(HealthZone::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /** Superviseur (ou autre profil) agent d'un binôme : il voit aussi tout ce que voit son binôme. */
    public function hasOwnTeam(): bool
    {
        return $this->team_id !== null && ! $this->hasRole(self::ROLE_TEAM);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Page d'accueil selon le profil. */
    public function homeRoute(): string
    {
        return match (true) {
            // Compte binôme : directement sur ses queries à corriger.
            $this->hasRole(self::ROLE_TEAM) => route('team.queries'),
            // Validateur : directement sur les queries dont la validation lui est confiée.
            $this->hasRole(self::ROLE_VALIDATOR) => route('queries.index', ['a_valider' => 'moi']),
            $this->hasRole(self::ROLE_SUPERVISOR) => route('supervision.index'),
            $this->hasRole(self::ROLE_PI) => route('investigator.index'),
            default => route('dashboard'),
        };
    }

    /**
     * Périmètre géographique : seuls les superviseurs sont restreints.
     *
     * @return array{column: string, id: int}|null
     */
    public function geographicScope(): ?array
    {
        if (! $this->hasRole(self::ROLE_SUPERVISOR)) {
            return null;
        }
        if ($this->commune_id) {
            return ['column' => 'commune_id', 'id' => $this->commune_id];
        }
        if ($this->health_zone_id) {
            return ['column' => 'health_zone_id', 'id' => $this->health_zone_id];
        }

        return null;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->getRoleNames()->first()] ?? '—';
    }
}
