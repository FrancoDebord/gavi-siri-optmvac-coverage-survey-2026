<?php

namespace App\Services\Dashboard;

/**
 * Couverture de la chimioprévention du paludisme saisonnier (CPS) chez les enfants enrôlés :
 * - cycles prévus : smc_cycles du ménage (fichier l2 : 4 à Tchaourou, 5 à Dassa et Glazoué) ;
 * - cycle reçu : d'après la carte CPS présentée (treated_k), sinon d'après le rappel du caregiver (treated_k_recall) ;
 * - CPS déclarée (6 derniers mois), carte CPS reçue et présentée ; au moins un cycle, tous les cycles prévus,
 *   répartition du nombre de cycles reçus.
 */
class SmcCoverageStats extends ChildCoverageStats
{
    public const MAX_CYCLES = 5;

    /** Cycles prévus si le ménage n'a pas la valeur (ancienne version du formulaire). */
    private const DEFAULT_CYCLES = 4;

    protected function cacheKey(): string
    {
        return 'smc-coverage';
    }

    protected function children(): array
    {
        return array_map(function (array $child) {
            $data = $child['data'];
            $planned = is_numeric($child['household']['smc_cycles'] ?? null) ? (int) $child['household']['smc_cycles'] : self::DEFAULT_CYCLES;
            $planned = max(1, min(self::MAX_CYCLES, $planned));
            $card = self::yes($data['child_still_have_card'] ?? null);
            $cycles = [];
            for ($k = 1; $k <= self::MAX_CYCLES; $k++) {
                $onCard = $data["treated_{$k}"] ?? null;
                $cycles[$k] = match (true) {
                    $k > $planned => false, // cycle non prévu dans la commune
                    $card && $onCard !== null && $onCard !== '' => self::yes($onCard) ? 'carte' : null,
                    self::yes($data["treated_{$k}_recall"] ?? null) => 'rappel',
                    default => null,
                };
            }

            return [
                'zone' => $child['zone'], 'commune' => $child['commune'], 'village' => $child['village'],
                'age_group' => $child['age_group'], 'sex' => $child['sex'],
                'planned' => $planned,
                'cycles' => $cycles,
                'received' => count(array_filter($cycles, fn ($c) => is_string($c))),
                'declared' => self::yes($data['child_got_smc'] ?? null),
                'card_given' => self::yes($data['child_given_smc_card'] ?? null),
                'card_present' => $card,
            ];
        }, parent::children());
    }

    protected function metrics(array $children): array
    {
        $n = count($children);
        $cycles = [];
        for ($k = 1; $k <= self::MAX_CYCLES; $k++) {
            $planned = array_filter($children, fn ($c) => $c['cycles'][$k] !== false);
            $card = count(array_filter($planned, fn ($c) => $c['cycles'][$k] === 'carte'));
            $recall = count(array_filter($planned, fn ($c) => $c['cycles'][$k] === 'rappel'));
            $cycles[$k] = ['denominator' => count($planned), 'received' => $card + $recall, 'card' => $card, 'recall' => $recall,
                'pct' => self::rate($card + $recall, count($planned)), 'pct_card' => self::rate($card, count($planned))];
        }
        $distribution = array_fill(0, self::MAX_CYCLES + 1, 0);
        foreach ($children as $child) {
            $distribution[$child['received']]++;
        }

        return [
            'cycles' => $cycles,
            'declared' => self::rate(count(array_filter($children, fn ($c) => $c['declared'])), $n),
            'card_given' => self::rate(count(array_filter($children, fn ($c) => $c['card_given'])), $n),
            'card_present' => self::rate(count(array_filter($children, fn ($c) => $c['card_present'])), $n),
            'at_least_one' => self::rate(count(array_filter($children, fn ($c) => $c['received'] > 0)), $n),
            'all_cycles' => self::rate(count(array_filter($children, fn ($c) => $c['received'] >= $c['planned'])), $n),
            'distribution' => $distribution,
        ];
    }
}
