# Plan de gestion des données (PGD)

Enquête de couverture vaccinale GAVI SIRI OPTMVAC – Round 2

| Référence | PGD-01 | Version | 1.0 |
|---|---|---|---|
| Date | 18/11/2026 | Statut | Projet, à approuver |
| Rédigé par | *[à compléter]* | Approuvé par | *[à compléter : investigateur principal]* |

## 1. Objet et périmètre

Ce plan décrit comment les données de l'enquête sont collectées, contrôlées, corrigées, gelées, archivées et partagées. Il couvre toutes les données reçues des formulaires ODK : ménages, caregivers, enfants, vaccination, GPS et photos. Il couvre aussi tous les traitements faits sur la plateforme de gestion des données.

## 2. Références

- ICH E6(R3), *Good Clinical Practice* (2025) : intégrité des données, piste d'audit, revue indépendante.
- OMS, *Guideline on data integrity*, Technical Report Series n° 1033, annexe 4 (2021) : principes ALCOA+.
- Principes de 21 CFR Part 11 et de l'annexe 11 des BPF européennes : enregistrements et signatures électroniques.
- SOP-DM-01 *Gestion des queries* (document lié).

## 3. Principes

1. **La source n'est jamais modifiée.** Les données d'ODK sont conservées telles que reçues. Aucune correction n'est faite dans ODK.
2. **Toute correction est tracée.** Pour chaque correction, on enregistre la valeur reçue, la valeur remplacée, la nouvelle valeur, le motif, la pièce source, l'auteur, la date, le validateur et la signature. Le registre ne se modifie pas.
3. **Revue indépendante.** Toute réponse à une query est validée par un data manager. Pour les queries critiques (paramétrable), le validateur n'est pas l'auteur de la réponse et il signe électroniquement.
4. **Résolution constatée, non déclarée.** Une query corrigée est résolue seulement si le moteur ne retrouve plus l'anomalie dans la base nettoyée.
5. **Versions gelées.** L'analyse porte sur une version gelée, numérotée et identifiée par une empreinte SHA-256.

## 4. Rôles et responsabilités

