<?php

namespace App\Services\Quality;

use App\Models\Collector;
use App\Models\Household;
use App\Models\Team;
use App\Models\Village;
use Illuminate\Support\Facades\DB;

/**
 * Cohérence binôme / village d'un ménage. Trois indices :
 * - l'agent sélectionné dans le formulaire → binôme « déclaré » ;
 * - le compte ODK qui a envoyé la soumission → binôme « du compte » (binôme sélectionné dans au moins 80 % de ses
 *   soumissions) ;
 * - le code village de l'identifiant ménage (ZONE-VIL-…).
 * Comparés aux villages attribués à chaque binôme (binomes_villages.csv), ils disent qui s'est trompé : l'agent
 * sélectionné, le village coché, ou un binôme réellement hors de ses villages. Utilisé par la règle TEAM_VILLAGE et la
 * page de suivi des villages des binômes.
 */
final class TeamVillageCheck
{
    public const VILLAGE = 'village';        // village coché faux : l'identifiant désigne un village du binôme

    public const AGENT = 'agent';            // agent mal sélectionné : le compte ODK est celui d'un autre binôme

    public const AGENT_VILLAGE = 'agent_village'; // agent et village : compte d'un autre binôme, ID = village de ce binôme

    public const OUTSIDE = 'hors_villages';  // binôme (confirmé par le compte ODK) hors de ses villages

    public const UNKNOWN = 'a_verifier';     // indices insuffisants

    public const CASES = [
        self::VILLAGE => 'Village coché faux',
        self::AGENT => 'Agent mal sélectionné',
        self::AGENT_VILLAGE => 'Agent et village à corriger',
        self::OUTSIDE => 'Binôme hors de ses villages',
        self::UNKNOWN => 'À vérifier avec les équipes',
    ];

    /** Part minimale des soumissions d'un compte ODK pour l'attribuer à un binôme. */
    private const ACCOUNT_SHARE = 0.8;

    private const ACCOUNT_MIN = 10;

    /** @var array<int, int> */
    private array $teamOfCollector = [];

    /** @var array<int, array<int, true>> */
    private array $teamVillages = [];

    /** @var array<int, Team> */
    private array $teams = [];

    /** @var array<int, string> village => libellés des binômes qui le couvrent */
    private array $coveredBy = [];

    /** @var array<int, Village> */
    private array $villages = [];

    /** @var array<string, Village> */
    private array $byShortCode = [];

    /** @var array<string, array{team_id: ?int, total: int, share: float}> compte ODK => binôme */
    private array $accounts = [];

    public static function prepared(): self
    {
        $check = new self;
        $check->prepare();

        return $check;
    }

    public function prepare(): void
    {
        $this->teamOfCollector = Collector::whereNotNull('team_id')->pluck('team_id', 'id')->map(fn ($id) => (int) $id)->all();
        $this->teams = Team::with(['villages:id', 'collectors:id,team_id,name'])->get()->keyBy('id')->all();
        $this->teamVillages = $this->coveredBy = $this->byShortCode = [];
        foreach ($this->teams as $team) {
            foreach ($team->villages as $village) {
                $this->teamVillages[$team->id][$village->id] = true;
                $this->coveredBy[$village->id] = isset($this->coveredBy[$village->id]) ? $this->coveredBy[$village->id].', '.self::teamLabel($team) : self::teamLabel($team);
            }
        }
        $this->villages = Village::get(['id', 'name', 'code', 'short_code', 'health_zone_id', 'arrondissement_id'])->keyBy('id')->all();
        foreach ($this->villages as $village) {
            if ($village->short_code) {
                $this->byShortCode[$village->health_zone_id.'|'.strtoupper($village->short_code)] = $village;
            }
        }
        $this->accounts = self::accountTeams($this->teamOfCollector);
    }

    /**
     * Binôme de chaque compte ODK : celui de l'agent sélectionné dans au moins 80 % de ses soumissions (10 au moins).
     *
     * @return array<string, array{team_id: ?int, total: int, share: float}>
     */
    public static function accountTeams(?array $teamOfCollector = null): array
    {
        $teamOfCollector ??= Collector::whereNotNull('team_id')->pluck('team_id', 'id')->map(fn ($id) => (int) $id)->all();
        $counts = [];
        DB::table('households')->whereNotNull('submitter')->whereNotNull('collector_id')
            ->selectRaw('submitter, collector_id, count(*) as n')->groupBy('submitter', 'collector_id')->get()
            ->each(function ($row) use (&$counts, $teamOfCollector) {
                if ($team = $teamOfCollector[$row->collector_id] ?? null) {
                    $counts[$row->submitter][$team] = ($counts[$row->submitter][$team] ?? 0) + (int) $row->n;
                }
            });
        $accounts = [];
        foreach ($counts as $account => $byTeam) {
            arsort($byTeam);
            $total = array_sum($byTeam);
            $share = reset($byTeam) / max(1, $total);
            $accounts[$account] = ['team_id' => $total >= self::ACCOUNT_MIN && $share >= self::ACCOUNT_SHARE ? (int) key($byTeam) : null, 'total' => $total, 'share' => $share];
        }
        ksort($accounts, SORT_NATURAL);

        return $accounts;
    }

