<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\VariableMap as Map;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

/**
 * Deux enfants du même ménage avec la même date de naissance et des dates de vaccination
 * presque toutes identiques : le même carnet a probablement servi aux deux (enfant saisi
 * deux fois, ou jumeaux dont un seul carnet a été consulté). Sexe et caregiver ne sont pas
 * comparés, contrairement à DUP_CHILD.
 */
class SameCardRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $children = array_values(array_filter(
            array_map(fn ($scope) => ['scope' => $scope, 'dob' => $context->childDob($scope), 'dates' => $context->vaccineDates($scope)], $context->scopes('child')),
            fn ($c) => $c['dob'] !== null && $c['dates'] !== [],
        ));
        $minCommon = (int) $this->params->get('min_common_doses', 5);
        $minShare = (float) $this->params->get('min_identical_share', 0.8);
        $sexVar = Map::var('enfant_sexe');

        foreach ($children as $i => $a) {
            foreach ($children as $j => $b) {
                if ($i === $j || ! $a['dob']->equalTo($b['dob'])) {
                    continue;
                }
                $common = array_intersect_key($a['dates'], $b['dates']);
                $identical = array_filter($common, fn ($date, $code) => $date->equalTo($b['dates'][$code]), ARRAY_FILTER_USE_BOTH);
                if (count($common) < $minCommon || count($identical) / count($common) < $minShare) {
                    continue;
                }

                $differentSex = $sexVar && (string) $a['scope']->own($sexVar) !== (string) $b['scope']->own($sexVar);
                yield new QueryCandidate(
                    sprintf('%s : même date de naissance (%s) et %d dates de vaccination sur %d identiques à %s%s',
                        $a['scope']->label(), self::fmt($a['dob']), count($identical), count($common), mb_lcfirst($b['scope']->label()),
                        $differentSex ? ' (sexe différent)' : ''),
                    null,
                    count($identical).'/'.count($common).' dates identiques',
                    'Un carnet par enfant',
                    $a['scope'],
                    $b['scope']->path,
                );
            }
        }
    }
}
