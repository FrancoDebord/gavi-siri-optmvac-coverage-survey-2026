<?php

namespace App\Services\Dashboard;

use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\BeninRuleSet;
use App\Services\Quality\TeamVillageCheck;
use Illuminate\Support\Collection;

/**
 * Restitution au format du document qualité Bénin (cov26c4v2) :
 * 1. synthèse par zone sanitaire et par binôme ; 2. fiche de corrections par binôme (CRITIQUE puis MAJEURE) ;
 * 3. observations de supervision par binôme ; 4. avertissements ; 5. règles non appliquées.
 * Écrit pour les agents : question désignée par son numéro et son libellé (« Q13 – … »), personne par son rang
 * (« Gardien n° 1 », « Enfant n° 2 »), problème et action sans nom de variable. Queries non résolues.
 */
final class CorrectionSheets
{
    public const SEVERITY_LABELS = ['critique' => 'CRITIQUE', 'majeure' => 'MAJEURE', 'mineure' => 'OBSERVATION'];

    /** Colonnes de la fiche (ordre du document). */
    public const COLUMNS = ['N°', 'Sévérité', 'Date', 'Village', 'Ménage', 'Personne', 'Question', 'Valeur saisie', 'Problème', 'Action à faire', 'Code'];

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $zoneId = null,
        private readonly ?int $teamId = null,
    ) {}

    /** Queries non résolues du périmètre (observations de supervision comprises). */
    private function queries(): Collection
    {
        return DataQuery::withoutGlobalScope('kind')
            ->when($this->user, fn ($q, $user) => $q->visibleTo($user))
            ->when($this->zoneId, fn ($q, $zone) => $q->where('health_zone_id', $zone))
            ->whereIn('status', DataQuery::UNRESOLVED)
            ->with(['healthZone:id,name', 'commune:id,name', 'village:id,name', 'collector:id,team_id', 'rule:id,code,suggestion'])
            ->orderBy('household_code')->get()
            ->map(fn (DataQuery $q) => $q->setAttribute('sheet_team_id', $q->team_id ?? $q->collector?->team_id))
            ->when($this->teamId, fn ($c) => $c->where('sheet_team_id', $this->teamId))
            ->values();
    }

    /**
     * @return array{synthesis: list<array>, sheets: list<array{team: ?Team, title: string, corrections: list<array>, observations: list<array>}>, not_applied: list<array{code: string, reason: string}>}
     */
    public function build(): array
    {
        $queries = $this->queries();
        $teams = Team::with(['collectors:id,team_id,name', 'healthZone:id,name'])->get()->keyBy('id');
        $households = Household::query()->when($this->zoneId, fn ($q, $zone) => $q->where('households.health_zone_id', $zone))
            ->join('collectors', 'collectors.id', '=', 'households.collector_id')
            ->selectRaw('collectors.team_id, count(*) as n')->groupBy('collectors.team_id')->pluck('n', 'team_id');

        $sheets = [];
        $synthesis = [];
        $groups = $queries->groupBy(fn ($q) => (int) $q->sheet_team_id)
            ->sortBy(fn ($items, $teamId) => ($teams[$teamId]?->healthZone?->name ?? 'zzz').' '.($teams[$teamId]?->code ?? 'zzz'));
        foreach ($groups as $teamId => $items) {
            $team = $teams[$teamId] ?? null;
            $zone = $team?->healthZone?->name ?? $items->first()->healthZone?->name ?? '—';
            $communes = $items->pluck('commune.name')->filter()->unique()->sort()->implode(', ') ?: '—';
            $label = $team ? TeamVillageCheck::teamLabel($team) : 'Sans binôme';
            $isObservation = fn (DataQuery $q) => $q->kind === QualityRule::KIND_OBSERVATION || $q->severity === 'mineure';
            $corrections = $items->reject($isObservation)->sortBy(fn ($q) => ($q->severity === 'critique' ? 0 : 1).' '.$q->household_code)->values();
            $observations = $items->filter($isObservation)->sortBy('household_code')->values();

            $synthesis[] = [
                'zone' => $zone, 'commune' => $communes, 'team' => $label,
                'households' => (int) ($households[$teamId] ?? 0),
                'critique' => $corrections->where('severity', 'critique')->count(),
                'majeure' => $corrections->where('severity', 'majeure')->count(),
                'observation' => $observations->count(),
            ];
            $sheets[] = [
                'team' => $team,
                'title' => "Binôme {$label} – Zone {$zone} – Commune {$communes}",
                'corrections' => $corrections->values()->map(fn ($q, $i) => self::row($q, $i + 1))->all(),
                'observations' => $observations->values()->map(fn ($q, $i) => self::row($q, $i + 1))->all(),
            ];
        }

        return ['synthesis' => $synthesis, 'sheets' => $sheets, 'not_applied' => self::notApplied()];
    }

    /** Ligne de fiche : N°, sévérité, date, village, ménage, personne, question, valeur, problème, action, code. */
    public static function row(DataQuery $q, int $n): array
    {
        return [
            'n' => $n,
            'severity' => self::SEVERITY_LABELS[$q->kind === QualityRule::KIND_OBSERVATION ? 'mineure' : $q->severity] ?? strtoupper((string) $q->severity),
            'date' => $q->collection_date?->format('d/m/Y'),
            'village' => $q->village?->name,
            'household' => $q->household_code,
            'person' => self::person($q->instance_path),
            'question' => self::question($q->variable_label, $q->variable),
            'value' => self::value($q->observed_value),
            'problem' => self::problem($q->message),
            'action' => self::action($q->suggestion),
            // Codes du document qualité (CRI_15 → CRI-15) ; les autres règles gardent leur code.
            'code' => preg_match('/^(CRI|MAJ|OBS|AVT)_\d+$/', (string) $q->rule_code) ? str_replace('_', '-', (string) $q->rule_code) : $q->rule_code,
            'url' => route('queries.show', $q->id),
        ];
    }

    /** « 13. Combien de personnes… » → « Q13 – Combien de personnes… » (libellé raccourci). */
    public static function question(?string $label, ?string $variable): string
    {
        $label = trim(preg_replace('/\s+/', ' ', strip_tags((string) $label)));
        if ($label === '') {
            return '—';
        }
        $text = preg_match('/^(\d+)\s*[\.\)]\s*(.+)$/u', $label, $m) ? 'Q'.$m[1].' – '.$m[2] : $label;

        return mb_strlen($text) > 90 ? mb_substr($text, 0, 88).'…' : $text;
    }

    /** Action à faire : question citée par son numéro (« 70. Date… » → « Q70 – Date… »). */
    public static function action(?string $suggestion): string
    {
        $text = trim((string) $suggestion);

        return $text === '' ? '—' : preg_replace('/«\s*(\d+)\s*\.\s*/u', '« Q$1 – ', $text);
    }

    /** Rang de la personne : « Gardien n° 1 », « Enfant n° 2 (gardien n° 1) », « Membre n° 3 », « Ménage ». */
    public static function person(?string $path): string
    {
        if (! $path) {
            return 'Ménage';
        }
        $names = [Map::var('repeat_caregivers') => 'Gardien', Map::var('repeat_enfants') => 'Enfant', Map::var('repeat_membres') => 'Membre'];
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^(.+)\[(\d+)\]$/', $segment, $m) && isset($names[$m[1]])) {
                $parts[] = $names[$m[1]].' n° '.$m[2];
            }
        }
        if ($parts === []) {
            return 'Ménage';
        }

        return count($parts) > 1 ? array_pop($parts).' ('.mb_strtolower(implode(', ', $parts)).')' : $parts[0];
    }

    /** Problème sans le rang en tête (déjà dans « Personne ») ni nom de variable. */
    public static function problem(?string $message): string
    {
        $message = trim((string) $message);
        $message = preg_replace('/^(Ménage|Caregiver n°\s?\d+|Enfant n°\s?\d+ \(caregiver n°\s?\d+\)|[A-Za-z0-9_]+ n°\s?\d+)\s*[:–-]\s*/u', '', $message);
        // Nom de variable restant dans le message → question (« Q13 – … ») d'après le dictionnaire.
        $dictionary = Dictionary::current();
        $message = preg_replace_callback('/\b[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+\b/', function ($m) use ($dictionary) {
            $definition = $dictionary->variable($m[0]);

            return $definition ? self::question((string) $definition['label'], $m[0]) : $m[0];
        }, $message);

        return $message === '' ? '—' : mb_strtoupper(mb_substr($message, 0, 1)).mb_substr($message, 1);
    }

    /** Valeur saisie lisible : « variable = valeur ; … » → valeurs seules. */
    public static function value(?string $observed): string
    {
        $observed = trim((string) $observed);
        if ($observed === '') {
            return '—';
        }
        if (str_contains($observed, ' = ')) {
            return implode(' ; ', array_map(fn ($part) => trim(substr($part, strpos($part, ' = ') + 3)), explode(' ; ', $observed)));
        }

        return $observed;
    }

    /** Règles non appliquées : règles désactivées et contrôles du document non repris, avec la raison. */
    public static function notApplied(): array
    {
        $inactive = QualityRule::where('is_active', false)->orderBy('code')->get(['code', 'name'])
            ->map(fn ($r) => ['code' => str_replace('_', '-', $r->code), 'reason' => $r->name.' : règle désactivée (à activer dans « Règles » après examen).'])->all();

        return array_merge($inactive, array_map(fn ($code, $reason) => ['code' => $code, 'reason' => $reason], array_keys(BeninRuleSet::NOT_APPLIED), BeninRuleSet::NOT_APPLIED));
    }
}