    public static function teamLabel(?Team $team): string
    {
        if (! $team) {
            return '?';
        }
        $names = $team->collectors->map(fn ($c) => trim(str_contains($c->name, ',') ? substr($c->name, strrpos($c->name, ',') + 1) : $c->name))->implode(' & ');

        return $team->code.($names !== '' ? " ({$names})" : '');
    }

    public function team(?int $id): ?Team
    {
        return $id ? ($this->teams[$id] ?? null) : null;
    }

    public function village(?int $id): ?Village
    {
        return $id ? ($this->villages[$id] ?? null) : null;
    }

    /** Binômes qui ont une liste de villages (sinon, rien à contrôler). */
    public function hasLists(): bool
    {
        return $this->teamVillages !== [];
    }

    /**
     * Diagnostic d'un ménage, null s'il est cohérent (ou non contrôlable).
     *
     * @return array{case: string, case_label: string, declared_team: ?Team, account: ?string, account_team: ?Team,
     *     village: ?Village, id_village: ?Village, team_id: ?int, message: string, suggestion: string,
     *     propose_village: ?Village}|null
     */
    public function diagnose(Household $household): ?array
    {
        $declared = $this->teamOfCollector[$household->collector_id] ?? null;
        $account = $household->submitter;
        $accountTeam = $account !== null ? ($this->accounts[$account]['team_id'] ?? null) : null;
        // Binôme qui a réellement collecté : celui du compte ODK s'il est connu, sinon celui de l'agent sélectionné.
        $actual = $accountTeam ?? $declared;
        if (! $actual || ! isset($this->teamVillages[$actual]) || ! $household->village_id) {
            return null;
        }
        $villageOk = isset($this->teamVillages[$actual][$household->village_id]);
        $agentOk = ! $accountTeam || ! $declared || $accountTeam === $declared;
        if ($villageOk && $agentOk) {
            return null;
        }

        $village = $this->villages[$household->village_id] ?? null;
        $idVillage = preg_match('/^\s*[A-Z]{3}\W*([A-Z]{3})/i', (string) $household->household_code, $m)
            ? ($this->byShortCode[$household->health_zone_id.'|'.strtoupper($m[1])] ?? null) : null;
        $idOk = $idVillage && isset($this->teamVillages[$actual][$idVillage->id]);
        $actualLabel = self::teamLabel($this->teams[$actual] ?? null);
        $declaredLabel = self::teamLabel($this->teams[$declared] ?? null);
        $names = collect(array_keys($this->teamVillages[$actual]))->map(fn ($id) => $this->villages[$id]->name ?? null)->filter()->sort()->implode(', ');
        $covered = $this->coveredBy[$household->village_id] ?? null;
        $proposeVillage = null;

        if (! $agentOk) {
            $case = $villageOk ? self::AGENT : ($idOk ? self::AGENT_VILLAGE : self::UNKNOWN);
            $message = "Agent du binôme {$declaredLabel} sélectionné ; données envoyées par le compte « {$account} » (binôme {$actualLabel}).";
            $message .= $villageOk ? '' : " Village « {$village?->name} » hors de ses villages".($idVillage ? " ; l'identifiant indique {$idVillage->name}." : '.');
            $suggestion = "Sélectionner un agent du binôme {$actualLabel}".($case === self::AGENT_VILLAGE ? " et le village {$idVillage->name}" : '').'.';
            if ($case === self::AGENT_VILLAGE) {
                $proposeVillage = $idVillage;
            }
            if ($case === self::UNKNOWN) {
                $suggestion = "Vérifier l'agent et le village avec les binômes {$actualLabel} et {$declaredLabel}.";
            }
        } else {
            $case = $idOk ? self::VILLAGE : ($accountTeam ? self::OUTSIDE : self::UNKNOWN);
            $message = "Village « {$village?->name} » hors des villages du binôme {$actualLabel}".($covered ? " (village du binôme {$covered})" : '').'.';
            $message .= $idVillage ? " L'identifiant ménage indique {$idVillage->name}." : '';
            $suggestion = match ($case) {
                self::VILLAGE => "Corriger le village : {$idVillage->name} (d'après l'identifiant ménage).",
                self::OUTSIDE => 'Binôme hors de ses villages : vérifier avec le superviseur, puis corriger le village ou justifier.',
                default => 'Vérifier le village et l\'agent avec le binôme. Corriger, ou justifier.',
            };
            if ($case === self::VILLAGE) {
                $proposeVillage = $idVillage;
            }
        }

        return [
            'case' => $case,
            'case_label' => self::CASES[$case],
            'declared_team' => $this->teams[$declared] ?? null,
            'account' => $account,
            'account_team' => $this->teams[$accountTeam] ?? null,
            'village' => $village,
            'id_village' => $idVillage,
            // Binôme concerné en plus de celui de l'agent sélectionné (il voit la query et la traite).
            'team_id' => $actual,
            'message' => $message,
            'suggestion' => $suggestion,
            'villages' => $names,
            'propose_village' => $proposeVillage,
        ];
    }
}
