<?php

/*
|--------------------------------------------------------------------------
| Configuration métier de l'enquête de couverture GAVI SIRI, round 2
|--------------------------------------------------------------------------
|
| Les noms de variables ci-dessous sont lus dans le XLSForm cov26c4v3.xls
| (form_id cov26c4v2, version 3). Ce sont les valeurs par défaut du mapping :
| elles restent modifiables dans Paramètres > Mapping des variables.
|
*/

return [

    // Dossier des fichiers d'export générés (ZIP) ; par défaut storage/app/private/exports. Les tests en utilisent un autre.
    'exports_path' => env('GAVI_EXPORTS_PATH'),

    // Dossier contenant le XLSForm et les fichiers CSV de pulldata (l1.csv, village.csv…).
    'settings_path' => (function () {
        $path = env('GAVI_SETTINGS_PATH') ?: storage_path('app/public/settings');

        return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) ? $path : base_path($path);
    })(),
    'default_dictionary' => env('GAVI_DICTIONARY_FILE', 'cov26c4v3.xls'),
    'country' => env('GAVI_COUNTRY', 'bj'),

    /*
    | Identifiant ménage ZONE-VIL-NN-MMM (ex. TCH-SEB-07-001) : préfixe de zone,
    | code village à 3 lettres, numéro de grappe, numéro d'ordre du ménage.
    | Les codes villages sont lus dans le classeur ci-dessous (un onglet par zone,
    | colonnes « Village » et « Identifiant ») ; l'onglet est rattaché à la zone
    | par les 3 premières lettres de son nom.
    */
    'household_code' => [
        'zone_prefixes' => ['TCH' => '1', 'DAG' => '2'],
        'village_codes_file' => env('GAVI_VILLAGE_CODES_FILE', 'identifiants_villages_dagla_tchaourou.xlsx'),
    ],

    'dashboard' => [
        'refresh_seconds' => (int) env('GAVI_DASHBOARD_REFRESH', 60),
        'cache_seconds' => (int) env('GAVI_DASHBOARD_CACHE', 30),
    ],

    'http' => [
        'timeout' => 60,
        'page_size' => 500,
        'rate_limit_per_minute' => 60,
    ],

    /*
    | Mapping par défaut : clé métier => [libellé, catégorie, variable XLSForm, valeur attendue]
    */
    'mapping' => [
        'zone_sanitaire' => ['Zone sanitaire', 'geo', 'l1', null],
        'commune' => ['Commune', 'geo', 'l2', null],
        'arrondissement' => ['Arrondissement', 'geo', 'l3', null],
        'village' => ['Village', 'geo', 'village', null],
        'grappe' => ['Grappe (si l\'identifiant ménage ne la donne pas ; vide = village)', 'geo', 'cluster_number', null],
        'sous_grappe' => ['Sous-grappe sélectionnée', 'geo', 'selected_sub_circuit', null],
        'nb_sous_grappes' => ['Nombre de sous-grappes créées', 'geo', 'sub_circuits', null],
        'binome' => ['Agent de terrain (fieldworker)', 'geo', 'fieldworker', null],
        'pays' => ['Pays', 'geo', 'country', null],

        'id_menage' => ['Identifiant du ménage', 'menage', 'household', null],
        'numero_visite' => ['Numéro de visite', 'menage', 'visit_num', null],
        'statut_menage' => ['Statut d\'enquête du ménage', 'menage', 'household_survey_status', null],
        'consentement' => ['Consentement ménage (valeur « accepté »)', 'menage', 'household_survey_status', '0'],
        'statut_refus' => ['Valeur « refus »', 'menage', 'household_survey_status', '1'],
        'statut_absent' => ['Valeurs « absent » (séparées par des virgules)', 'menage', 'household_survey_status', '2,4'],
        'statut_sans_enfant' => ['Valeur « pas d\'enfant éligible »', 'menage', 'household_survey_status', '3'],
        'gps' => ['Coordonnées GPS', 'menage', 'current_location', null],

        'repeat_caregivers' => ['Repeat caregivers', 'caregiver', 'cg', null],
        'consentement_caregiver' => ['Participation du caregiver (valeur « oui »)', 'caregiver', 'participation_agreed', '1'],
        'caregiver_initiales' => ['Initiales du caregiver', 'caregiver', 'firstname', null],
        'caregiver_sexe' => ['Sexe du caregiver', 'caregiver', 'caregiver_sex', null],
        'caregiver_age' => ['Âge du caregiver', 'caregiver', 'age_years', null],

        'repeat_enfants' => ['Repeat enfants', 'enfant', 'smc_cov_hh_cg_child_2023', null],
        'enfant_sexe' => ['Sexe de l\'enfant', 'enfant', 'child_sex', null],
        'date_naissance_enfant' => ['Date de naissance (documentée)', 'enfant', 'child_dob', null],
        'date_naissance_declaree' => ['Date de naissance (déclarée)', 'enfant', 'child_dob_reported', null],
        'age_annees' => ['Âge déclaré (années)', 'enfant', 'child_years', null],
        'age_mois' => ['Âge déclaré (mois)', 'enfant', 'child_months', null],
        'age_mois_estime' => ['Âge estimé en mois (calculé)', 'enfant', 'age_months_est', null],
        'age_min_mois' => ['Âge minimal éligible (calculé)', 'enfant', 'c_low', null],
        'age_max_mois' => ['Âge maximal éligible (calculé)', 'enfant', 'c_high', null],
        'recu_vaccin' => ['A déjà reçu des vaccins', 'enfant', 'receive_vaccine', null],
        'carnet_vu' => ['Carnet vu (valeur « oui »)', 'enfant', 'see_card', '1'],

        'start' => ['Début d\'interview (start)', 'para', 'start', null],
        'end' => ['Fin d\'interview (end)', 'para', 'end', null],
        'today' => ['Date du jour (today)', 'para', 'today', null],
    ],

    /*
    | Antigènes : code => libellé, préfixe de variables (X, Xdat_known, Xdate, XInvalid),
    | âge minimal en jours. Le mapping crée une clé <code>_date par antigène.
    */
    'vaccines' => [
        'bcg' => ['label' => 'BCG', 'var' => 'bcg', 'min_age_days' => 0],
        'hepb' => ['label' => 'HepB', 'var' => 'hepb', 'min_age_days' => 0],
        'opv0' => ['label' => 'VPO 0', 'var' => 'opv0', 'min_age_days' => 0],
        'opv1' => ['label' => 'VPO 1', 'var' => 'opv1', 'min_age_days' => 42],
        'opv2' => ['label' => 'VPO 2', 'var' => 'opv2', 'min_age_days' => 70],
        'opv3' => ['label' => 'VPO 3', 'var' => 'opv3', 'min_age_days' => 98],
        'ipv1' => ['label' => 'VPI 1', 'var' => 'ipv1', 'min_age_days' => 98],
        'ipv2' => ['label' => 'VPI 2', 'var' => 'ipv2', 'min_age_days' => 270],
        'penta1' => ['label' => 'Penta 1', 'var' => 'Penta1', 'min_age_days' => 42],
        'penta2' => ['label' => 'Penta 2', 'var' => 'Penta2', 'min_age_days' => 70],
        'penta3' => ['label' => 'Penta 3', 'var' => 'Penta3', 'min_age_days' => 98],
        'pcv1' => ['label' => 'PCV 1', 'var' => 'pcv1', 'min_age_days' => 42],
        'pcv2' => ['label' => 'PCV 2', 'var' => 'pcv2', 'min_age_days' => 70],
        'pcv3' => ['label' => 'PCV 3', 'var' => 'pcv3', 'min_age_days' => 98],
        'rota1' => ['label' => 'Rota 1', 'var' => 'rota1', 'min_age_days' => 42],
        'rota2' => ['label' => 'Rota 2', 'var' => 'rota2', 'min_age_days' => 70],
        'rota3' => ['label' => 'Rota 3', 'var' => 'rota3', 'min_age_days' => 98],
        // VAP (vaccin antipaludique), calendrier standard appliqué à Tchaourou : 6, 7, 9 et 18 mois (mois de 30 jours).
        'mal1' => ['label' => 'VAP 1', 'var' => 'mal1', 'min_age_days' => 180],
        'mal2' => ['label' => 'VAP 2', 'var' => 'mal2', 'min_age_days' => 210],
        'mal3' => ['label' => 'VAP 3', 'var' => 'mal3', 'min_age_days' => 270],
        'mal4' => ['label' => 'VAP 4', 'var' => 'mal4', 'min_age_days' => 540],
        'meas1' => ['label' => 'RR 1', 'var' => 'meas1', 'min_age_days' => 270],
        'meas2' => ['label' => 'RR 2', 'var' => 'meas2', 'min_age_days' => 450],
        'mena' => ['label' => 'MenA', 'var' => 'MenA', 'min_age_days' => 270],
        'yf' => ['label' => 'VAA', 'var' => 'YF', 'min_age_days' => 270],
    ],

    /*
    | Calendrier propre à une zone sanitaire : code de zone (l1) => [antigène => âge minimal en jours].
    | Remplace min_age_days ci-dessus pour les ménages de cette zone.
    */
    'vaccine_min_age_by_zone' => [
        // Dassa-Glazoué (Dagla) : VAP 1–3 à 6, 7 et 9 mois comme le standard ; la 4e dose peut être
        // rapprochée (au plus tôt 15 mois au lieu de 18) ou reportée (aucun âge maximal contrôlé).
        '2' => ['mal4' => 450],
    ],

    // Séries de doses dont l'ordre et l'intervalle minimal sont contrôlés.
    'dose_series' => [
        'VPO' => ['opv1', 'opv2', 'opv3'],
        'VPI' => ['ipv1', 'ipv2'],
        'Penta' => ['penta1', 'penta2', 'penta3'],
        'PCV' => ['pcv1', 'pcv2', 'pcv3'],
        'Rota' => ['rota1', 'rota2', 'rota3'],
        'VAP' => ['mal1', 'mal2', 'mal3', 'mal4'],
        'RR' => ['meas1', 'meas2'],
    ],

    'yes_value' => '1',
    'no_value' => '0',
];
