<?php

namespace App\Models;

use App\Services\Quality\RuleCatalog;

/**
 * Observation de supervision : fait relevé sur les paradonnées (heure, durée, volume journalier, délai d'envoi,
 * précision GPS…). À examiner par la supervision, sans valeur à corriger : hors du cycle de résolution des queries,
 * sauf si l'administrateur l'envoie dans les queries (voir ObservationEscalation) ; elle devient alors une query.
 */
class SupervisionObservation extends DataQuery
{
    public const KIND = 'observation';

    protected $table = 'data_queries';

    /** Réponse libre (« Autre explication ») : le texte de l'agent est alors obligatoire. */
    public const OTHER = 'autre';

    /**
     * Explications proposées aux agents pour une observation de paradonnées envoyée dans les queries, par règle.
     * Une explication commune (« autre ») complète chaque liste.
     */
    public const EXPLANATIONS = [
        'PARA_DURATION' => [
            'Ménage sans enfant éligible : questionnaire court',
            'Refus ou abandon en cours d\'entretien',
            'Entretien interrompu puis repris (formulaire resté ouvert)',
            'Formulaire complété après l\'entretien, à partir de notes',
            'Carnet bien tenu et caregiver disponible : entretien rapide',
        ],
        'PARA_OFF_HOURS' => [
            'Heure de la tablette mal réglée',
            'Ménage visité tôt ou tard à sa demande (absent dans la journée)',
            'Formulaire finalisé plus tard, après l\'entretien',
        ],
        'PARA_TODAY_START' => [
            'Date de la tablette mal réglée (corrigée depuis)',
            'Formulaire ouvert un jour et terminé le lendemain',
        ],
        'PARA_DAILY_VOLUME' => [
            'Grappe dense : ménages proches les uns des autres',
            'Beaucoup de ménages sans enfant éligible (entretiens courts)',
            'Formulaires de plusieurs jours finalisés ou envoyés le même jour',
            'Tablette ou compte utilisé par plusieurs agents',
        ],
        'PARA_LATE_SUBMISSION' => [
            'Pas de réseau internet dans la zone',
            'Tablette déchargée ou en panne',
            'Formulaire resté en brouillon, envoyé dès que constaté',
        ],
        'GPS_ACCURACY' => [
            'Point pris à l\'intérieur ou sous un abri',
            'Signal satellite faible (relief, végétation dense, temps couvert)',
            'Point enregistré trop vite, avant la stabilisation du GPS',
        ],
        'GPS_OUTSIDE_COMMUNE' => [
            'Ménage en limite de commune : localisation exacte',
            'Point GPS pris hors du ménage',
            'Commune mal sélectionnée dans le formulaire',
        ],
    ];

    /** Règle de paradonnées (aucune valeur du formulaire à corriger). */
    public static function isParadata(?string $ruleCode): bool
    {
        return $ruleCode !== null && in_array($ruleCode, RuleCatalog::OBSERVATIONS, true);
    }

    /**
     * Explications proposées pour une règle (clé = texte enregistré), avec « Autre explication » en dernier.
     *
     * @return array<string, string>
     */
    public static function explanationsFor(?string $ruleCode): array
    {
        $list = self::EXPLANATIONS[$ruleCode] ?? [];

        return array_combine($list, $list) + [self::OTHER => 'Autre explication (préciser ci-dessous)'];
    }
}
