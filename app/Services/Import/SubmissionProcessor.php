<?php

namespace App\Services\Import;

use App\Models\Arrondissement;
use App\Models\Caregiver;
use App\Models\Child;
use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\DataCorrection;
use App\Models\FormVersion;
use App\Models\HealthZone;
use App\Models\Household;
use App\Models\Village;
use App\Services\Cleaning\CorrectionApplier;
use App\Services\Cleaning\SourceSnapshot;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap as Map;
use App\Support\HouseholdCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enregistre une soumission normalisée : ménage, caregivers, enfants, rattachement
 * au référentiel (zone > commune > arrondissement > village > grappe, binôme).
 * L'upsert est idempotent sur l'UUID de soumission ; une soumission inchangée
 * (même empreinte de contenu) n'est pas réécrite.
 */
class SubmissionProcessor
{
    public const NEW = 'new';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /** @var array<string, array<string, int>> */
    private array $ids = [];

    /** @var array<int, array{arrondissement_id: ?int, commune_id: ?int, health_zone_id: ?int, name: string, code: string}> */
    private array $villages = [];

    /** @var array<string, int> village par « code zone|code à 3 lettres » (ex. « 1|SEB ») */
    private array $villageShortCodes = [];

    /** @var array<string, int> */
    private array $formVersions = [];

    private Dictionary $dictionary;

    private CorrectionApplier $corrections;

    public function __construct(?Dictionary $dictionary = null)
    {
        $this->dictionary = $dictionary ?? Dictionary::current();
        $this->corrections = new CorrectionApplier;
        $this->ids = [
            'zone' => HealthZone::pluck('id', 'code')->all(),
            'commune' => Commune::pluck('id', 'code')->all(),
            'arrondissement' => Arrondissement::pluck('id', 'code')->all(),
            'village' => Village::pluck('id', 'code')->all(),
            'cluster' => Cluster::pluck('id', 'code')->all(),
            'collector' => Collector::pluck('id', 'code')->all(),
        ];
        $zoneCodes = array_flip($this->ids['zone']);
        foreach (Village::get(['id', 'code', 'short_code', 'name', 'arrondissement_id', 'commune_id', 'health_zone_id']) as $village) {
            $this->villages[$village->id] = $village->only(['code', 'name', 'arrondissement_id', 'commune_id', 'health_zone_id']);
            if ($village->short_code && isset($zoneCodes[$village->health_zone_id])) {
                $this->villageShortCodes[$zoneCodes[$village->health_zone_id].'|'.$village->short_code] = $village->id;
            }
        }
        $this->formVersions = FormVersion::pluck('id', 'version')->all();
    }

    /**
     * @param  array{data_source_id?: int|null, import_batch_id?: int|null, source_type?: string|null}  $origin
     */
    public function process(NormalizedSubmission $submission, array $origin = []): string
    {
        // Base nettoyée : la source est conservée telle quelle, les tables de travail reçoivent la version corrigée.
        $source = $submission;
        [$submission, $corrections] = $this->corrections->apply($source);
        $hash = $corrections === '' ? $source->hash() : hash('sha256', $source->hash().'|'.$corrections);
        $existing = Household::withoutGlobalScope('included')->where('submission_uuid', $submission->uuid)->first(['id', 'content_hash', 'excluded_at']);
        if ($existing && $existing->content_hash === $hash) {
            return self::UNCHANGED;
        }

        $attributes = $this->householdAttributes($submission) + [
            'source_data' => SourceSnapshot::serialize($source),
            // Exclusion de la base nettoyée (correction validée « exclure la soumission »).
            'excluded_at' => isset($submission->data[DataCorrection::EXCLUSION]) ? ($existing?->excluded_at ?? now()) : null,
            'content_hash' => $hash,
            'data_source_id' => $origin['data_source_id'] ?? null,
            'import_batch_id' => $origin['import_batch_id'] ?? null,
            'source_type' => $origin['source_type'] ?? null,
        ];

        DB::transaction(function () use ($submission, $attributes, $existing) {
            $household = $existing ?? new Household(['submission_uuid' => $submission->uuid]);
            $household->fill($attributes)->save();

            Child::where('household_id', $household->id)->delete();
            Caregiver::where('household_id', $household->id)->delete();
            $this->storeMembers($household, $submission);
        });

        return $existing ? self::UPDATED : self::NEW;
    }

