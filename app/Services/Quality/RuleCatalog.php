<?php

namespace App\Services\Quality;

/**
 * Catalogue des règles livrées : sert au seeder, aux valeurs par défaut des
 * paramètres et à la documentation (docs/regles-qualite.md).
 */
class RuleCatalog
{
    /**
     * Règles de paradonnées : observations de supervision (faits à examiner), pas des queries de données.
     * Elles ne portent pas sur une valeur à corriger ou confirmer et n'entrent pas dans le cycle de résolution.
     */
    public const OBSERVATIONS = [
        'PARA_DURATION', 'PARA_OFF_HOURS', 'PARA_TODAY_START', 'PARA_DAILY_VOLUME', 'PARA_LATE_SUBMISSION',
        'GPS_ACCURACY', 'GPS_OUTSIDE_COMMUNE',
    ];

    /**
     * @return array<string, array{name: string, description: string, category: string, severity: string, scope: string, variables: string|null, parameters: array, handler: class-string, suggestion: string|null}>
     */
    public static function all(): array
    {
        $rules = self::definitions();
        foreach ($rules as $code => &$rule) {
            $rule['suggestion'] = self::SUGGESTIONS[$code] ?? null;
        }

        return $rules;
    }

    private static function definitions(): array
    {
        return [
            // ── 5.1 Règles générées depuis le data dictionary ───────────────────────
            'DICT_REQUIRED' => self::rule('Champ obligatoire manquant', 'Variable « required » vide alors que ses conditions d\'affichage (relevant de la variable et de ses groupes) sont vraies.', 'auto', 'majeure', Rules\Dictionary\RequiredRule::class),
            'DICT_CONSTRAINT' => self::rule('Contrainte non respectée', 'La valeur saisie viole la « constraint » du XLSForm.', 'auto', 'majeure', Rules\Dictionary\ConstraintRule::class),
            'DICT_CHOICE' => self::rule('Valeur hors liste de choix', 'Valeur d\'un select_one / select_multiple absente de la liste de choix du dictionnaire.', 'auto', 'critique', Rules\Dictionary\ChoiceListRule::class),
            'DICT_TYPE' => self::rule('Type de donnée invalide', 'Valeur incompatible avec le type déclaré (integer, decimal, date, geopoint).', 'auto', 'critique', Rules\Dictionary\TypeRule::class),
            'DICT_RELEVANT' => self::rule('Saut non respecté', 'Variable renseignée alors que sa condition « relevant » est fausse.', 'auto', 'mineure', Rules\Dictionary\IrrelevantFilledRule::class),
            'OTHER_SPECIFY' => self::rule('« Autre, préciser » vide', 'Option « autre » choisie mais le champ de précision (non obligatoire dans le formulaire) est vide.', 'auto', 'mineure', Rules\Dictionary\OtherSpecifyRule::class),

            // ── Doublons ─────────────────────────────────────────────────────────────
            'DUP_HOUSEHOLD' => self::rule('Ménage en double', 'Même identifiant de ménage dans la même grappe, pour un même numéro de visite ou avec plusieurs interviews acceptées.', 'metier', 'critique', Rules\Business\DuplicateHouseholdRule::class),
            'DUP_CHILD' => self::rule('Enfant en double', 'Même enfant (ménage, initiales du caregiver, sexe, date de naissance) enregistré plusieurs fois.', 'metier', 'majeure', Rules\Business\DuplicateChildRule::class, 'child'),
            'DUP_CARD' => self::rule('Même carnet pour deux enfants', 'Deux enfants du même ménage avec la même date de naissance et au moins une part donnée de dates de vaccination identiques (sur un nombre minimal de doses communes). Sexe et caregiver ne sont pas comparés.', 'metier', 'majeure', Rules\Business\SameCardRule::class, 'child', null, ['min_common_doses' => 5, 'min_identical_share' => 0.8]),
            'DUP_SUBMISSION' => self::rule('Soumission en double', 'Plusieurs soumissions du même agent avec la même heure de début, ou au contenu identique.', 'metier', 'critique', Rules\Business\DuplicateSubmissionRule::class),

            // ── Éligibilité ─────────────────────────────────────────────────────────
            'ELIG_AGE' => self::rule('Âge hors tranche ciblée', 'Enfant enrôlé (section vaccination remplie) dont l\'âge à la date d\'interview, calculé depuis la date de naissance, est hors de la tranche ciblée.', 'metier', 'critique', Rules\Business\ChildAgeEligibilityRule::class, 'child', null, ['age_min_months' => 12, 'age_max_months' => 35]),
            'ELIG_AGE_DOB' => self::rule('Âge déclaré incohérent avec la date de naissance', 'Écart entre l\'âge déclaré (années/mois) et l\'âge calculé depuis la date de naissance supérieur à la tolérance.', 'metier', 'majeure', Rules\Business\AgeDobConsistencyRule::class, 'child', null, ['tolerance_months' => 2]),

            // ── Vaccination ─────────────────────────────────────────────────────────
            'VAC_BEFORE_DOB' => self::rule('Vaccin avant la naissance', 'Date de vaccination antérieure à la date de naissance.', 'metier', 'critique', Rules\Business\VaccineBeforeBirthRule::class, 'child'),
            'VAC_AFTER_INTERVIEW' => self::rule('Vaccin après l\'interview', 'Date de vaccination postérieure à la date d\'interview.', 'metier', 'critique', Rules\Business\VaccineAfterInterviewRule::class, 'child'),
            'VAC_DOSE_ORDER' => self::rule('Ordre des doses non respecté', 'Dose n+1 datée avant la dose n (Penta, VPO, VPI, PCV, Rota, VAP, RR).', 'metier', 'majeure', Rules\Business\VaccineDoseOrderRule::class, 'child'),
            'VAC_DOSE_INTERVAL' => self::rule('Intervalle entre doses trop court', 'Moins de N jours entre deux doses successives d\'une même série.', 'metier', 'majeure', Rules\Business\VaccineDoseIntervalRule::class, 'child', null, ['min_interval_days' => 28]),
            'VAC_MIN_AGE' => self::rule('Âge minimal de vaccination non respecté', 'Dose administrée avant l\'âge minimal du calendrier (ex. RR1 avant 9 mois). Âges minimaux dans config/gavi.php, avec calendrier propre à une zone sanitaire possible (ex. VAP 4 dès 15 mois à Dassa-Glazoué) ; tolérance en jours.', 'metier', 'majeure', Rules\Business\VaccineMinAgeRule::class, 'child', null, ['tolerance_days' => 0]),
            'VAC_STATUS_MISMATCH' => self::rule('Statut vaccinal incohérent avec la date', 'Date saisie alors que le vaccin est déclaré « non reçu », ou dates saisies alors que l\'enfant n\'a « jamais reçu de vaccin ».', 'metier', 'majeure', Rules\Business\VaccineStatusMismatchRule::class, 'child'),
            'CARD_NO_DATES' => self::rule('Carnet vu sans aucune date', 'Le carnet a été consulté mais aucune date de vaccination n\'a été saisie (ni motif de date manquante).', 'metier', 'majeure', Rules\Business\CardSeenNoDatesRule::class, 'child'),
            'PHOTO_CHECK' => self::rule('Saisie différente de la photo', 'Valeur saisie différente de celle lue sur la photo du document (carnet, acte de naissance), d\'après les vérifications enregistrées dans photo_checks. Fermée automatiquement quand la saisie corrigée correspond à la photo.', 'metier', 'majeure', Rules\Business\PhotoCheckRule::class, 'child'),

            // ── Consentement ────────────────────────────────────────────────────────
            'CONSENT_REFUSED_DATA' => self::rule('Données saisies malgré un refus', 'Ménage non enquêté (refus, absent…) avec des caregivers saisis, ou caregiver ayant refusé avec des enfants saisis.', 'metier', 'critique', Rules\Business\RefusedWithDataRule::class),
            'CONSENT_NO_CAREGIVER' => self::rule('Module caregiver vide', 'Ménage ayant accepté l\'enquête sans aucun caregiver interviewé.', 'metier', 'majeure', Rules\Business\ConsentNoCaregiverRule::class),

            // ── Géographie ──────────────────────────────────────────────────────────
            'GPS_MISSING' => self::rule('GPS manquant', 'Coordonnées GPS absentes.', 'metier', 'majeure', Rules\Business\GpsMissingRule::class),
            'GPS_ACCURACY' => self::rule('Précision GPS insuffisante', 'Précision du point GPS supérieure au seuil (mètres).', 'metier', 'mineure', Rules\Business\GpsAccuracyRule::class, 'household', null, ['max_accuracy_m' => 50]),
            'GPS_CLUSTER_DISTANCE' => self::rule('Point trop loin de la grappe', 'Distance au centroïde de la grappe (coordonnées importées, sinon médiane des points de la grappe) supérieure au seuil.', 'metier', 'majeure', Rules\Business\GpsClusterDistanceRule::class, 'household', null, ['max_distance_km' => 5, 'min_points' => 3]),
            'GPS_OUTSIDE_COMMUNE' => self::rule('Point hors de la commune', 'Approximation sans polygones : distance au centroïde (médiane) des points de la commune supérieure au rayon.', 'metier', 'mineure', Rules\Business\GpsOutsideCommuneRule::class, 'household', null, ['radius_km' => 50, 'min_points' => 5]),
            'HH_CODE' => self::rule('Identifiant ménage non conforme', 'Identifiant hors format ZONE-VIL-NN[-Sx]-MMM, préfixe de zone inconnu ou différent de la zone déclarée, code village inconnu, ou composante S différente de la sous-grappe sélectionnée dans le formulaire.', 'metier', 'majeure', Rules\Business\HouseholdCodeRule::class),
            'GEO_HIERARCHY' => self::rule('Grappe / village incohérents', 'Le village déclaré n\'appartient pas à l\'arrondissement ou à la commune déclarés, ou la grappe n\'est pas rattachée au village.', 'metier', 'critique', Rules\Business\GeoHierarchyRule::class),

            // ── Paradonnées ─────────────────────────────────────────────────────────
            'PARA_DURATION' => self::rule('Durée d\'interview anormale', 'Durée (end − start) inférieure au minimum ou supérieure au maximum, pour les ménages ayant accepté.', 'metier', 'mineure', Rules\Business\InterviewDurationRule::class, 'household', null, ['min_minutes' => 10, 'max_minutes' => 120]),
            'PARA_OFF_HOURS' => self::rule('Interview hors heures de travail', 'Début avant l\'heure d\'ouverture ou fin après l\'heure de fermeture.', 'metier', 'mineure', Rules\Business\OffHoursRule::class, 'household', null, ['start_hour' => 7, 'end_hour' => 19]),
            'PARA_TODAY_START' => self::rule('Date « today » différente de « start »', 'La date du jour enregistrée ne correspond pas à la date de début d\'interview.', 'metier', 'mineure', Rules\Business\TodayStartMismatchRule::class),
            'PARA_DAILY_VOLUME' => self::rule('Volume journalier anormal', 'Nombre d\'interviews d\'un agent sur une journée supérieur au maximum.', 'metier', 'mineure', Rules\Business\CollectorDailyVolumeRule::class, 'household', null, ['max_per_day' => 20]),
            'PARA_LATE_SUBMISSION' => self::rule('Soumission tardive', 'Délai entre la fin de l\'interview et l\'envoi au serveur supérieur au seuil (jours).', 'metier', 'mineure', Rules\Business\LateSubmissionRule::class, 'household', null, ['max_days' => 2]),

            // ── Valeurs aberrantes ──────────────────────────────────────────────────
            'OUTLIER' => self::rule('Valeur numérique aberrante', 'Valeur hors de [Q1 − k·IQR ; Q3 + k·IQR] (ou |z| > seuil) au sein de la grappe ou de l\'agent.', 'metier', 'mineure', Rules\Business\OutlierRule::class, 'household', 'num_slept_last_night,num_bednets,hh_caregivers_num,age_years,caregiver_num_children', [
                'method' => 'iqr', 'iqr_k' => 3, 'z_threshold' => 3, 'group_by' => 'collector', 'min_group_size' => 8,
            ]),
        ];
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /**
     * Suggestions de résolution par défaut (modifiables par règle dans l'interface).
     * Gabarits : {question} libellé de la question, {variable} nom XLSForm, {valeur} valeur observée, {attendu} valeur attendue.
     */
    public const SUGGESTIONS = [
        'DICT_REQUIRED' => 'Contacter le binôme (ou revisiter le ménage) pour obtenir la réponse à « {question} », puis compléter la soumission par une édition dans ODK/Kobo.',
        'DICT_CONSTRAINT' => 'Vérifier la valeur saisie pour « {question} » ({valeur}) : elle doit respecter « {attendu} ». Corriger s\'il s\'agit d\'une erreur de saisie, sinon justifier.',
        'DICT_CHOICE' => 'La valeur {valeur} n\'existe pas dans la liste de choix de « {question} ». Vérifier la version du formulaire utilisée sur la tablette et corriger la réponse.',
        'DICT_TYPE' => 'Corriger le format de « {question} » (attendu : {attendu}).',
        'DICT_RELEVANT' => 'Vérifier les réponses qui conditionnent « {question} » (saut) : supprimer la valeur si la question ne devait pas être posée, ou corriger la réponse filtre.',
        'OTHER_SPECIFY' => 'Compléter la précision « autre » de « {question} » auprès du binôme ou du répondant.',
        'DUP_HOUSEHOLD' => 'Comparer les deux soumissions ({attendu}) avec le binôme : rejeter le doublon sur le serveur ou corriger l\'identifiant du ménage.',
        'DUP_CHILD' => 'Vérifier avec le binôme si l\'enfant a été saisi deux fois et supprimer l\'enregistrement en double.',
        'HH_CODE' => 'Corriger l\'identifiant du ménage dans ODK Central ({valeur}) au format attendu : {attendu}. Vérifier avec le binôme la grappe, la sous-grappe et le numéro d\'ordre.',
        'DUP_CARD' => 'Retourner au ménage : s\'il s\'agit du même enfant, supprimer l\'enregistrement en double ; s\'il s\'agit de jumeaux, photographier et saisir le carnet de chaque enfant.',
        'DUP_SUBMISSION' => 'Identifier la soumission à conserver ({attendu}) et rejeter l\'autre sur le serveur (statut « rejeté » ODK, validation Kobo).',
        'ELIG_AGE' => 'Vérifier la date de naissance sur le document. Si elle est exacte, l\'enfant n\'était pas éligible : retirer ses données de vaccination ou justifier.',
        'ELIG_AGE_DOB' => 'Recouper l\'âge déclaré ({valeur}) et la date de naissance ({attendu}) avec le caregiver ou le document, puis corriger la valeur erronée.',
        'VAC_BEFORE_DOB' => 'Relire sur le carnet la date « {question} » ({valeur}) et la date de naissance : l\'erreur peut porter sur l\'une ou l\'autre (jour/mois inversés, année). Corriger celle qui est fausse.',
        'VAC_AFTER_INTERVIEW' => 'La date « {question} » ({valeur}) est postérieure à l\'interview : relire le carnet et corriger (erreur d\'année probable).',
        'VAC_DOSE_ORDER' => 'Relire sur le carnet les deux dates en cause : l\'erreur peut porter sur « {question} » ou sur la dose précédente (doses inversées, jour et mois inversés, année). Corriger celle qui est fausse.',
        'VAC_DOSE_INTERVAL' => 'Vérifier sur le carnet les dates des deux doses ({valeur} d\'écart). Si elles sont exactes, justifier (rattrapage).',
        'VAC_MIN_AGE' => 'Vérifier la date « {question} » et la date de naissance : corriger celle qui est fausse. Si elles sont exactes, justifier (dose administrée précocement).',
        'VAC_STATUS_MISMATCH' => 'Harmoniser le statut « reçu » et la date selon le carnet : corriger « {question} » ou supprimer la date.',
        'CARD_NO_DATES' => 'Le carnet a été vu : saisir les dates de vaccination, ou le motif de date manquante pour chaque vaccin.',
        'PHOTO_CHECK' => 'Corriger « {question} » dans ODK Central d\'après la photo : {attendu} au lieu de {valeur}.',
        'CONSENT_REFUSED_DATA' => 'Vérifier le statut de l\'enquête et le consentement avec le binôme : corriger le statut ou supprimer les données saisies sans consentement.',
        'CONSENT_NO_CAREGIVER' => 'Vérifier pourquoi aucun caregiver n\'a été saisi : compléter le module ou corriger le statut du ménage.',
        'GPS_MISSING' => 'Reprendre le point GPS lors d\'une revisite, ou justifier (panne GPS, refus).',
        'GPS_ACCURACY' => 'Reprendre le point GPS à l\'extérieur avec une meilleure précision (valeur actuelle : {valeur}) en cas de revisite, sinon justifier.',
        'GPS_CLUSTER_DISTANCE' => 'Vérifier que le ménage appartient bien à la grappe déclarée ({valeur} du centre) : corriger la grappe ou le village, ou justifier.',
        'GPS_OUTSIDE_COMMUNE' => 'Vérifier la commune et le village déclarés ainsi que la position GPS, puis corriger la localisation.',
        'GEO_HIERARCHY' => 'Corriger le village, l\'arrondissement ou la commune sélectionnés pour qu\'ils soient cohérents ({attendu}).',
        'PARA_DURATION' => 'Échanger avec le binôme sur le déroulement de l\'interview ({valeur}) ; justifier, ou contrôler le ménage.',
        'PARA_OFF_HOURS' => 'Vérifier l\'heure de la tablette et les conditions de l\'interview ({valeur}) avec le binôme, puis justifier.',
        'PARA_TODAY_START' => 'Vérifier et régler la date et l\'heure de la tablette du binôme, puis justifier la soumission.',
        'PARA_DAILY_VOLUME' => 'Revoir avec le superviseur la charge du binôme ce jour-là ({valeur} interviews) et contrôler un échantillon de ses interviews.',
        'PARA_LATE_SUBMISSION' => 'Rappeler au binôme d\'envoyer les formulaires chaque jour et vérifier la connectivité de la tablette.',
        'OUTLIER' => 'Vérifier la valeur {valeur} de « {question} » avec le binôme ; corriger s\'il s\'agit d\'une erreur de saisie, sinon justifier.',
    ];

    private static function rule(string $name, string $description, string $category, string $severity, string $handler, string $scope = 'household', ?string $variables = null, array $parameters = []): array
    {
        return compact('name', 'description', 'category', 'severity', 'scope', 'variables', 'parameters', 'handler');
    }
}