| Rôle | Responsabilités sur la plateforme |
|---|---|
| Administrateur | Comptes et rôles, paramètres, gel et dégel signés, exports des bases et copie SQL chiffrée, consultation de la piste d'audit. |
| Investigateur principal | Suivi de la collecte et de la qualité, rapports, décisions de terrain, consultation du registre des corrections. |
| Data manager | Exécution du moteur de queries, affectation, validation et rejet des réponses (à l'unité ou en bloc), décision « irrésoluble », règles qualité, imports. |
| Superviseur | Suivi de sa zone, affectation et réponse aux queries de sa zone, signalement des incidents. Ne valide pas. |
| Compte binôme | Consultation des données de ses agents, réponse aux queries qui lui sont affectées. Ne valide pas. |
| Lecteur | Consultation des tableaux de bord. |

Les noms des titulaires de chaque rôle figurent en annexe A *[à compléter]*.

## 5. Flux de données

```
Tablettes ODK Collect ──► ODK Central (source, jamais modifiée)
                              │ synchronisation automatique
                              ▼
              Plateforme : copie source (brute) ── registre des corrections
                              │                          │
                              └──────► base nettoyée ◄───┘
                                        (source + corrections validées, doublons exclus)
                                              │
              Moteur de queries ◄─────────────┤──────► tableaux de bord, rapports
                                              │
                              Gel (version Vn) ──► paquet d'archivage (SHA-256)
```

- **Réception** : synchronisation automatique depuis ODK Central (ou import de fichier). Chaque soumission est identifiée par son UUID ODK. Une soumission inchangée n'est pas réécrite.
- **Base brute** : la soumission normalisée telle que reçue et la réponse ODK brute.
- **Base nettoyée** : elle est reconstruite à chaque réception et après chaque validation. Une resynchronisation n'efface donc jamais une correction. Si une valeur change dans ODK après correction, la correction est suspendue (« en conflit ») et soumise à revue.

## 6. Contrôle qualité

- **Règles** (catalogue publié dans la documentation « Règles qualité ») :
  - contrôles dérivés du dictionnaire XLSForm : champs obligatoires, contraintes, listes de choix, types, sauts ;
  - règles métier : doublons, éligibilité, cohérence vaccinale, consentement, géographie, identifiant ménage, valeurs aberrantes.
- **Nature** : chaque règle produit soit des **queries de données** (une valeur à corriger ou à confirmer), soit des **observations de supervision** (paradonnées : heures, durées, volumes, délais d'envoi, précision GPS). Les observations sont examinées avec les équipes, mais n'entrent pas dans le cycle de résolution.
- **Fréquence** : le moteur passe après chaque réception, chaque nuit et à la demande, puis sur la soumission concernée après chaque correction validée.
- **Sévérité** : critique, majeure ou mineure. Elle fixe la priorité et l'exigence de double validation.

## 7. Gestion des queries et des corrections

Le circuit détaillé est décrit dans la SOP-DM-01. En résumé :
1. Ouverture par le moteur.
2. Affectation, avec une échéance.
3. Réponse de l'assigné : correction proposée avec la nouvelle valeur, ou valeur confirmée avec justification.
4. Validation par le data manager.
5. Application à la base nettoyée et vérification par le moteur.
6. Fermeture.

Les doublons sont exclus de la base nettoyée par une correction du même registre ; ils restent dans la base brute.

## 8. Piste d'audit et signatures

- La **piste d'audit** réunit le journal système, l'historique de chaque query et le registre des corrections :
  - le journal système couvre les connexions, les paramètres, les imports, les exports, les téléchargements, le gel et le dégel ;
  - l'historique d'une query couvre l'affectation, la réponse, la validation, le rejet et la réouverture.

  Elle est horodatée, attribuée à un utilisateur, filtrable par utilisateur et par période, et exportable en CSV. Chaque fiche ménage présente l'historique complet de sa soumission.
- **Signature électronique** : l'utilisateur ressaisit son mot de passe. Elle s'applique à la validation et à la décision « irrésoluble » d'une query soumise à double validation, ainsi qu'au gel, au dégel et à la copie SQL. Les actions signées sont marquées dans l'historique.

## 9. Gel de la base et versions

- **Gel** : l'administrateur gèle la base avec un motif et une signature. Le gel ouvre la version Vn, enregistre l'état de la base (ménages, exclusions, corrections, queries en suspens) et génère automatiquement le paquet d'archivage de la version. L'empreinte SHA-256 de ce paquet identifie la version.
- **Pendant le gel** : aucune correction ni exclusion n'est possible, et les synchronisations n'écrivent plus dans la base.
- **Dégel** : l'administrateur dégèle avec un motif et une signature. Les changements reçus d'ODK sont repris à la synchronisation suivante, et le gel suivant ouvre la version Vn+1.
- **Préalable au gel final** : zéro query non résolue, zéro réponse à valider, zéro correction proposée ou en conflit, ou bien un écart justifié et approuvé par l'investigateur principal.

## 10. Exports, archivage et conservation

| Export | Contenu | Accès |
|---|---|---|
| Base brute | Soumissions telles que reçues (exclusions signalées) | Administrateur |
| Base nettoyée | Corrections appliquées, doublons exclus, registre joint | Administrateur |
| Paquet d'archivage | Bases brute et nettoyée, registre, queries et historique, observations, dictionnaire | Administrateur |
| Copie SQL | Toutes les tables, ZIP chiffré AES-256, mot de passe non conservé | Administrateur (signature) |
| Registre des corrections (Excel) | Registre filtré | Data manager, investigateur principal |

- **Format des fichiers** : CSV UTF-8. Chaque ZIP contient un manifeste qui donne l'empreinte SHA-256 et le nombre de lignes de chaque fichier, la version du formulaire, la révision logicielle, l'auteur et la date.
- **Traçabilité** : les demandes et les téléchargements sont tracés.
- **Rétention sur la plateforme** : les fichiers sont conservés 7 jours sur la plateforme. Leur trace et leur empreinte restent ensuite.
- **Archivage hors plateforme** : le paquet de chaque version gelée est archivé hors de la plateforme *[à compléter : support, emplacement, durée de conservation réglementaire, responsable]*.

## 11. Sécurité et confidentialité

- **Comptes et périmètre** : comptes nominatifs, rôles à droits minimaux (section 4) ; les superviseurs voient leur zone, les binômes les données de leurs agents.
- **Protection des fichiers sensibles** : la copie SQL est chiffrée en AES-256. Le mot de passe de chiffrement n'est jamais stocké et doit être transmis par un autre canal que le fichier.
- **Décisions confidentielles** : les décisions marquées confidentielles ne sont visibles que des administrateurs.
- **Infrastructure** *[à compléter selon l'hébergement]* : HTTPS, sauvegardes du serveur, politique de mots de passe, revue périodique des comptes.

## 12. Validation du système informatique

- **Tests automatisés** : environ 160 tests couvrent le circuit des queries, les corrections, la base nettoyée, les exports, le gel et les droits d'accès. Ils sont exécutés à chaque modification (intégration continue GitHub Actions, PHP 8.2 et 8.3).
- **Déploiement** : le déploiement est automatique et traçable. Chaque version porte sa révision (empreinte du commit), avec retour arrière automatique en cas d'échec du contrôle de santé (procédure dans la documentation « Déploiement »).
- **Recette avant la première utilisation en production** : scénarios de la SOP-DM-01 joués sur des données de test et procès-verbal signé *[à compléter]*.

## 13. Indicateurs de suivi

- Queries non résolues, réponses à valider et queries en retard sur leur échéance.
- Délai moyen de résolution.
- Taux de queries par binôme et par règle.
- Nombre de corrections validées, rejetées et en conflit.
- Observations de supervision par type.

Les tableaux de bord de la plateforme les présentent ; ils sont revus *[à compléter : fréquence, instance]*.

## Annexe B – Correspondance ALCOA+

| Principe | Mise en œuvre |
|---|---|
| Attribuable | Comptes nominatifs ; auteur de chaque réponse, validation, correction et export. |
| Lisible | Registre, historique et exports en clair (CSV/Excel) ; libellés du dictionnaire. |
| Contemporain | Horodatage automatique de chaque action. |
| Original | Source ODK intacte ; base brute et réponse ODK brute conservées. |
| Exact | Contrôle des valeurs saisies selon le dictionnaire ; vérification par le moteur après correction. |
| Complet | Rien n'est supprimé : corrections remplacées, rejetées ou en conflit restent au registre ; doublons exclus et non effacés. |
| Cohérent | Même notation des membres (chemin) dans les queries, le registre et les exports ; ordre de validation reproductible. |
| Durable | Versions gelées archivées, identifiées par empreinte SHA-256 ; copie SQL chiffrée. |
| Disponible | Exports à la demande ; piste d'audit consultable et exportable. |