    private function householdAttributes(NormalizedSubmission $s): array
    {
        $d = $s->data;
        $get = fn (string $key) => ($var = Map::var($key)) !== null ? ($d[$var] ?? null) : null;

        // Référentiel géographique.
        $villageId = $this->reference('village', $get('village'), fn ($code) => Village::create([
            'code' => $code,
            'name' => $this->label(Map::var('village'), $code),
            'arrondissement_id' => $this->ids['arrondissement'][(string) $get('arrondissement')] ?? null,
            'commune_id' => $this->ids['commune'][(string) $get('commune')] ?? null,
            'health_zone_id' => $this->ids['zone'][(string) $get('zone_sanitaire')] ?? null,
        ]));
        if ($villageId && ! isset($this->villages[$villageId])) {
            $this->villages[$villageId] = Village::find($villageId)->only(['code', 'name', 'arrondissement_id', 'commune_id', 'health_zone_id']);
        }
        $village = $villageId ? $this->villages[$villageId] : null;

        $zoneId = $this->reference('zone', $get('zone_sanitaire'), fn ($code) => HealthZone::create([
            'code' => $code, 'name' => $this->label(Map::var('zone_sanitaire'), $code),
        ])) ?? $village['health_zone_id'] ?? null;
        $communeId = $this->reference('commune', $get('commune'), fn ($code) => Commune::create([
            'code' => $code, 'name' => $this->label(Map::var('commune'), $code), 'health_zone_id' => $zoneId,
        ])) ?? $village['commune_id'] ?? null;
        $arrondissementId = $this->reference('arrondissement', $get('arrondissement'), fn ($code) => Arrondissement::create([
            'code' => $code, 'name' => $this->label(Map::var('arrondissement'), $code), 'commune_id' => $communeId,
        ])) ?? $village['arrondissement_id'] ?? null;

        $clusterId = $this->resolveCluster($get('id_menage'), $get('grappe'), $villageId, $communeId, $zoneId);
        $subCluster = self::subCluster($get('id_menage'), $get('sous_grappe'));

        $collectorId = $this->reference('collector', $get('binome'), fn ($code) => Collector::create([
            'code' => $code, 'name' => $this->label(Map::var('binome'), $code), 'health_zone_id' => $zoneId,
        ]));

        // Paradonnées.
        $start = self::dateTime($get('start'));
        $end = self::dateTime($get('end'));
        $today = self::date($get('today'));
        $submitted = self::dateTime($s->submittedAt);
        $duration = $start && $end && $end->gte($start) ? (int) round($start->diffInSeconds($end) / 60) : null;

        [$lat, $lon, $accuracy] = self::geopoint($get('gps'));
        $status = $get('statut_menage');

        return [
            'form_version_id' => $this->formVersions[(string) $s->formVersion] ?? $this->dictionary->formVersion?->id,
            'form_version' => $s->formVersion,
            'review_state' => $s->reviewState,
            'health_zone_id' => $zoneId,
            'commune_id' => $communeId,
            'arrondissement_id' => $arrondissementId,
            'village_id' => $villageId,
            'cluster_id' => $clusterId,
            'sub_cluster' => $subCluster,
            'collector_id' => $collectorId,
            'household_code' => $get('id_menage') !== null ? trim((string) $get('id_menage')) : null,
            'visit_number' => is_numeric($get('numero_visite')) ? (int) $get('numero_visite') : null,
            'survey_status' => $status !== null ? (string) $status : null,
            'consented' => $status !== null && (string) $status === Map::value('consentement', '0'),
            'interview_date' => $today ?? $start?->startOfDay() ?? $submitted?->startOfDay(),
            'started_at' => $start,
            'ended_at' => $end,
            'submitted_at' => $submitted,
            'duration_minutes' => $duration,
            'latitude' => $lat,
            'longitude' => $lon,
            'gps_accuracy' => $accuracy,
            // Les repeats caregivers/enfants vont dans leurs tables ; les autres (roster, moustiquaires…) restent avec le ménage.
            'data' => $s->data + ['__repeats' => array_diff_key($s->repeats, array_flip(array_filter([Map::var('repeat_caregivers'), Map::var('repeat_enfants')])))],
            'raw_data' => $s->raw,
        ];
    }

