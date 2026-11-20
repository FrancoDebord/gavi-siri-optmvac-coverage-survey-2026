<?php

namespace App\Services\Quality;

use App\Services\Quality\Rules\Business;

/**
 * Règles de qualité du Bénin (document « regles_qualite_benin_cov26c4v2.md », formulaire cov26c4v2) qui ne
 * doublonnent pas les règles déjà en place. Codes du document (CRI-10 → CRI_10). Créées une seule fois (migration) :
 * les réglages faits ensuite dans l'interface sont conservés.
 *
 * - Règles par expression XLSForm (condition d'erreur) sur une portée : ménage, caregiver, enfant, ou « repeat »
 *   (roster des membres, SES). Une valeur vide rend la comparaison fausse : les sauts sont respectés.
 * - Règles qui comparent plusieurs instances ou plusieurs ménages : classe dédiée (handler).
 * OBS = observation de supervision (hors cycle de correction) ; CRI = critique ; MAJ = majeure.
 */
final class BeninRuleSet
{
    /**
     * Créées désactivées : trop de signalements au test sur les données (11 291 chevauchements, 3 045 points GPS
     * identiques, 1 011 sols « autres », 468 traitements CPS > cycles) ou volume à valider (567, 442). À activer
     * depuis la page des règles après examen.
     */
    public const INACTIVE = ['MAJ_47', 'CRI_20', 'OBS_29', 'MAJ_42', 'MAJ_08', 'MAJ_35'];

    /** Contrôles du document non repris, avec la raison (section « Règles non appliquées » de la restitution). */
    public const NOT_APPLIED = [
        'CRI-09' => 'Découpage des sous-grappes : écarts visibles dans « Grappes par village » (plusieurs grappes par village).',
        'MAJ-05' => 'Déjà couverte par la règle « Module caregiver vide » (CONSENT_NO_CAREGIVER).',
        'MAJ-06' => 'Revisites : l\'historique des visites d\'un même ménage n\'est pas relié entre soumissions.',
        'MAJ-20' => 'Déjà couverte par « Champ obligatoire manquant » (DICT_REQUIRED).',
        'MAJ-22' => 'Naissances rapprochées : à préciser (jumeaux, enfants confiés).',
        'MAJ-30' => 'Vaccins attendus au Bénin (ok_*, vaccines.csv) à fournir.',
        'MAJ-33' => 'Doses de 1re année attendues (ok_*, vaccines.csv) à fournir.',
        'MAJ-39' => 'Mois des cycles CPS par commune (c1_mth…c5_mth, l2.csv) à fournir.',
        'MAJ-44' => 'Identifiant de carte CPS (child_id) : format à préciser.',
        'OBS-12' => 'Mois de déménagement : codage de date_moved à préciser.',
        'OBS-14' => 'Statistique par enquêteur : à suivre dans l\'espace superviseur.',
        'OBS-15' => 'Statistique par enquêteur : à suivre dans l\'espace superviseur.',
        'OBS-26' => 'Déjà couverte par « Champ obligatoire manquant » quand la question est obligatoire.',
        'OBS-27' => 'Seuil statistique par zone : à préciser.',
        'AVT-01' => 'Avertissement sans correction : liste des vaccins d\'un même contact à préciser.',
    ];

    /** Tranche d'âge cible de l'enfant (âge en mois entre c_low et c_high du ménage). */
    private const ELIGIBLE = '${age_months_est} >= ${c_low} and ${age_months_est} <= ${c_high}';

