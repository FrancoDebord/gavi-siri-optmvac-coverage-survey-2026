<?php

namespace App\Services\Quality\Rules\Business;

use App\Models\HealthZone;
use App\Models\QualityRule;
use App\Models\Village;
use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\PreparesDataset;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;
use App\Support\HouseholdCode;

/**
 * Identifiant ménage ZONE-VIL-NN[-Sx]-MMM : format, préfixe de zone, code village,
 * cohérence avec la zone déclarée et avec la sous-grappe sélectionnée dans le formulaire.
 * Sans identifiant conforme, le ménage ne peut pas être rattaché à sa grappe.
 */
class HouseholdCodeRule extends BusinessRule implements PreparesDataset
{
    /** @var array<string, string> « code zone|code village » => nom du village */
    private array $villages = [];

    /** @var array<int, string> id zone => code zone */
    private array $zoneCodes = [];

    public function prepare(QualityRule $rule): void
    {
        $this->zoneCodes = HealthZone::pluck('code', 'id')->all();
        $this->villages = Village::whereNotNull('short_code')->get(['short_code', 'name', 'health_zone_id'])
            ->mapWithKeys(fn ($v) => [($this->zoneCodes[$v->health_zone_id] ?? '').'|'.$v->short_code => $v->name])->all();
    }

    protected function check(SubmissionContext $context): iterable
    {
        $variable = Map::var('id_menage');
        $code = $context->household->household_code;
        if (! $variable || $code === null || $code === '') {
            return;
        }
        $format = 'ZONE-VIL-NN-MMM, ex. TCH-SEB-07-001 ou TCH-SEB-07-S1-001';

        $parsed = HouseholdCode::parse($code);
        if (! $parsed) {
            yield QueryCandidate::for($context->root, "Identifiant ménage « {$code} » non conforme : le ménage ne peut pas être rattaché à sa grappe", $variable, $code, $format);

            return;
        }

        $zoneCode = HouseholdCode::zoneCode($parsed['zone']);
        if ($zoneCode === null) {
            yield QueryCandidate::for($context->root, "Identifiant ménage « {$code} » : préfixe de zone « {$parsed['zone']} » inconnu", $variable, $code,
                'Préfixe '.implode(' ou ', array_keys(config('gavi.household_code.zone_prefixes'))));

            return;
        }
        $declaredZone = $this->zoneCodes[$context->household->health_zone_id] ?? null;
        if ($declaredZone !== null && $declaredZone !== $zoneCode) {
            yield QueryCandidate::for($context->root, "Identifiant ménage « {$code} » : le préfixe {$parsed['zone']} ne correspond pas à la zone sanitaire déclarée", $variable, $code,
                'Préfixe de la zone déclarée');
        }
        if ($this->villages && ! isset($this->villages[$zoneCode.'|'.$parsed['village']])) {
            yield QueryCandidate::for($context->root, "Identifiant ménage « {$code} » : code village « {$parsed['village']} » inconnu dans la zone", $variable, $code,
                'Code village de la liste des identifiants');
        }

        $selected = Map::var('sous_grappe') ? $context->root->get(Map::var('sous_grappe')) : null;
        if ($parsed['sub_cluster'] !== null && is_numeric($selected) && (int) $selected !== $parsed['sub_cluster']) {
            yield new QueryCandidate("Identifiant ménage « {$code} » : sous-grappe S{$parsed['sub_cluster']} alors que la sous-grappe sélectionnée dans le formulaire est {$selected}",
                Map::var('sous_grappe'), $selected, 'S'.$parsed['sub_cluster'], $context->root, 'sub_cluster');
        }
    }
}
