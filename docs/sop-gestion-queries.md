# SOP-DM-01 – Gestion des queries

| Référence | SOP-DM-01 | Version | 1.0 |
|---|---|---|---|
| Date | 18/11/2026 | Entrée en vigueur | *[à compléter]* |
| Rédigé par | *[à compléter]* | Approuvé par | *[à compléter : investigateur principal]* |

## 1. Objet

Définir comment une query de données est traitée, de sa détection à sa clôture, sans jamais modifier les données d'ODK et en conservant une piste d'audit complète.

## 2. Champ d'application

S'applique à toutes les queries de données générées par le moteur de la plateforme.

Ne s'applique pas aux **observations de supervision** (paradonnées), qui sont examinées avec les équipes sans cycle de résolution, sauf celles que l'administrateur ou le data manager envoie dans les queries (voir 6).

## 3. Responsabilités

| Acteur | Rôle dans la procédure |
|---|---|
| Data manager | Lance le moteur, trie, affecte ; peut faire la vérification à la place du superviseur ; **validation finale** (à l'unité ou en bloc), rejet, « irrésoluble », réouverture, revue des conflits. |
| Superviseur | Affecte et répond aux queries de sa zone ; **vérifie les réponses des binômes de sa zone** (photo, carnet, terrain) et les valide (première étape) ou les rejette. |
| Validateur (compte dédié) | Valide ou rouvre les queries dont la validation lui est confiée ; arrive directement sur « À valider par moi ». |
| Compte binôme | Se connecte avec l'identifiant `nom1.nom2` (noms des deux agents) et arrive sur ses queries. Il répond à toutes les queries de ses agents d'après la pièce source, sans attendre d'affectation. |
| Investigateur principal | Suit les indicateurs ; approuve les écarts avant le gel final. |
| Administrateur | **Validation finale** des réponses vérifiées (à l'unité ou en bloc), mêmes droits que le data manager ; paramètres (affectation automatique, échéance, double validation), gel et dégel. |

## 4. Définitions

| Terme | Définition |
|---|---|
| Query | Anomalie sur une valeur de données, à corriger ou à confirmer. Code `Q-nnnnnn`. |
| Pièce source | Document ou constat qui fonde une réponse : carnet de vaccination ou sa photo, acte de naissance, revisite, appel au répondant, vérification du superviseur. |
| Base nettoyée | Source ODK + corrections validées, doublons exclus. |
| Double validation | Validation par une personne autre que l'auteur de la réponse, avec signature (mot de passe). Par défaut pour les queries critiques ; paramétrable. |
| Membre | Instance de repeat concernée, notée par son chemin, par exemple `cg[1]/smc_cov_hh_cg_child_2023[2]` (caregiver 1, enfant 2). |

## 5. Procédure

### 5.1 Détection et tri (data manager)

1. Le moteur passe après chaque synchronisation et chaque nuit. Le data manager peut le relancer depuis la liste des queries.
2. Revue quotidienne de la liste, filtrée sur « Non résolu », triée par sévérité puis par date de collecte.

### 5.2 Affectation (data manager ou superviseur)

1. Affecter chaque query, à l'unité ou en bloc :
   - au compte du binôme qui a collecté le ménage, pour une vérification sur la pièce source ou une revisite ;
   - au superviseur de la zone, pour une vérification de terrain ;
   - à un data manager, pour une erreur vérifiable sur photo.
2. Fixer l'échéance. Par défaut, elle est de *[3]* jours (paramètre).
3. L'affectation automatique au compte du binôme peut être activée par l'administrateur.

### 5.3 Réponse (assigné)

1. Consulter la query, présentée en clair :
   - l'erreur ;
   - la question ;
   - le membre concerné et son URI ODK ;
   - la **valeur saisie** (données brutes ODK) ;
   - la **valeur suggérée** lue sur la photo du document (quand elle a été vérifiée) ;
   - les photos.
2. Vérifier sur la pièce source, puis choisir une réponse :

| Situation | Réponse | Exigences |
|---|---|---|
| La valeur est fausse | **Correction proposée** | Nouvelle valeur pour chaque variable concernée (plusieurs possibles dans la soumission), motif, référence de la pièce source. La valeur est contrôlée selon le dictionnaire. |
| La valeur est exacte | **Valeur confirmée** | Justification et pièce source. |
| Soumission en double | **Correction proposée → exclusion** | Sur une query de doublon uniquement ; indiquer la soumission conservée. |

3. Si on ne peut ni corriger ni confirmer, l'assigné l'explique en commentaire. Seul le data manager peut alors déclarer la query « irrésoluble ».
4. **Recharger les données du ménage** : une valeur corrigée (date de naissance, date de vaccination…) peut lever d'autres queries du même ménage. Après avoir proposé la correction, l'assigné clique sur « Recharger les données du ménage » (fiche d'une query de ce ménage). Les queries encore sans réponse dont l'erreur disparaît avec la correction passent en « Correction proposée » et suivent la query d'origine : elles sont fermées à la validation de sa correction et reviennent à l'assigné si elle est refusée. Rien n'est appliqué aux données avant la validation.

### 5.3 bis Prise en compte immédiate (paramètre, désactivé par défaut)

Une correction proposée est appliquée tout de suite si :
- elle lève l'erreur ;
- elle ne fait apparaître aucune autre query sur la soumission.

La plateforme le vérifie en relançant les règles. Si ces deux conditions sont réunies, la query est résolue sans attendre et la correction est marquée « à revoir ». Sinon, rien n'est appliqué et la correction attend la validation du data manager (5.4), avec le motif affiché.

Le data manager revoit ces corrections dans le registre (« À revoir »). Il peut :
- **les accepter**, à l'unité ou toutes à la fois ;
- **les refuser**, avec un motif : la valeur précédente est rétablie et, si l'erreur revient, la query est rouverte et rendue à l'assigné.

### 5.4 Validation en deux temps

**Étape 1 – Vérification du superviseur.** La réponse d'un binôme part directement chez le ou les superviseurs de sa zone (ou de sa commune) : menu **« À vérifier (superviseur) »**. Le superviseur contrôle la réponse sur la pièce source, puis :
- **Valide** (« Valider (vérification du superviseur) ») : la query passe au statut « Validée par le superviseur » et part à la validation finale. Rien n'est encore appliqué à la base nettoyée.
- ou **Rejette** (motif obligatoire) : la query revient au binôme et les corrections proposées sont rejetées.

Un superviseur ne vérifie jamais sa propre réponse. L'administrateur et le data manager peuvent faire cette vérification. Le data manager peut aussi confier la vérification d'une query à une personne désignée (superviseur, validateur).

**Étape 2 – Validation finale (administrateur, data manager).** Menu **« Validation finale »** : les réponses vérifiées par les superviseurs. Elle se fait à l'unité ou **en bloc** (sélection dans la liste, action « Validation finale et fermeture », une seule signature). L'administrateur peut aussi valider directement une réponse non vérifiée.

1. Revue de chaque réponse : cohérence avec la pièce source et avec les autres données de la soumission.
2. Décision :
   - **Valider.**
     - *Correction* : les corrections sont appliquées à la base nettoyée et le moteur relance les règles. Si l'anomalie a disparu, la query est **résolue**. Sinon, elle revient automatiquement à l'assigné.
     - *Valeur confirmée* : la query est fermée.
     - *Exclusion* : la soumission sort de la base nettoyée et ses queries sont fermées.
   - **Rejeter** : motif obligatoire. La query revient à l'assigné et les corrections proposées sont rejetées (elles restent au registre).
   - **Irrésoluble** : motif obligatoire (ménage injoignable, carnet perdu…). La query est fermée sans correction.
3. **Double validation** (sévérités paramétrées) : le validateur n'est pas l'auteur de la réponse et il signe avec son mot de passe. La plateforme bloque toute validation par l'auteur.
4. **Validation en bloc** : elle est possible, avec une seule signature. Chaque query est contrôlée individuellement, et les refus sont listés.

### 5.5 Réouverture

Le data manager peut rouvrir une query fermée, avec un motif. Une anomalie de nouveau détectée après une fermeture automatique rouvre la query, qui revient à son assigné.

## 6. Cas particuliers

- **Conflit avec la source** : si une valeur corrigée change ensuite dans ODK, la correction est suspendue (« En conflit avec la source ») et la valeur d'ODK s'applique. Le data manager revoit ces corrections depuis le registre et, si besoin, propose une nouvelle correction.
- **Base gelée** : aucune correction ni exclusion n'est possible. Les autres actions (affectation, valeur confirmée, validation d'une confirmation) restent possibles.
- **Query sans variable** (soumission en double…) : on répond par une valeur confirmée, une exclusion ou « irrésoluble ».
- **Observation de supervision envoyée dans les queries** : depuis la page « Observations de supervision », l'administrateur ou le data manager coche des observations encore constatées (ou prend toutes celles du filtre), ajoute au besoin une consigne, puis les envoie dans les queries. Chacune devient une query (badge « observation », filtre « Origine » de la liste). Elle est confiée au compte du binôme quand l'affectation automatique est active, et suit ensuite le cycle normal (5.3 à 5.5).
  - Observation portant sur des données du formulaire (ex. type de murs, carte CPS) : on corrige ou on confirme, comme pour une query.
  - Observation de paradonnées (durée, heure, date de la tablette, volume journalier, délai d'envoi, GPS) : rien n'est à corriger. L'agent choisit l'explication de ce qui s'est passé dans la liste proposée, ou « Autre explication » avec une précision obligatoire. La réponse est enregistrée comme valeur confirmée, puis vérifiée et validée.

## 7. Délais

| Sévérité | Réponse attendue | Validation attendue |
|---|---|---|
| Critique | *[à compléter, ex. 48 h]* | *[ex. 24 h après réponse]* |
| Majeure | *[ex. 3 jours]* | *[ex. 48 h]* |
| Mineure | *[ex. 7 jours]* | *[ex. 3 jours]* |

Les queries non résolues après leur échéance sont signalées « en retard » dans la liste (filtre « En retard »).

## 8. Enregistrements

Tous ces enregistrements sont produits par la plateforme et exportables. Aucun n'est modifiable.
- Historique de chaque query.
- Registre des corrections.
- Piste d'audit.
- Paquet d'archivage de chaque version gelée.

## 9. Indicateurs

- Nombre de queries non résolues, de réponses à valider et de queries en retard.
- Délai de résolution par sévérité.
- Taux de queries par binôme.
- Corrections validées, rejetées et en conflit.

Ils sont revus *[à compléter : fréquence, instance]*.

## 10. Historique des révisions

| Version | Date | Modification | Auteur |
|---|---|---|---|
| 1.0 | 18/11/2026 | Création | *[à compléter]* |
| 1.1 | 20/11/2026 | Validation en deux temps : vérification du superviseur de la zone, puis validation finale de l'administrateur (à l'unité ou en bloc) | *[à compléter]* |
| 1.2 | 22/11/2026 | Rechargement des données du ménage : les queries levées par une correction proposée la suivent (5.3, point 4) | *[à compléter]* |
| 1.3 | 28/11/2026 | Envoi des observations de supervision dans les queries ; explications proposées aux binômes pour les paradonnées | *[à compléter]* |
