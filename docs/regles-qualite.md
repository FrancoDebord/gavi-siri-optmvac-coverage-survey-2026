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

## Statuts de résolution

| Statut de résolution | Statuts détaillés | Date de résolution |
|---|---|---|
| Non résolu | Ouverte, Assignée | — |
| Réponse à valider | Valeur confirmée – à valider, Correction proposée – à valider | — |
| Résolu | Fermée (validée) | date de validation |
| Irrésoluble | Irrésoluble (fermée), motif obligatoire | date de la décision |

Une query rouverte perd sa date de résolution. Une anomalie qui disparaît à la vérification suivante (donnée corrigée) ferme la query automatiquement ; si elle réapparaît, la query est rouverte et rendue à son assigné.
