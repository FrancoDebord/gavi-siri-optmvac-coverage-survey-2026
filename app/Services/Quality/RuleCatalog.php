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
            'ROSTER_MEMBERS' => self::rule('Roster des membres non conforme', 'Le nombre de membres saisis dans le roster du ménage diffère du nombre de personnes déclarées (personnes ayant dormi dans le ménage).', 'metier', 'majeure', Rules\Business\RosterMembersRule::class),
            'ROSTER_CAREGIVERS' => self::rule('Roster des caregivers non conforme', 'Le nombre de caregivers saisis diffère du nombre de mères / gardien(ne)s déclaré(e)s dans le ménage.', 'metier', 'majeure', Rules\Business\RosterCaregiversRule::class),
            'ROSTER_CHILDREN' => self::rule('Roster des enfants non conforme', 'Pour un caregiver, le nombre d\'enfants saisis diffère du nombre d\'enfants de moins de 5 ans qu\'il a déclaré.', 'metier', 'majeure', Rules\Business\RosterChildrenRule::class, 'caregiver'),
            'TEAM_VILLAGE' => self::rule('Village hors de ceux du binôme', 'Le village coché ne fait pas partie des villages attribués au binôme de l\'agent (fichier binomes_villages.csv). Si le code village de l\'identifiant ménage désigne un village du binôme, ce village est proposé comme correction.', 'metier', 'majeure', Rules\Business\TeamVillageRule::class),

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
        'DICT_REQUIRED' => 'Obtenir la réponse à « {question} » (binôme ou ménage), puis la saisir en correction.',
        'DICT_CONSTRAINT' => 'Vérifier « {question} » ({valeur} ; attendu : {attendu}). Corriger, ou confirmer la valeur.',
        'DICT_CHOICE' => '« {valeur} » n\'est pas un choix de « {question} ». Corriger la réponse.',
        'DICT_TYPE' => 'Corriger le format de « {question} » (attendu : {attendu}).',
        'DICT_RELEVANT' => '« {question} » ne devait pas être posée. Vider la valeur, ou corriger la réponse qui la conditionne.',
        'OTHER_SPECIFY' => 'Compléter la précision « autre » de « {question} ».',
        'DUP_HOUSEHOLD' => 'Comparer les deux soumissions ({attendu}). Exclure le doublon, ou corriger l\'identifiant ménage.',
        'DUP_CHILD' => 'Vérifier si l\'enfant est saisi deux fois. Signaler le doublon en commentaire.',
        'HH_CODE' => 'Corriger l\'identifiant ménage ({valeur}). Format attendu : {attendu}.',
        'DUP_CARD' => 'Même enfant : signaler le doublon. Jumeaux : photographier le carnet de chacun.',
        'DUP_SUBMISSION' => 'Garder une soumission ({attendu}) et exclure l\'autre.',
        'ELIG_AGE' => 'Vérifier la date de naissance. Si elle est juste, l\'enfant n\'est pas éligible : confirmer.',
        'ELIG_AGE_DOB' => 'Âge ({valeur}) et date de naissance ({attendu}) incohérents. Corriger la valeur fausse.',
        'VAC_BEFORE_DOB' => 'Relire sur le carnet « {question} » ({valeur}) et la date de naissance. Corriger celle qui est fausse.',
        'VAC_AFTER_INTERVIEW' => '« {question} » ({valeur}) est après l\'interview. Relire le carnet et corriger (année ?).',
        'VAC_DOSE_ORDER' => 'Relire sur le carnet « {question} » et la dose précédente. Corriger celle qui est fausse.',
        'VAC_DOSE_INTERVAL' => '{valeur} entre les deux doses. Vérifier les dates sur le carnet ; si elles sont justes, confirmer (rattrapage).',
        'VAC_MIN_AGE' => 'Vérifier « {question} » et la date de naissance. Corriger, ou confirmer (dose précoce).',
        'VAC_STATUS_MISMATCH' => 'Statut « reçu » et date incohérents. Corriger selon le carnet.',
        'CARD_NO_DATES' => 'Carnet vu : saisir les dates, ou le motif de date manquante.',
        'PHOTO_CHECK' => 'Photo : {attendu} au lieu de {valeur}. Corriger « {question} ».',
        'CONSENT_REFUSED_DATA' => 'Données saisies sans consentement. Corriger le statut du ménage, ou vider les données.',
        'CONSENT_NO_CAREGIVER' => 'Aucun caregiver saisi. Compléter, ou corriger le statut du ménage.',
        'GPS_MISSING' => 'GPS manquant. Le reprendre à la revisite, ou justifier.',
        'GPS_ACCURACY' => 'GPS imprécis ({valeur}). Le reprendre dehors à la revisite, ou justifier.',
        'GPS_CLUSTER_DISTANCE' => 'Ménage à {valeur} du centre de la grappe. Corriger la grappe ou le village, ou justifier.',
        'GPS_OUTSIDE_COMMUNE' => 'GPS hors de la commune déclarée. Corriger la localisation.',
        'GEO_HIERARCHY' => 'Localisation incohérente ({attendu}). Corriger le village, l\'arrondissement ou la commune.',
        'ROSTER_MEMBERS' => '{valeur} personnes déclarées, {attendu}. Corriger le nombre déclaré.',
        'ROSTER_CAREGIVERS' => '{valeur} caregiver(s) déclaré(s), {attendu}. Corriger le nombre déclaré.',
        'ROSTER_CHILDREN' => '{valeur} enfant(s) déclaré(s), {attendu}. Corriger le nombre déclaré.',
        'TEAM_VILLAGE' => 'Vérifier le village et l\'agent avec le binôme. Corriger, ou justifier.',
        'PARA_DURATION' => 'Interview de {valeur} : vérifier avec le binôme, puis justifier ou contrôler le ménage.',
        'PARA_OFF_HOURS' => 'Interview à {valeur} : vérifier l\'heure de la tablette, puis justifier.',
        'PARA_TODAY_START' => 'Régler la date et l\'heure de la tablette, puis justifier.',
        'PARA_DAILY_VOLUME' => '{valeur} interviews ce jour : contrôler un échantillon avec le superviseur.',
        'PARA_LATE_SUBMISSION' => 'Envoyer les formulaires chaque jour ; vérifier la connexion de la tablette.',
        'OUTLIER' => '« {question} » = {valeur} : valeur inhabituelle. Corriger, ou confirmer.',
    ];

    private static function rule(string $name, string $description, string $category, string $severity, string $handler, string $scope = 'household', ?string $variables = null, array $parameters = []): array
    {
        return compact('name', 'description', 'category', 'severity', 'scope', 'variables', 'parameters', 'handler');
    }
}