    /**
     * Grappe du ménage : d'après l'identifiant ZONE-VIL-NN-MMM (grappe ZONE-VIL-NN, rattachée au village
     * de ce code), sinon d'après la variable « grappe », sinon le village déclaré.
     */
    public function resolveCluster(mixed $householdCode, mixed $clusterValue, ?int $villageId, ?int $communeId, ?int $zoneId): ?int
    {
        $parsed = HouseholdCode::parse(is_scalar($householdCode) ? (string) $householdCode : null);
        if ($parsed) {
            $zoneCode = HouseholdCode::zoneCode($parsed['zone']);
            $codeVillageId = $this->villageShortCodes[$zoneCode.'|'.$parsed['village']] ?? null;
            $codeVillage = $codeVillageId ? $this->villages[$codeVillageId] : null;

            return $this->reference('cluster', $parsed['cluster_code'], fn ($code) => Cluster::create([
                'code' => $code,
                'name' => sprintf('%s – grappe %02d', $codeVillage['name'] ?? $parsed['village'], $parsed['cluster']),
                'number' => $parsed['cluster'],
                'village_id' => $codeVillageId ?? $villageId,
                'commune_id' => $codeVillage['commune_id'] ?? $communeId,
                'health_zone_id' => $codeVillage['health_zone_id'] ?? ($zoneCode !== null ? $this->ids['zone'][$zoneCode] ?? null : null) ?? $zoneId,
            ]));
        }

        $village = $villageId ? $this->villages[$villageId] ?? null : null;
        $clusterCode = ($clusterValue === null || $clusterValue === '') ? ($village['code'] ?? null) : (string) $clusterValue;

        return $this->reference('cluster', $clusterCode, fn ($code) => Cluster::create([
            'code' => $code,
            'name' => $village && $village['code'] === $code ? $village['name'] : 'Grappe '.$code,
            'village_id' => $villageId,
            'commune_id' => $communeId,
            'health_zone_id' => $zoneId,
        ]));
    }

    /** Sous-grappe : composante S de l'identifiant ménage (ex. TCH-SEB-07-S2-001), sinon variable « sous_grappe ». */
    public static function subCluster(mixed $householdCode, mixed $subClusterValue): ?int
    {
        $parsed = HouseholdCode::parse(is_scalar($householdCode) ? (string) $householdCode : null);

        return $parsed['sub_cluster'] ?? (is_numeric($subClusterValue) ? (int) $subClusterValue : null);
    }

