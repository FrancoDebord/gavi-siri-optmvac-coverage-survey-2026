# Règles de contrôle qualité

Ce document liste les règles livrées avec la plateforme. Il est généré à partir de
`app/Services/Quality/RuleCatalog.php`, qui fait foi. Les sévérités et les seuils se modifient dans
**Paramètres › Règles qualité** sans toucher au code. Une règle désactivée ne crée plus de query et
ne ferme pas automatiquement celles qui existent.

## Fonctionnement du moteur

- Le moteur (`App\Services\Quality\QualityEngine`) contrôle **toutes** les soumissions après chaque
  synchronisation ou import. Il s'exécute aussi chaque nuit à 02:00 et peut être relancé à la main
  (`php artisan gavi:run-quality-checks` ou bouton « Relancer le moteur »).
- Chaque anomalie a une **empreinte** : règle + soumission + instance (`cg[1]/smc_cov_hh_cg_child_2023[2]`)
  + variable. Une anomalie retrouvée garde donc la même query, sans doublon.
- Une query dont l'anomalie n'est plus détectée (donnée corrigée dans ODK ou Kobo) est **fermée
  automatiquement** : elle passe en statut « Validée », avec un événement système dans l'historique.
  Si l'anomalie réapparaît, une query fermée automatiquement est rouverte.
- Les règles du dictionnaire évaluent les expressions XLSForm (`relevant`, `constraint`) avec un
  évaluateur XPath simplifié. Une expression qui utilise une fonction non prise en charge hors du
  terminal (`pulldata()`, `position()`…) est ignorée au lieu de produire une fausse alerte. Les
  variables calculées (`c_low`, `ok_bcg`, `age_months_est`…) sont lues dans la soumission.
- Une condition `relevant` de groupe fausse alors que le groupe contient des données produit **une
  seule** query `DICT_RELEVANT` pour ce groupe, et non une query par variable.

## Règles livrées

