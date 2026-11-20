<?php

namespace App\Support;

/**
 * Identifiant ménage ZONE-VIL-NN-MMM (ex. TCH-SEB-07-001) :
 * préfixe de zone, code village, numéro de grappe, numéro d'ordre du ménage.
 * Une composante « S » facultative donne la sous-grappe, après la grappe
 * (TCH-SEB-07-S1-001, TCH-SEB-07S1-001, DAG-HOI-01(S1)-004, DAG-HOI-01S(1)001)
 * ou en fin d'identifiant (TCH-SEB-07-001-S1).
 */
final class HouseholdCode
{
    private const HEAD = '/^([A-Z]{3})\s*[-_ ]\s*([A-Z]{3})\s*[-_ ]\s*(.+)$/';

    /** Composante S sous ses formes saisies : S1, S01, (S1), S(1). */
    private const SUB_CLUSTER = '/\(\s*S\s*(\d{1,2})\s*\)|S\s*\(\s*(\d{1,2})\s*\)|S\s*(\d{1,2})/';

    private const TAIL = '/^(\d{1,3})(?:-S(\d{1,2}))?-(\d{1,4})(?:-S(\d{1,2}))?$/';

    /**
     * @return array{zone: string, village: string, cluster: int, sub_cluster: int|null, order: int, cluster_code: string}|null
     */
    public static function parse(?string $code): ?array
    {
        if ($code === null || ! preg_match(self::HEAD, mb_strtoupper(trim($code)), $head)) {
            return null;
        }
        // Après zone et village : composante S ramenée à « -Sn- », séparateurs uniformisés.
        $tail = preg_replace_callback(self::SUB_CLUSTER, fn ($m) => '-S'.((int) ($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? ''))).'-', $head[3]);
        $tail = trim(preg_replace('/[\s\-_]+/', '-', $tail), '-');
        if (! preg_match(self::TAIL, $tail, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        // Une seule composante S, à l'une des deux positions.
        if ($m[2] !== null && ($m[4] ?? null) !== null) {
            return null;
        }
        $subCluster = $m[2] ?? $m[4] ?? null;

        return [
            'zone' => $head[1],
            'village' => $head[2],
            'cluster' => (int) $m[1],
            'sub_cluster' => $subCluster !== null ? (int) $subCluster : null,
            'order' => (int) $m[3],
            'cluster_code' => sprintf('%s-%s-%02d', $head[1], $head[2], (int) $m[1]),
        ];
    }

    /**
     * Forme correcte d'un identifiant mal écrit mais reconnaissable : zone, village, grappe, sous-grappe facultative et
     * numéro d'ordre, quels que soient les séparateurs (ex. « TCH-KAP-1-S-1-124 » → « TCH-KAP-01-S1-124 »).
     * La lettre O saisie à la place du chiffre 0 dans les nombres est corrigée (« O24 » → « 024 »).
     * Null si l'identifiant est déjà conforme ou n'est pas reconnaissable.
     */
    public static function normalize(?string $code): ?string
    {
        $value = mb_strtoupper(trim((string) $code));
        if ($value === '' || self::parse($value) !== null) {
            return null;
        }
        $digits = '[0-9O]';
        if (! preg_match("/^([A-Z]{3})\\W*([A-Z]{3})\\W*({$digits}{1,2})\\W*(?:S\\W*({$digits}{1,2})\\W*)?({$digits}{1,4})$/", $value, $m)) {
            return null;
        }
        $number = fn (string $part) => (int) str_replace('O', '0', $part);
        $normalized = sprintf('%s-%s-%02d%s-%03d', $m[1], $m[2], $number($m[3]), $m[4] !== '' ? '-S'.$number($m[4]) : '', $number($m[5]));

        return self::parse($normalized) !== null ? $normalized : null;
    }

    /** Code de zone sanitaire (l1) associé au préfixe, d'après config/gavi.php. */
    public static function zoneCode(string $prefix): ?string
    {
        $code = config('gavi.household_code.zone_prefixes')[mb_strtoupper($prefix)] ?? null;

        return $code === null ? null : (string) $code;
    }
}