    private function storeMembers(Household $household, NormalizedSubmission $s): void
    {
        $caregiverRepeat = Map::var('repeat_caregivers');
        $childRepeat = Map::var('repeat_enfants');
        $range = $this->ageRange($s->data);
        $interviewDate = $household->interview_date ? CarbonImmutable::parse($household->interview_date) : null;

        $caregivers = $caregiverRepeat ? NormalizedSubmission::findRepeat($s->repeats, $caregiverRepeat) : [];
        foreach ($caregivers as $i => $instance) {
            $cd = $instance['data'];
            $caregiver = Caregiver::create([
                'household_id' => $household->id,
                'position' => $i + 1,
                'repeat_key' => $instance['key'],
                'consented' => (string) ($cd[Map::var('consentement_caregiver')] ?? '') === Map::value('consentement_caregiver', '1'),
                'initials' => $cd[Map::var('caregiver_initiales')] ?? null,
                'sex' => $cd[Map::var('caregiver_sexe')] ?? null,
                'age_years' => is_numeric($cd[Map::var('caregiver_age')] ?? null) ? (int) $cd[Map::var('caregiver_age')] : null,
                'data' => $cd,
            ]);

            $children = $childRepeat ? NormalizedSubmission::findRepeat($instance['repeats'], $childRepeat) : [];
            foreach ($children as $j => $child) {
                $this->storeChild($household, $caregiver->id, $j + 1, $child, $range, $interviewDate);
            }
        }

        // Enfants hors repeat caregiver (structure de formulaire différente).
        if ($caregivers === [] && $childRepeat) {
            foreach (NormalizedSubmission::findRepeat($s->repeats, $childRepeat) as $j => $child) {
                $this->storeChild($household, null, $j + 1, $child, $range, $interviewDate);
            }
        }
    }

    private function storeChild(Household $household, ?int $caregiverId, int $position, array $instance, array $range, ?CarbonImmutable $interviewDate): void
    {
        $cd = $instance['data'];
        $dob = self::date($cd[Map::var('date_naissance_enfant')] ?? null) ?? self::date($cd[Map::var('date_naissance_declaree')] ?? null);
        $age = self::childAgeMonths($cd, $dob, $interviewDate);

        Child::create([
            'household_id' => $household->id,
            'caregiver_id' => $caregiverId,
            'position' => $position,
            'repeat_key' => $instance['key'],
            'sex' => $cd[Map::var('enfant_sexe')] ?? null,
            'date_of_birth' => $dob,
            'age_months' => $age,
            'eligible' => $age !== null && $age >= $range[0] && $age <= $range[1],
            'card_seen' => (string) ($cd[Map::var('carnet_vu')] ?? '') === Map::value('carnet_vu', '1'),
            'data' => $cd,
        ]);
    }

    /** Âge en mois : calcul du formulaire, sinon date de naissance, sinon âge déclaré. */
    public static function childAgeMonths(array $cd, ?CarbonImmutable $dob, ?CarbonImmutable $interviewDate): ?int
    {
        $estimated = $cd[Map::var('age_mois_estime')] ?? null;
        if (is_numeric($estimated)) {
            return max(0, (int) $estimated);
        }
        if ($dob && $interviewDate) {
            return max(0, (int) floor($dob->diffInMonths($interviewDate)));
        }
        $years = $cd[Map::var('age_annees')] ?? null;
        $months = $cd[Map::var('age_mois')] ?? null;
        if (is_numeric($years) || is_numeric($months)) {
            return (int) $years * 12 + (int) $months;
        }

        return null;
    }

    /** @return array{0: int, 1: int} tranche d'âge éligible en mois */
    public function ageRange(array $data): array
    {
        $low = $data[Map::var('age_min_mois')] ?? null;
        $high = $data[Map::var('age_max_mois')] ?? null;

        return [is_numeric($low) ? (int) $low : 12, is_numeric($high) ? (int) $high : 35];
    }

    private function reference(string $type, mixed $code, callable $create): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = (string) $code;

        return $this->ids[$type][$code] ??= $create($code)->id;
    }

    private function label(?string $variable, string $code): string
    {
        $listName = $variable ? ($this->dictionary->variable($variable)['list_name'] ?? null) : null;

        return ($listName ? $this->dictionary->choiceLabel($listName, $code) : null) ?? $code;
    }

    public static function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse(substr((string) $value, 0, 10), config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: ?float, 1: ?float, 2: ?float} latitude, longitude, précision */
    public static function geopoint(mixed $value): array
    {
        $parts = preg_split('/\s+/', trim((string) $value));
        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return [null, null, null];
        }

        return [(float) $parts[0], (float) $parts[1], isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : null];
    }
}