| Code | Libellé | Type | Portée | Sévérité | Description | Seuils par défaut |
|---|---|---|---|---|---|---|
| `DICT_REQUIRED` | Champ obligatoire manquant | Dictionnaire (auto) | Ménage | Majeure | Variable « required » vide alors que ses conditions d'affichage (relevant de la variable et de ses groupes) sont vraies. | — |
| `DICT_CONSTRAINT` | Contrainte non respectée | Dictionnaire (auto) | Ménage | Majeure | La valeur saisie viole la « constraint » du XLSForm. | — |
| `DICT_CHOICE` | Valeur hors liste de choix | Dictionnaire (auto) | Ménage | Critique | Valeur d'un select_one / select_multiple absente de la liste de choix du dictionnaire. | — |
| `DICT_TYPE` | Type de donnée invalide | Dictionnaire (auto) | Ménage | Critique | Valeur incompatible avec le type déclaré (integer, decimal, date, geopoint). | — |
| `DICT_RELEVANT` | Saut non respecté | Dictionnaire (auto) | Ménage | Mineure | Variable renseignée alors que sa condition « relevant » est fausse. | — |
| `OTHER_SPECIFY` | « Autre, préciser » vide | Dictionnaire (auto) | Ménage | Mineure | Option « autre » choisie mais le champ de précision (non obligatoire dans le formulaire) est vide. | — |
| `DUP_HOUSEHOLD` | Ménage en double | Métier | Ménage | Critique | Même identifiant de ménage dans la même grappe, pour un même numéro de visite ou avec plusieurs interviews acceptées. | — |
| `DUP_CHILD` | Enfant en double | Métier | Enfant | Majeure | Même enfant (ménage, initiales du caregiver, sexe, date de naissance) enregistré plusieurs fois. | — |
| `DUP_CARD` | Même carnet pour deux enfants | Métier | Enfant | Majeure | Deux enfants du même ménage avec la même date de naissance et au moins une part donnée de dates de vaccination identiques (sur un nombre minimal de doses communes). Sexe et caregiver ne sont pas comparés. | `min_common_doses = 5`<br>`min_identical_share = 0.8` |
| `DUP_SUBMISSION` | Soumission en double | Métier | Ménage | Critique | Plusieurs soumissions du même agent avec la même heure de début, ou au contenu identique. | — |
| `ELIG_AGE` | Âge hors tranche ciblée | Métier | Enfant | Critique | Enfant enrôlé (section vaccination remplie) dont l'âge à la date d'interview, calculé depuis la date de naissance, est hors de la tranche ciblée. | `age_min_months = 12`<br>`age_max_months = 35` |
| `ELIG_AGE_DOB` | Âge déclaré incohérent avec la date de naissance | Métier | Enfant | Majeure | Écart entre l'âge déclaré (années/mois) et l'âge calculé depuis la date de naissance supérieur à la tolérance. | `tolerance_months = 2` |
| `VAC_BEFORE_DOB` | Vaccin avant la naissance | Métier | Enfant | Critique | Date de vaccination antérieure à la date de naissance. | — |
| `VAC_AFTER_INTERVIEW` | Vaccin après l'interview | Métier | Enfant | Critique | Date de vaccination postérieure à la date d'interview. | — |
| `VAC_DOSE_ORDER` | Ordre des doses non respecté | Métier | Enfant | Majeure | Dose n+1 datée avant la dose n (Penta, VPO, VPI, PCV, Rota, VAP, RR). | — |
| `VAC_DOSE_INTERVAL` | Intervalle entre doses trop court | Métier | Enfant | Majeure | Moins de N jours entre deux doses successives d'une même série. | `min_interval_days = 28` |
| `VAC_MIN_AGE` | Âge minimal de vaccination non respecté | Métier | Enfant | Majeure | Dose administrée avant l'âge minimal du calendrier (ex. RR1 avant 9 mois). Âges minimaux dans config/gavi.php, avec calendrier propre à une zone sanitaire possible (ex. VAP 4 dès 15 mois à Dassa-Glazoué) ; tolérance en jours. | `tolerance_days = 0` |
| `VAC_STATUS_MISMATCH` | Statut vaccinal incohérent avec la date | Métier | Enfant | Majeure | Date saisie alors que le vaccin est déclaré « non reçu », ou dates saisies alors que l'enfant n'a « jamais reçu de vaccin ». | — |
| `CARD_NO_DATES` | Carnet vu sans aucune date | Métier | Enfant | Majeure | Le carnet a été consulté mais aucune date de vaccination n'a été saisie (ni motif de date manquante). | — |
| `PHOTO_CHECK` | Saisie différente de la photo | Métier | Enfant | Majeure | Valeur saisie différente de celle lue sur la photo du document (carnet, acte de naissance), d'après les vérifications enregistrées dans `photo_checks`. Fermée automatiquement quand la saisie corrigée correspond à la photo ; une vérification sans valeur attendue reste ouverte jusqu'à sa clôture manuelle. | — |
| `CONSENT_REFUSED_DATA` | Données saisies malgré un refus | Métier | Ménage | Critique | Ménage non enquêté (refus, absent…) avec des caregivers saisis, ou caregiver ayant refusé avec des enfants saisis. | — |
| `CONSENT_NO_CAREGIVER` | Module caregiver vide | Métier | Ménage | Majeure | Ménage ayant accepté l'enquête sans aucun caregiver interviewé. | — |
| `GPS_MISSING` | GPS manquant | Métier | Ménage | Majeure | Coordonnées GPS absentes. | — |
| `GPS_ACCURACY` | Précision GPS insuffisante | Métier | Ménage | Mineure | Précision du point GPS supérieure au seuil (mètres). | `max_accuracy_m = 50` |
| `GPS_CLUSTER_DISTANCE` | Point trop loin de la grappe | Métier | Ménage | Majeure | Distance au centroïde de la grappe (coordonnées importées, sinon médiane des points de la grappe) supérieure au seuil. | `max_distance_km = 5`<br>`min_points = 3` |
| `GPS_OUTSIDE_COMMUNE` | Point hors de la commune | Métier | Ménage | Mineure | Approximation sans polygones : distance au centroïde (médiane) des points de la commune supérieure au rayon. | `radius_km = 50`<br>`min_points = 5` |
| `HH_CODE` | Identifiant ménage non conforme | Métier | Ménage | Majeure | Identifiant hors format ZONE-VIL-NN[-Sx]-MMM, préfixe de zone inconnu ou différent de la zone déclarée, code village inconnu, ou composante S différente de la sous-grappe sélectionnée dans le formulaire. | — |
| `GEO_HIERARCHY` | Grappe / village incohérents | Métier | Ménage | Critique | Le village déclaré n'appartient pas à l'arrondissement ou à la commune déclarés, ou la grappe n'est pas rattachée au village. | — |
| `ROSTER_MEMBERS` | Roster des membres non conforme | Métier | Ménage | Majeure | Nombre de membres saisis dans le roster du ménage différent du nombre de personnes déclarées (personnes ayant dormi dans le ménage). | — |
| `ROSTER_CAREGIVERS` | Roster des caregivers non conforme | Métier | Ménage | Majeure | Nombre de caregivers saisis différent du nombre de mères / gardien(ne)s déclaré(e)s. | — |
| `ROSTER_CHILDREN` | Roster des enfants non conforme | Métier | Caregiver | Majeure | Pour un caregiver, nombre d'enfants saisis différent du nombre d'enfants de moins de 5 ans déclaré. | — |
| `TEAM_VILLAGE` | Village hors de ceux du binôme | Métier | Ménage | Majeure | Village coché hors des villages attribués au binôme (`binomes_villages.csv`), ou agent sélectionné d'un autre binôme que celui du compte ODK qui a soumis. Le compte ODK (binôme sélectionné dans ≥ 80 % de ses soumissions) et le code village de l'identifiant ménage départagent : agent mal sélectionné, village coché faux (village de l'identifiant proposé), ou binôme hors de ses villages. La query est confiée au binôme du compte ODK et visible des deux binômes ; suivi sur la page « Villages des binômes ». | — |
| `PARA_DURATION` | Durée d'interview anormale | Métier | Ménage | Mineure | Durée (end − start) inférieure au minimum ou supérieure au maximum, pour les ménages ayant accepté. | `min_minutes = 10`<br>`max_minutes = 120` |
| `PARA_OFF_HOURS` | Interview hors heures de travail | Métier | Ménage | Mineure | Début avant l'heure d'ouverture ou fin après l'heure de fermeture. | `start_hour = 7`<br>`end_hour = 19` |
| `PARA_TODAY_START` | Date « today » différente de « start » | Métier | Ménage | Mineure | La date du jour enregistrée ne correspond pas à la date de début d'interview. | — |
| `PARA_DAILY_VOLUME` | Volume journalier anormal | Métier | Ménage | Mineure | Nombre d'interviews d'un agent sur une journée supérieur au maximum. | `max_per_day = 20` |
| `PARA_LATE_SUBMISSION` | Soumission tardive | Métier | Ménage | Mineure | Délai entre la fin de l'interview et l'envoi au serveur supérieur au seuil (jours). | `max_days = 2` |
| `OUTLIER` | Valeur numérique aberrante | Métier | Ménage | Mineure | Valeur hors de [Q1 − k·IQR ; Q3 + k·IQR] (ou \|z\| > seuil) au sein de la grappe ou de l'agent. | `method = iqr`<br>`iqr_k = 3`<br>`z_threshold = 3`<br>`group_by = collector`<br>`min_group_size = 8`<br>variables : `num_slept_last_night,num_bednets,hh_caregivers_num,age_years,caregiver_num_children` |

Les âges minimaux par antigène (en jours) utilisés par `VAC_MIN_AGE` et les séries de doses contrôlées
par `VAC_DOSE_ORDER` et `VAC_DOSE_INTERVAL` se règlent dans `config/gavi.php` (`vaccines`,
`dose_series`). Valeurs par défaut : BCG, HepB et VPO0 dès la naissance ; VPO1, Penta1, PCV1 et Rota1
à 42 j ; doses 2 à 70 j ; doses 3 à 98 j ; VPI1 à 98 j ; VAP1, 2 et 3 à 150, 180 et 210 j ; RR1, VAA et
MenA à 270 j ; RR2 à 450 j ; VAP4 à 540 j.

## Règles du document qualité Bénin (cov26c4v2)

Règles du document « Règles de qualité des données – Bénin (cov26c4v2) » qui complètent les règles livrées ci-dessus, sans les remplacer : les contrôles du document déjà couverts (doublons, géographie, ordre et âge minimal des doses, rosters, GPS, paradonnées…) restent assurés par les règles existantes. Codes du document (CRI-10 → `CRI_10`). La plupart sont des règles par expression, modifiables dans **Règles** ; six sont créées désactivées, à activer après examen (volume élevé au test sur les données du 20/11/2026).

| Code | Règle | Sévérité | Nature | Active | Action à faire |
|---|---|---|---|---|---|
| `CRI_05` | GPS hors du Bénin | Critique | Query | Oui | Reprendre le point GPS à la revisite. |
| `CRI_07` | Ménage accepté sans membres | Critique | Query | Oui | Compléter le nombre de personnes et la liste des membres. |
| `CRI_08` | Données saisies malgré le refus du caregiver | Critique | Query | Oui | Vider ses données, ou corriger le consentement. |
| `CRI_10` | Date de naissance dans le futur | Critique | Query | Oui | Vérifier le document et corriger la date. |
| `CRI_11` | Âge de l'enfant impossible à calculer | Critique | Query | Oui | Saisir la date de naissance ou l'âge. |
| `CRI_12` | Éligibilité contradictoire | Critique | Query | Oui | Vérifier la date de naissance et l'âge, puis corriger. |
| `CRI_13` | Enfant éligible sans section vaccination | Critique | Query | Oui | Compléter la section vaccination. |
| `CRI_20` | Même point GPS pour deux ménages | Critique | Query | Non (à valider) | Vérifier la visite ; reprendre le point GPS si besoin. |
| `MAJ_07` | Sous-circuit invalide | Majeure | Query | Oui | Corriger le nombre de sous-grappes ou la sous-grappe tirée. |
| `MAJ_08` | Nombre de caregivers incohérent avec la liste des membres | Majeure | Query | Non (à valider) | Corriger le nombre de caregivers ou la liste des membres. |
| `MAJ_09` | Plus d'enfants déclarés que d'enfants listés | Majeure | Query | Oui | La fiche liste le nombre d'enfants déclaré par chaque caregiver et la tranche d'âge des membres de 5 ans ou plus, corrigeables chacun dans son membre. |
| `MAJ_10` | Ménage sans adulte | Majeure | Query | Oui | La fiche liste les membres (initiales, sexe, tranche d'âge cochée), corrigeables chacun ; « Aucune » confirme la query sans correction (ménage réellement sans adulte). |
| `MAJ_11` | Caregiver de moins de 15 ans | Majeure | Query | Oui | La query montre la réponse « caregiver » (q. 27), la tranche d'âge du membre (q. 24) et l'âge du caregiver interrogé (q. 34) ; chacune est corrigeable. |
| `MAJ_12` | Âge du caregiver impossible | Majeure | Query | Oui | Vérifier l'âge et corriger. |
| `MAJ_13` | Parent trop jeune pour l'enfant | Majeure | Query | Oui | Vérifier l'âge du caregiver, l'âge de l'enfant ou le lien. |
| `MAJ_14` | Sexe incohérent avec le lien de parenté | Majeure | Query | Oui | Corriger le sexe ou le lien de parenté. |
| `MAJ_15` | Caregiver sans enfant | Majeure | Query | Oui | Vérifier le nombre d'enfants déclaré. |
| `MAJ_16` | Années d'études coraniques impossibles | Majeure | Query | Oui | Corriger les années d'études ou l'âge. |
| `MAJ_18` | Enfant de 5 ans ou plus | Majeure | Query | Oui | Vérifier l'âge ; signaler l'enfant en trop. |
| `MAJ_19` | Date de naissance documentée sans photo | Majeure | Query | Oui | Photographier le document à la revisite. |
| `MAJ_26` | Dose reçue sans la précédente (carte) | Majeure | Query | Oui | Relire la carte et corriger. |
| `MAJ_27` | Carte vue sans photo | Majeure | Query | Oui | Photographier la carte à la revisite. |
| `MAJ_28` | Photos de la carte manquantes | Majeure | Query | Oui | Photographier les pages manquantes. |
| `MAJ_29` | VAP 4 trop proche de VAP 3 | Majeure | Query | Oui | La query donne les dates de VAP 3 et de VAP 4 et leur écart ; « Quelle valeur est fausse ? » propose de corriger VAP 3 ou VAP 4. |
| `MAJ_31` | Rappel : dose reçue sans la précédente | Majeure | Query | Oui | Revoir le rappel avec le caregiver. |
| `MAJ_32` | Rappel : dose impossible pour l'âge | Majeure | Query | Oui | La query nomme le vaccin, la dose, la question et l'âge minimal. Corriger la date de naissance ou la réponse au rappel. |
| `MAJ_34` | Revue « 3 doses de VAP » incohérente | Majeure | Query | Oui | La query détaille la carte, le rappel et l'âge. Carnet avec le cachet « Enfant complètement vacciné » : l'enfant a tout reçu, même si les cases sont vides (carnet vu sans aucune case VAP cochée : pas de query). Sinon corriger la revue, la carte ou le rappel. |
| `MAJ_35` | Revue de 2e année incohérente | Majeure | Query | Non (à valider) | Corriger la réponse de revue. |
| `MAJ_36` | Question de 2e année posée trop tôt | Majeure | Query | Oui | Corriger en « N/A ». |
| `MAJ_37` | Cycles CPS dans le désordre | Majeure | Query | Oui | La query liste toutes les dates de cycles et les cycles en cause ; chaque date en cause peut être corrigée. |
| `MAJ_38` | Cycles CPS trop rapprochés | Majeure | Query | Oui | La query liste toutes les dates de cycles et les cycles trop rapprochés ; chaque date saisie est corrigeable ; « Aucune » confirme (erreur sur la carte CPS). |
| `MAJ_40` | Cycle CPS avant la naissance | Majeure | Query | Oui | Relire la carte CPS et la date de naissance. |
| `MAJ_41` | Carte CPS contredit la déclaration | Majeure | Query | Oui | La query donne la déclaration et les cycles cochés sur la carte (avec leurs dates) ; corriger la déclaration, un ou plusieurs cycles, ou « Aucune » (confirmée sans correction). |
| `MAJ_42` | Nombre de traitements CPS incohérent | Majeure | Query | Non (à valider) | Corriger le nombre de traitements. |
| `MAJ_43` | CPS avant 3 mois | Majeure | Query | Oui | Relire la carte CPS et la date de naissance. |
| `MAJ_47` | Entretiens qui se chevauchent | Majeure | Query | Non (à valider) | Vérifier les heures de la tablette avec le binôme. |
| `OBS_05` | Initiales du caregiver mal formatées | Mineure | Observation | Oui | Saisir des initiales (1 à 5 lettres). |
| `OBS_11` | Taille du ménage atypique | Mineure | Observation | Oui | Vérifier le nombre de personnes. |
| `OBS_13` | Études coraniques très longues | Mineure | Observation | Oui | Vérifier la durée. |
| `OBS_16` | Naissance le jour de l'entretien | Mineure | Observation | Oui | Vérifier la date de naissance. |
| `OBS_17` | Vaccins d'un même contact à des dates éloignées | Mineure | Observation | Oui | Relire la carte. |
| `OBS_18` | HepB de naissance tardive | Mineure | Observation | Oui | Relire la date sur la carte. |
| `OBS_19` | Rota 1 tardif | Mineure | Observation | Oui | Relire la date sur la carte. |
| `OBS_20` | Cicatrice BCG incohérente | Mineure | Observation | Oui | Revoir avec le caregiver. |
| `OBS_21` | Rappel contredit la carte | Mineure | Observation | Oui | Revoir avec le caregiver. |
| `OBS_22` | Photos insuffisantes pour le carnet | Mineure | Observation | Oui | Photographier toutes les pages. |
| `OBS_23` | Carte CPS sans CPS déclarée | Mineure | Observation | Oui | Revoir avec le caregiver. |
| `OBS_24` | Carte CPS sans cycle traité | Mineure | Observation | Oui | Relire la carte CPS. |
| `OBS_25` | Rappel CPS contredit la carte | Mineure | Observation | Oui | Revoir avec le caregiver. |
| `OBS_28` | Type de murs incohérent | Mineure | Observation | Oui | Vérifier les murs. |
| `OBS_29` | Toit ou sol non renseigné | Mineure | Observation | Non (à valider) | Vérifier le toit et le sol. |
| `OBS_30` | Électricité incohérente | Mineure | Observation | Oui | Vérifier l'électricité. |
| `OBS_31` | Animaux sans espèce | Mineure | Observation | Oui | Préciser les animaux. |
| `OBS_32` | Aucun combustible de cuisson | Mineure | Observation | Oui | Vérifier le combustible. |

Non reprises : CRI-09 (découpage des sous-grappes, visible dans « Grappes par village »), MAJ-05 (= module caregiver vide), MAJ-06 (historique des visites), MAJ-20 (= champ obligatoire), MAJ-22, MAJ-30, MAJ-33, MAJ-39 et MAJ-44 (paramètres « à fournir » ou contrôles à préciser), OBS-01 à OBS-04 et OBS-06 à OBS-10 (déjà couvertes), OBS-12, OBS-14, OBS-15, OBS-26, OBS-27 et AVT-01.

## Règles personnalisées (expressions)

Une règle de type « Expression personnalisée » contient une expression XLSForm qui décrit la
**condition d'erreur** : une query est ouverte quand l'expression est vraie. Elle est évaluée dans
chaque instance de la portée choisie (ménage, caregiver ou enfant), et les variables des niveaux
parents restent accessibles. Exemple livré, `EXPR_BEDNETS` :

```
${num_bednets} > ${num_slept_last_night}
```

Le bouton « Tester sur les données » évalue l'expression sur les 300 dernières soumissions avant de
l'enregistrer.

## Limites connues

- `GPS_OUTSIDE_COMMUNE` est une approximation : sans limites administratives, elle mesure la distance
  à la médiane des points de la commune.
- `GPS_CLUSTER_DISTANCE` utilise les coordonnées de la grappe si elles ont été importées, sinon la
  médiane des points GPS de la grappe (3 points au minimum).
- `OUTLIER` ignore les groupes de moins de 8 valeurs et les groupes sans dispersion (IQR nul).

## Suggestions de résolution par défaut

Recopiées dans chaque query et dans les exports ; modifiables par règle (Paramètres › Règles qualité). Gabarits : `{question}` libellé de la question, `{variable}` nom XLSForm, `{valeur}` valeur observée, `{attendu}` valeur attendue.

| Code | Suggestion |
|---|---|
| `DICT_REQUIRED` | Contacter le binôme (ou revisiter le ménage) pour obtenir la réponse à « {question} », puis compléter la soumission par une édition dans ODK/Kobo. |
| `DICT_CONSTRAINT` | Vérifier la valeur saisie pour « {question} » ({valeur}) : elle doit respecter « {attendu} ». Corriger s'il s'agit d'une erreur de saisie, sinon justifier. |
| `DICT_CHOICE` | La valeur {valeur} n'existe pas dans la liste de choix de « {question} ». Vérifier la version du formulaire utilisée sur la tablette et corriger la réponse. |
| `DICT_TYPE` | Corriger le format de « {question} » (attendu : {attendu}). |
| `DICT_RELEVANT` | Vérifier les réponses qui conditionnent « {question} » (saut) : supprimer la valeur si la question ne devait pas être posée, ou corriger la réponse filtre. |
| `OTHER_SPECIFY` | Compléter la précision « autre » de « {question} » auprès du binôme ou du répondant. |
| `DUP_HOUSEHOLD` | Comparer les deux soumissions ({attendu}) avec le binôme : rejeter le doublon sur le serveur ou corriger l'identifiant du ménage. |
| `DUP_CHILD` | Vérifier avec le binôme si l'enfant a été saisi deux fois et supprimer l'enregistrement en double. |
| `DUP_CARD` | Retourner au ménage : s'il s'agit du même enfant, supprimer l'enregistrement en double ; s'il s'agit de jumeaux, photographier et saisir le carnet de chaque enfant. |
| `DUP_SUBMISSION` | Identifier la soumission à conserver ({attendu}) et rejeter l'autre sur le serveur (statut « rejeté » ODK, validation Kobo). |
| `ELIG_AGE` | Vérifier la date de naissance sur le document. Si elle est exacte, l'enfant n'était pas éligible : retirer ses données de vaccination ou justifier. |
| `ELIG_AGE_DOB` | Recouper l'âge déclaré ({valeur}) et la date de naissance ({attendu}) avec le caregiver ou le document, puis corriger la valeur erronée. |
| `VAC_BEFORE_DOB` | Relire sur le carnet la date « {question} » ({valeur}) et la date de naissance ; corriger l'erreur de saisie (jour/mois inversés, année). |
| `VAC_AFTER_INTERVIEW` | La date « {question} » ({valeur}) est postérieure à l'interview : relire le carnet et corriger (erreur d'année probable). |
| `VAC_DOSE_ORDER` | Relire sur le carnet les dates de la série ; corriger l'inversion des doses ou du jour et du mois pour « {question} ». |
| `VAC_DOSE_INTERVAL` | Vérifier sur le carnet les dates des deux doses ({valeur} d'écart). Si elles sont exactes, justifier (rattrapage). |
| `VAC_MIN_AGE` | Vérifier la date « {question} » et la date de naissance. Si elles sont exactes, justifier (dose administrée précocement). |
| `VAC_STATUS_MISMATCH` | Harmoniser le statut « reçu » et la date selon le carnet : corriger « {question} » ou supprimer la date. |
| `CARD_NO_DATES` | Le carnet a été vu : saisir les dates de vaccination, ou le motif de date manquante pour chaque vaccin. |
| `PHOTO_CHECK` | Corriger « {question} » dans ODK Central d'après la photo : {attendu} au lieu de {valeur}. |
| `CONSENT_REFUSED_DATA` | Vérifier le statut de l'enquête et le consentement avec le binôme : corriger le statut ou supprimer les données saisies sans consentement. |
| `CONSENT_NO_CAREGIVER` | Vérifier pourquoi aucun caregiver n'a été saisi : compléter le module ou corriger le statut du ménage. |
| `GPS_MISSING` | Reprendre le point GPS lors d'une revisite, ou justifier (panne GPS, refus). |
| `GPS_ACCURACY` | Reprendre le point GPS à l'extérieur avec une meilleure précision (valeur actuelle : {valeur}) en cas de revisite, sinon justifier. |
| `GPS_CLUSTER_DISTANCE` | Vérifier que le ménage appartient bien à la grappe déclarée ({valeur} du centre) : corriger la grappe ou le village, ou justifier. |
| `GPS_OUTSIDE_COMMUNE` | Vérifier la commune et le village déclarés ainsi que la position GPS, puis corriger la localisation. |
| `HH_CODE` | Corriger l'identifiant du ménage dans ODK Central ({valeur}) au format attendu : {attendu}. Vérifier avec le binôme la grappe, la sous-grappe et le numéro d'ordre. |
| `GEO_HIERARCHY` | Corriger le village, l'arrondissement ou la commune sélectionnés pour qu'ils soient cohérents ({attendu}). |
| `TEAM_VILLAGE` | Selon le diagnostic : corriger l'agent sélectionné, le village coché (village de l'identifiant ménage pré-coché) ou justifier. |
| `PARA_DURATION` | Échanger avec le binôme sur le déroulement de l'interview ({valeur}) ; justifier, ou contrôler le ménage. |
| `PARA_OFF_HOURS` | Vérifier l'heure de la tablette et les conditions de l'interview ({valeur}) avec le binôme, puis justifier. |
| `PARA_TODAY_START` | Vérifier et régler la date et l'heure de la tablette du binôme, puis justifier la soumission. |
| `PARA_DAILY_VOLUME` | Revoir avec le superviseur la charge du binôme ce jour-là ({valeur} interviews) et contrôler un échantillon de ses interviews. |
| `PARA_LATE_SUBMISSION` | Rappeler au binôme d'envoyer les formulaires chaque jour et vérifier la connectivité de la tablette. |
| `OUTLIER` | Vérifier la valeur {valeur} de « {question} » avec le binôme ; corriger s'il s'agit d'une erreur de saisie, sinon justifier. |

## Queries et observations de supervision

Chaque règle a une **nature** (modifiable dans l'écran de la règle) :

- **Query de données** : une valeur à corriger ou à confirmer. Elle suit le cycle de résolution ci-dessous.
- **Observation de supervision** : un fait relevé sur les paradonnées, à examiner avec les binômes, sans valeur à corriger. Les observations sont dans le menu **Observations de supervision**, hors des listes, indicateurs et exports de queries. Par défaut : `PARA_DURATION`, `PARA_OFF_HOURS`, `PARA_TODAY_START`, `PARA_DAILY_VOLUME`, `PARA_LATE_SUBMISSION`, `GPS_ACCURACY`, `GPS_OUTSIDE_COMMUNE`.

Changer la nature d'une règle reclasse toutes ses anomalies. Rien n'est supprimé.

## Cycle de résolution (BPC/GCP)

**Ouverte → Assignée → Réponse → Validation par le data manager → Fermée**

| Étape | Qui | Règle |
|---|---|---|
| Affectation | data manager, superviseur (sa zone) | À un data manager, un superviseur ou un compte binôme qui a accès à la query. Échéance donnée, ou échéance par défaut des paramètres. Retirer l'affectation la remet en « Ouverte ». |
| Réponse : correction proposée | l'assigné, ou l'équipe de traitement de la zone | Description de la correction et de sa source. |
| Réponse : valeur confirmée | idem | Justification obligatoire (source vérifiée : carnet, photo, revisite…). |
| Validation | data manager | Ferme la query. Possible à l'unité ou en bloc. |
| Rejet de la réponse | data manager | Motif obligatoire ; la query revient à l'assigné. |
| Irrésoluble | data manager | Motif obligatoire ; la query est fermée. |
| Réouverture | data manager | Motif obligatoire. |

**Double validation** (sévérités paramétrables, critiques par défaut) :
- la réponse est validée par une autre personne que son auteur ;
- le validateur signe en ressaisissant son mot de passe, pour une validation comme pour un « irrésoluble » ;
- l'événement est marqué « signé » dans l'historique.

**Paramètres** (administrateur, menu **Paramètres de l'application**) : affectation automatique des nouvelles queries au compte du binôme, échéance par défaut, sévérités en double validation. Chaque modification est tracée dans le journal d'audit.

Chaque action est tracée dans l'historique de la query : auteur, date, ancien et nouveau statut, motif, signature.

## Base nettoyée et registre des corrections

Les données d'ODK ne sont jamais modifiées. La plateforme conserve :

- la **source** : chaque soumission telle que reçue (`raw_data` pour la réponse ODK brute, `source_data` pour sa version normalisée) ;
- le **registre des corrections** (menu **Registre des corrections**) : pour chaque correction, la variable et le membre concernés, la valeur reçue d'ODK, la valeur remplacée, la nouvelle valeur, le motif, la référence de la source, l'auteur, la décision du data manager et sa signature. Le registre ne s'efface pas : une correction validée est remplacée par la suivante sur le même champ, jamais réécrite ;
- la **base nettoyée** : la source, à laquelle on applique les corrections validées dans leur ordre de validation. Tableaux de bord, rapports et règles qualité s'appuient sur elle. Elle est reconstruite à chaque import et après chaque validation, donc une resynchronisation ODK n'efface pas les corrections.

**Correction d'une query.** On répond « Correction proposée » sur la fiche de la query :
- la fiche montre la variable avec sa valeur actuelle et sa valeur reçue d'ODK ;
- on saisit la nouvelle valeur, le motif et la source, et on peut corriger d'autres variables de la même soumission (membre au format `cg[1]/smc_cov_hh_cg_child_2023[2]`) ;
- la valeur est contrôlée selon le dictionnaire (question saisissable, type, liste de choix, membre existant, valeur différente de l'actuelle).

**Prise en compte immédiate** (paramètre, activé par défaut). Une correction qui lève l'erreur sans faire apparaître d'autre query est appliquée tout de suite, et la query est résolue. Le data manager la revoit dans le registre (« À revoir ») : il l'accepte, ou il la refuse avec un motif, ce qui rétablit la valeur précédente et rouvre la query si l'erreur revient. Les autres corrections attendent la validation ci-dessous.

**Validation.** Quand le data manager valide, les corrections s'appliquent à la base nettoyée et le moteur relance les règles sur la soumission :
- si l'anomalie a disparu, la query est **résolue** ;
- sinon, elle **revient à l'assigné**, et les corrections restent appliquées et tracées.

Un rejet ou un « irrésoluble » rejette les corrections proposées, qui restent au registre.

**Conflit.** Si la valeur reçue d'ODK change après une correction, la correction est suspendue (« En conflit avec la source ») et la valeur d'ODK s'applique. Le data manager revoit ces corrections depuis le registre.

**Exclusion d'un doublon.** Sur une query `DUP_HOUSEHOLD` ou `DUP_SUBMISSION`, la réponse « Correction proposée » peut demander d'exclure la soumission de la base nettoyée, en indiquant la soumission conservée. L'exclusion suit le même circuit que toute correction : registre, validation par le data manager, signature selon la sévérité. Une fois validée :
- la soumission disparaît de la base nettoyée, des tableaux de bord et du moteur, et ses queries sont fermées avec ce motif ;
- le moteur repasse sur toutes les soumissions, ce qui lève aussi le doublon chez la soumission conservée ;
- la soumission reste dans la base brute, et sa fiche reste consultable.

## Exports des bases (administrateur)

Menu **Exports des bases**. La génération se fait en arrière-plan. Chaque fichier est un ZIP qui contient :
- `manifest.json` : pour chaque fichier, l'empreinte SHA-256, le nombre de lignes et la description, plus la version du formulaire, la révision de l'application, l'auteur et la date ;
- `LISEZMOI.txt` : la notice.

| Export | Contenu |
|---|---|
| Base brute | Chaque soumission telle que reçue d'ODK, y compris les exclues (`exclue_base_nettoyee`). Une table CSV par niveau, nommée comme dans l'export CSV d'ODK Central et préfixée de `app_brute_` et de la date de l'export : `app_brute_AAAAMMJJ_<formulaire>.csv` pour les ménages, `app_brute_AAAAMMJJ_<formulaire>-<repeat>.csv` pour chaque repeat (base nettoyée : préfixe `app_nettoyee_`). Le membre est identifié par son chemin. |
| Base nettoyée | Mêmes tables, avec les corrections validées appliquées et les doublons exclus, plus `registre_corrections.csv`. |
| Paquet d'archivage | Bases brute et nettoyée, registre, `queries.csv`, `historique_queries.csv`, `observations_supervision.csv`, `dictionnaire.csv`. |
| Copie SQL | Structure et données de toutes les tables (hors cache, sessions, file d'attente), dans un ZIP chiffré AES-256. Le mot de passe de chiffrement est saisi par l'administrateur, qui signe aussi avec son propre mot de passe. La plateforme ne conserve pas le mot de passe de chiffrement. |

Les CSV sont en UTF-8 avec BOM, avec la virgule comme séparateur. Les deux bases ont les mêmes colonnes : toutes les variables du formulaire, dans l'ordre du formulaire.

Chaque demande et chaque téléchargement sont tracés au journal d'audit. Les fichiers sont supprimés au bout de 7 jours ; la trace, l'empreinte et le manifeste sont conservés. Le registre des corrections s'exporte aussi en Excel depuis sa page.

## Statuts de résolution

| Statut de résolution | Statuts détaillés | Date de résolution |
|---|---|---|
| Non résolu | Ouverte, Assignée | — |
| Réponse à valider | Valeur confirmée – à valider, Correction proposée – à valider | — |
| Résolu | Fermée (validée) | date de validation |
| Irrésoluble | Irrésoluble (fermée), motif obligatoire | date de la décision |

Une query rouverte perd sa date de résolution. Une anomalie qui disparaît à la vérification suivante (donnée corrigée) ferme la query automatiquement ; si elle réapparaît, la query est rouverte et rendue à son assigné.
