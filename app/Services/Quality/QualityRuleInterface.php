<?php

namespace App\Services\Quality;

use App\Models\QualityRule;

/**
 * Une règle de contrôle qualité. Elle reçoit une soumission (ménage + caregivers + enfants)
 * et renvoie les anomalies détectées sous forme de QueryCandidate.
 */
interface QualityRuleInterface
{
    /**
     * @return iterable<QueryCandidate>
     */
    public function evaluate(SubmissionContext $context, QualityRule $rule): iterable;
}