    /**
     * @return array<string, array{name: string, severity: string, kind: string, scope: string, variables: string, expression?: string, handler?: class-string, message: string, suggestion: string}>
     */
    public static function rules(): array
    {
        $doses = fn (array $series, string $suffix = '') => implode(' or ', array_merge(...array_map(
            fn ($s) => array_map(fn ($i) => "(\${{$s[$i]}{$suffix}} = 1 and \${{$s[$i - 1]}{$suffix}} = 0)", range(1, count($s) - 1)), $series)));
        $cycles = fn (callable $one) => implode(' or ', array_map($one, range(1, 5)));

        return [
            // ---------------- Critiques ----------------
            'CRI_05' => self::expr('GPS hors du Bénin', 'critique', 'household', 'current_location',
                'string-length(${current_location}) > 0 and (number(selected-at(${current_location}, 0)) < 6 or number(selected-at(${current_location}, 0)) > 12.5 or number(selected-at(${current_location}, 1)) < 0.7 or number(selected-at(${current_location}, 1)) > 3.9)',
                'Position GPS hors du Bénin.', 'Reprendre le point GPS à la revisite.'),
            'CRI_07' => self::expr('Ménage accepté sans membres', 'critique', 'household', 'household_survey_status,num_slept_last_night',
                '${household_survey_status} = 0 and (string-length(${num_slept_last_night}) = 0 or ${num_slept_last_night} = 0)',
                'Ménage accepté sans liste des membres.', 'Compléter le nombre de personnes et la liste des membres.'),
            'CRI_08' => self::expr('Données saisies malgré le refus du caregiver', 'critique', 'caregiver', 'participation_agreed,firstname,age_years',
                '${participation_agreed} = 0 and (string-length(${firstname}) > 0 or string-length(${age_years}) > 0)',
                'Caregiver ayant refusé, mais données saisies.', 'Vider ses données, ou corriger le consentement.'),
            'CRI_10' => self::expr('Date de naissance dans le futur', 'critique', 'child', 'child_dob,child_dob_reported',
                '${child_dob} > ${today} or ${child_dob_reported} > ${today}',
                'Date de naissance dans le futur.', 'Vérifier le document et corriger la date.'),
            'CRI_11' => self::expr('Âge de l\'enfant impossible à calculer', 'critique', 'child', 'age_months_est,child_dob,child_dob_reported,child_years',
                '${participation_agreed} = 1 and string-length(${age_months_est}) = 0',
                'Âge de l\'enfant manquant : éligibilité impossible à établir.', 'Saisir la date de naissance ou l\'âge.'),
            'CRI_12' => self::expr('Éligibilité contradictoire', 'critique', 'child', 'calc_dob_months,child_age_reported,c_low,c_high',
                'string-length(coalesce(${calc_dob_months}, ${calc_reported_months})) > 0 and string-length(${child_age_reported}) > 0 and ((coalesce(${calc_dob_months}, ${calc_reported_months}) < ${c_low}) != (${child_age_reported} < ${c_low}) or (coalesce(${calc_dob_months}, ${calc_reported_months}) > ${c_high}) != (${child_age_reported} > ${c_high}))',
                'Date de naissance et âge déclaré ne donnent pas la même éligibilité.', 'Vérifier la date de naissance et l\'âge, puis corriger.'),
            'CRI_13' => self::expr('Enfant éligible sans section vaccination', 'critique', 'child', 'age_months_est,child_sleep_last_night,receive_vaccine',
                self::ELIGIBLE.' and ${child_sleep_last_night} = 1 and string-length(${receive_vaccine}) = 0',
                'Enfant éligible non interrogé sur la vaccination.', 'Compléter la section vaccination.'),
            'CRI_20' => self::code('Même point GPS pour deux ménages', 'critique', 'household', 'current_location', Business\DuplicateGpsRule::class,
                'Même point GPS pour deux ménages.', 'Vérifier la visite ; reprendre le point GPS si besoin.'),

            // ---------------- Majeures ----------------
            'MAJ_07' => self::expr('Sous-circuit invalide', 'majeure', 'household', 'sub_circuits,selected_sub_circuit',
                '${sub_circuits} < 1 or ${selected_sub_circuit} < 1 or ${selected_sub_circuit} > ${sub_circuits}',
                'Sous-grappe tirée hors du nombre de sous-grappes.', 'Corriger le nombre de sous-grappes ou la sous-grappe tirée.'),
            'MAJ_08' => self::code('Nombre de caregivers incohérent avec la liste des membres', 'majeure', 'household', 'hh_caregivers_num,person_caregiver',
                Business\RosterCaregiverFlagRule::class, 'Le nombre de caregivers ne correspond pas à la liste des membres.', 'Corriger le nombre de caregivers ou la liste des membres.'),
            'MAJ_09' => self::code('Plus d\'enfants déclarés que d\'enfants listés', 'majeure', 'household', 'caregiver_num_children,age_group',
                Business\RosterUnderFiveRule::class, 'Plus d\'enfants déclarés par les caregivers que d\'enfants de moins de 5 ans listés.', 'Vérifier les âges des membres et le nombre d\'enfants déclaré.'),
            'MAJ_10' => self::code('Ménage sans adulte', 'majeure', 'household', 'age_group', Business\RosterNoAdultRule::class,
                'Aucun adulte (15 ans ou plus) dans la liste des membres.', 'Vérifier les âges des membres.'),
            'MAJ_11' => self::expr('Caregiver de moins de 15 ans', 'majeure', 'repeat', 'person_caregiver,age_group',
                '${person_caregiver} = 1 and ${age_group} <= 6',
                'Un membre de moins de 15 ans est noté caregiver.', 'Vérifier l\'âge du membre ou corriger « caregiver ».'),
            'MAJ_12' => self::expr('Âge du caregiver impossible', 'majeure', 'caregiver', 'age_years',
                '${age_years} < 12 or ${age_years} > 90', 'Âge du caregiver à vérifier.', 'Vérifier l\'âge et corriger.'),
            'MAJ_13' => self::expr('Parent trop jeune pour l\'enfant', 'majeure', 'child', 'age_years,age_months_est,caregiver_relationship_to_child',
                '${caregiver_relationship_to_child} = 0 and (${age_years} - (${age_months_est} div 12)) < 12',
                'Parent trop jeune pour cet enfant.', 'Vérifier l\'âge du caregiver, l\'âge de l\'enfant ou le lien.'),
            'MAJ_14' => self::expr('Sexe incohérent avec le lien de parenté', 'majeure', 'caregiver', 'cg_rel_hh,caregiver_sex',
                '((${cg_rel_hh} = 2 or ${cg_rel_hh} = 4 or ${cg_rel_hh} = 5 or ${cg_rel_hh} = 9 or ${cg_rel_hh} = 15) and ${caregiver_sex} = 0) or ((${cg_rel_hh} = 3 or ${cg_rel_hh} = 6 or ${cg_rel_hh} = 7 or ${cg_rel_hh} = 10 or ${cg_rel_hh} = 14) and ${caregiver_sex} = 1)',
                'Le sexe du caregiver ne correspond pas au lien déclaré.', 'Corriger le sexe ou le lien de parenté.'),
            'MAJ_15' => self::expr('Caregiver sans enfant', 'majeure', 'caregiver', 'participation_agreed,caregiver_num_children',
                '${participation_agreed} = 1 and ${caregiver_num_children} = 0',
                'Caregiver interrogé sans enfant de moins de 5 ans.', 'Vérifier le nombre d\'enfants déclaré.'),
            'MAJ_16' => self::expr('Années d\'études coraniques impossibles', 'majeure', 'caregiver', 'years_of_koranic,age_years',
                '${years_of_koranic} > ${age_years} - 3', 'Années d\'études supérieures à l\'âge possible.', 'Corriger les années d\'études ou l\'âge.'),
            'MAJ_18' => self::expr('Enfant de 5 ans ou plus', 'majeure', 'child', 'age_months_est',
                '${age_months_est} >= 60', 'Enfant de 5 ans ou plus : ne devait pas être listé.', 'Vérifier l\'âge ; signaler l\'enfant en trop.'),
            'MAJ_19' => self::expr('Date de naissance documentée sans photo', 'majeure', 'child', 'child_dob_documented,dob_doc_image',
                '${child_dob_documented} = 1 and string-length(${dob_doc_image}) = 0',
                'Photo du document de naissance manquante.', 'Photographier le document à la revisite.'),
            'MAJ_26' => self::expr('Dose reçue sans la précédente (carte)', 'majeure', 'child', 'Penta1,Penta2,Penta3',
                $doses([['opv1', 'opv2', 'opv3'], ['Penta1', 'Penta2', 'Penta3'], ['pcv1', 'pcv2', 'pcv3'], ['rota1', 'rota2', 'rota3'], ['mal1', 'mal2', 'mal3', 'mal4'], ['ipv1', 'ipv2'], ['meas1', 'meas2']]),
                'Dose reçue sans la dose précédente sur la carte.', 'Relire la carte et corriger.'),
            'MAJ_27' => self::expr('Carte vue sans photo', 'majeure', 'child', 'see_card,chb_image1',
                '${see_card} = 1 and string-length(${chb_image1}) = 0', 'Photo de la carte manquante.', 'Photographier la carte à la revisite.'),
            'MAJ_28' => self::expr('Photos de la carte manquantes', 'majeure', 'child', 'num_images,chb_image2,chb_image3,chb_image4',
                '(${num_images} >= 2 and string-length(${chb_image2}) = 0) or (${num_images} >= 3 and string-length(${chb_image3}) = 0) or (${num_images} >= 4 and string-length(${chb_image4}) = 0)',
                'Moins de photos que le nombre déclaré.', 'Photographier les pages manquantes.'),
            'MAJ_29' => self::expr('VAP 4 trop proche de VAP 3', 'majeure', 'child', 'mal3date,mal4date',
                '${mal4date} - ${mal3date} < 150', 'VAP 4 moins de 150 jours après VAP 3.', 'Relire les deux dates sur la carte.'),
            'MAJ_31' => self::expr('Rappel : dose reçue sans la précédente', 'majeure', 'child', 'penta1_rec,penta2_rec,penta3_rec',
                $doses([['opv1', 'opv2', 'opv3'], ['penta1', 'penta2', 'penta3'], ['pcv1', 'pcv2', 'pcv3'], ['rota1', 'rota2', 'rota3'], ['rtss1', 'rtss2', 'rtss3', 'rtss4']], '_rec'),
                'Rappel incohérent entre les doses.', 'Revoir le rappel avec le caregiver.'),
            'MAJ_32' => self::expr('Rappel : dose impossible pour l\'âge', 'majeure', 'child', 'age_months_est,measmth9,measmth15,rtss4_rec',
                '(${measmth9} = 1 and ${age_months_est} < 9) or (${measmth15} = 1 and ${age_months_est} < 15) or ((${penta3_rec} = 1 or ${opv3_rec} = 1 or ${pcv3_rec} = 1) and ${age_months_est} < 3) or (${rtss1_rec} = 1 and ${age_months_est} < 5) or (${rtss4_rec} = 1 and ((${l1} = 2 and ${age_months_est} < 15) or (${l1} = 1 and ${age_months_est} < 18)))',
                'Enfant trop jeune pour cette dose.', 'Vérifier l\'âge de l\'enfant ou la réponse.'),
            'MAJ_34' => self::expr('Revue « 3 doses de VAP » incohérente', 'majeure', 'child', 'three_MV_doses,mal3,rtss3_rec,age_months_est',
                '${three_MV_doses} = 1 and (${mal3} = 0 or ${rtss3_rec} = 0 or ${age_months_est} < 7)',
                '« 3 doses de VAP » contredit la carte, le rappel ou l\'âge.', 'Corriger la réponse de revue.'),
            'MAJ_35' => self::expr('Revue de 2e année incohérente', 'majeure', 'child', 'all_y2_doses,fourth_MV_dose,meas2,mal4,age_months_est',
                '(${all_y2_doses} = 1 and (${meas2} = 0 or ${age_months_est} < 15)) or (${fourth_MV_dose} = 1 and (${mal4} = 0 or ${rtss4_rec} = 0 or (${l1} = 2 and ${age_months_est} < 15) or (${l1} = 1 and ${age_months_est} < 18)))',
                'Revue de 2e année contredite par la carte ou l\'âge.', 'Corriger la réponse de revue.'),
            'MAJ_36' => self::expr('Question de 2e année posée trop tôt', 'majeure', 'child', 'age_months_est,all_y2_doses,fourth_MV_dose',
                '${age_months_est} < 12 and ((${all_y2_doses} = 0 or ${all_y2_doses} = 1) or (${fourth_MV_dose} = 0 or ${fourth_MV_dose} = 1))',
                'Enfant de moins de 12 mois : répondre « N/A ».', 'Corriger en « N/A ».'),
            'MAJ_37' => self::expr('Cycles CPS dans le désordre', 'majeure', 'child', 'date_1,date_2,date_3,date_4,date_5',
                '${date_2} <= ${date_1} or ${date_3} <= ${date_2} or ${date_4} <= ${date_3} or ${date_5} <= ${date_4}',
                'Dates des cycles CPS dans le désordre.', 'Relire la carte CPS et corriger.'),
            'MAJ_38' => self::expr('Cycles CPS trop rapprochés', 'majeure', 'child', 'date_1,date_2,date_3,date_4,date_5',
                implode(' or ', array_map(fn ($k) => "(\${date_{$k}} - \${date_".($k - 1)."} > 0 and \${date_{$k}} - \${date_".($k - 1).'} < 21)', range(2, 5))),
                'Deux cycles CPS à moins de 3 semaines.', 'Relire la carte CPS et corriger.'),
            'MAJ_40' => self::expr('Cycle CPS avant la naissance', 'majeure', 'child', 'date_1,child_dob',
                $cycles(fn ($k) => "\${date_{$k}} < \${child_dob}"), 'Date de cycle CPS avant la naissance.', 'Relire la carte CPS et la date de naissance.'),
            'MAJ_41' => self::expr('Carte CPS contredit la déclaration', 'majeure', 'child', 'child_got_smc,treated_1',
                '${child_got_smc} = 0 and ('.$cycles(fn ($k) => "\${treated_{$k}} = 1").')',
                'La carte montre une CPS que le caregiver n\'a pas déclarée.', 'Revoir avec le caregiver et corriger.'),
            'MAJ_42' => self::expr('Nombre de traitements CPS incohérent', 'majeure', 'child', 'smc_treatment_number,smc_cycles',
                '${smc_treatment_number} > ${smc_cycles}', 'Plus de traitements CPS que de cycles prévus.', 'Corriger le nombre de traitements.'),
            'MAJ_43' => self::expr('CPS avant 3 mois', 'majeure', 'child', 'date_1,child_dob',
                $cycles(fn ($k) => "(\${treated_{$k}} = 1 and \${date_{$k}} - \${child_dob} < 90)"),
                'Enfant de moins de 3 mois au cycle CPS.', 'Relire la carte CPS et la date de naissance.'),
            'MAJ_47' => self::code('Entretiens qui se chevauchent', 'majeure', 'household', 'start,end', Business\OverlappingInterviewsRule::class,
                'Deux entretiens du même agent en même temps.', 'Vérifier les heures de la tablette avec le binôme.'),

            // ---------------- Observations de supervision ----------------
            'OBS_05' => self::expr('Initiales du caregiver mal formatées', 'mineure', 'caregiver', 'firstname',
                'string-length(${firstname}) > 0 and not(regex(${firstname}, \'^[A-Za-z]{1,5}$\'))',
                'Initiales du caregiver mal formatées.', 'Saisir des initiales (1 à 5 lettres).', true),
            'OBS_11' => self::expr('Taille du ménage atypique', 'mineure', 'household', 'num_slept_last_night',
                '${num_slept_last_night} > 25', 'Ménage de plus de 25 personnes.', 'Vérifier le nombre de personnes.', true),
            'OBS_13' => self::expr('Études coraniques très longues', 'mineure', 'caregiver', 'years_of_koranic',
                '${years_of_koranic} > 20', 'Plus de 20 ans d\'études coraniques.', 'Vérifier la durée.', true),
            'OBS_16' => self::expr('Naissance le jour de l\'entretien', 'mineure', 'child', 'child_dob,today',
                'string-length(${child_dob}) > 0 and ${child_dob} = ${today}', 'Enfant né le jour de l\'entretien.', 'Vérifier la date de naissance.', true),
            'OBS_17' => self::expr('Vaccins d\'un même contact à des dates éloignées', 'mineure', 'child', 'Penta1date,opv1date,pcv1date,rota1date',
                implode(' or ', array_merge(
                    array_merge(...array_map(fn ($n) => ["abs(\${Penta{$n}date} - \${opv{$n}date}) > 7", "abs(\${Penta{$n}date} - \${pcv{$n}date}) > 7", "abs(\${Penta{$n}date} - \${rota{$n}date}) > 7"], [1, 2, 3])),
                    ['abs(${meas1date} - ${YFdate}) > 7'],
                )),
                'Vaccins habituellement donnés le même jour, à plus de 7 jours d\'écart.', 'Relire la carte.', true),
            'OBS_18' => self::expr('HepB de naissance tardive', 'mineure', 'child', 'hepbdate,child_dob',
                '${hepbdate} - ${child_dob} > 1', 'HepB de naissance après 24 h.', 'Relire la date sur la carte.', true),
            'OBS_19' => self::expr('Rota 1 tardif', 'mineure', 'child', 'rota1date,child_dob',
                '${rota1date} - ${child_dob} > 105', 'Rota 1 après 15 semaines.', 'Relire la date sur la carte.', true),
            'OBS_20' => self::expr('Cicatrice BCG incohérente', 'mineure', 'child', 'bcg_rec,bcg_scar',
                '(${bcg_rec} = 0 and ${bcg_scar} = 1) or (${bcg_rec} = 1 and ${bcg_scar} = 2)', 'Cicatrice BCG contredit la déclaration.', 'Revoir avec le caregiver.', true),
            'OBS_21' => self::expr('Rappel contredit la carte', 'mineure', 'child', 'Penta3,penta3_rec',
                implode(' or ', array_map(fn ($p) => "(\${{$p[0]}} = 1 and \${{$p[1]}} = 0) or (\${{$p[0]}} = 0 and \${{$p[1]}} = 1)", [['Penta3', 'penta3_rec'], ['opv3', 'opv3_rec'], ['pcv3', 'pcv3_rec']])),
                'Rappel et carte ne concordent pas.', 'Revoir avec le caregiver.', true),
            'OBS_22' => self::expr('Photos insuffisantes pour le carnet', 'mineure', 'child', 'card_version,num_images',
                '(${card_version} = 1 and ${num_images} = 1) or (${card_version} = 3 and ${num_images} < 2)', 'Toutes les pages du carnet ne sont pas photographiées.', 'Photographier toutes les pages.', true),
            'OBS_23' => self::expr('Carte CPS sans CPS déclarée', 'mineure', 'child', 'child_given_smc_card,child_got_smc',
                '${child_given_smc_card} = 1 and ${child_got_smc} = 0', 'Carte CPS reçue, mais CPS déclarée non reçue.', 'Revoir avec le caregiver.', true),
            'OBS_24' => self::expr('Carte CPS sans cycle traité', 'mineure', 'child', 'child_still_have_card,treated_1',
                '${child_still_have_card} = 1 and string-length(${treated_1}) > 0 and not('.$cycles(fn ($k) => "\${treated_{$k}} = 1").')',
                'Carte CPS sans aucun cycle coché.', 'Relire la carte CPS.', true),
            'OBS_25' => self::expr('Rappel CPS contredit la carte', 'mineure', 'child', 'treated_1,treated_1_recall',
                $cycles(fn ($k) => "(\${treated_{$k}} = 1 and \${treated_{$k}_recall} = 0) or (\${treated_{$k}} = 0 and \${treated_{$k}_recall} = 1)"),
                'Rappel du caregiver et carte CPS ne concordent pas.', 'Revoir avec le caregiver.', true),
            'OBS_28' => self::expr('Type de murs incohérent', 'mineure', 'repeat', 'mud_walls,cement_walls,mud_and_cement_walls',
                '(${mud_walls} = 0 and ${cement_walls} = 0 and ${mud_and_cement_walls} = 0) or (${mud_walls} = 1 and ${cement_walls} = 1 and ${mud_and_cement_walls} = 0)',
                'Type de murs incohérent.', 'Vérifier les murs.', true),
            'OBS_29' => self::expr('Toit ou sol non renseigné', 'mineure', 'repeat', 'tin_tile_roof,thatched_roof,cement_floor,mud_floor',
                '(${tin_tile_roof} = 0 and ${thatched_roof} = 0) or (${cement_floor} = 0 and ${mud_floor} = 0)', 'Type de toit ou de sol non renseigné.', 'Vérifier le toit et le sol.', true),
            'OBS_30' => self::expr('Électricité incohérente', 'mineure', 'repeat', 'electricity,electricity_fuel,tv,solar_panel',
                '(${electricity_fuel} = 1 and ${electricity} = 0) or (${tv} = 1 and ${electricity} = 0 and ${solar_panel} = 0)', 'Appareil électrique sans source d\'électricité.', 'Vérifier l\'électricité.', true),
            'OBS_31' => self::expr('Animaux sans espèce', 'mineure', 'repeat', 'own_livestock',
                '${own_livestock} = 1 and '.implode(' and ', array_map(fn ($a) => "\${{$a}} = 0", ['poultry', 'cattle', 'sheep', 'goats', 'horses', 'donkeys', 'pigs', 'camels'])),
                'Animaux déclarés sans espèce.', 'Préciser les animaux.', true),
            'OBS_32' => self::expr('Aucun combustible de cuisson', 'mineure', 'repeat', 'collect_firewood,gas,charcoal',
                implode(' and ', array_map(fn ($f) => "\${{$f}} = 0", ['collect_firewood', 'buy_firewood', 'gas', 'electricity_fuel', 'crop_residues', 'charcoal', 'kerosene'])),
                'Aucun combustible de cuisson déclaré.', 'Vérifier le combustible.', true),
        ];
    }

    private static function expr(string $name, string $severity, string $scope, string $variables, string $expression, string $message, string $suggestion, bool $observation = false): array
    {
        return ['name' => $name, 'severity' => $severity, 'kind' => $observation ? 'observation' : 'query', 'scope' => $scope, 'variables' => $variables,
            'expression' => $expression, 'message' => $message, 'suggestion' => $suggestion];
    }

    private static function code(string $name, string $severity, string $scope, string $variables, string $handler, string $message, string $suggestion): array
    {
        return ['name' => $name, 'severity' => $severity, 'kind' => 'query', 'scope' => $scope, 'variables' => $variables,
            'handler' => $handler, 'message' => $message, 'suggestion' => $suggestion];
    }
}
