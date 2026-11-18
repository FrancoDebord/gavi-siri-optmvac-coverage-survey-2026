# SOP-DM-01 – Gestion des queries

| Référence | SOP-DM-01 | Version | 1.0 |
|---|---|---|---|
| Date | 18/11/2026 | Entrée en vigueur | *[à compléter]* |
| Rédigé par | *[à compléter]* | Approuvé par | *[à compléter : investigateur principal]* |

## 1. Objet

Définir comment une query de données est traitée, de sa détection à sa clôture, sans jamais modifier les données d'ODK et en conservant une piste d'audit complète.

## 2. Champ d'application

S'applique à toutes les queries de données générées par le moteur de la plateforme.

Ne s'applique pas aux **observations de supervision** (paradonnées), qui sont examinées avec les équipes sans cycle de résolution.

## 3. Responsabilités

| Acteur | Rôle dans la procédure |
|---|---|
| Data manager | Lance le moteur, trie, affecte, valide ou rejette les réponses, décide « irrésoluble », rouvre, revoit les conflits. |
| Superviseur | Affecte et répond aux queries de sa zone ; vérifie sur le terrain. |
| Compte binôme | Répond aux queries qui lui sont affectées, d'après la pièce source. |
| Investigateur principal | Suit les indicateurs ; approuve les écarts avant le gel final. |
| Administrateur | Paramètres (affectation automatique, échéance, double validation), gel et dégel. |

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

1. Consulter la query : message, valeur observée et attendue, suggestion de résolution, photos.
2. Vérifier sur la pièce source, puis choisir une réponse :

| Situation | Réponse | Exigences |
|---|---|---|
| La valeur est fausse | **Correction proposée** | Nouvelle valeur pour chaque variable concernée (plusieurs possibles dans la soumission), motif, référence de la pièce source. La valeur est contrôlée selon le dictionnaire. |
| La valeur est exacte | **Valeur confirmée** | Justification et pièce source. |
| Soumission en double | **Correction proposée → exclusion** | Sur une query de doublon uniquement ; indiquer la soumission conservée. |

3. Si on ne peut ni corriger ni confirmer, l'assigné l'explique en commentaire. Seul le data manager peut alors déclarer la query « irrésoluble ».

### 5.4 Validation (data manager)

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
