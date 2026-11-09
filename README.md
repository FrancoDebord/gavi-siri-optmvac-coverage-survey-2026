# Suivi en temps réel – Enquête de couverture vaccinale GAVI SIRI, round 2

Plateforme Laravel 12 de suivi de la collecte. Elle récupère les soumissions ODK Central ou
KoboToolbox par API, ou par import de fichiers. Elle calcule la progression (ménages, caregivers,
enfants) et détecte automatiquement les données erronées (queries) à partir du data dictionary
(XLSForm) et de règles métier sur la vaccination. Toutes les vues s'agrègent selon la hiérarchie
**zone sanitaire › commune › village › grappe**, avec en plus le binôme et la date de collecte.

- **Backend** : Laravel 12, PHP 8.2 ou plus, MySQL 8 ou PostgreSQL (SQLite en développement)
- **Frontend** : Blade, Bootstrap 5.3, Bootstrap Icons, Chart.js 4, DataTables (bs5), Leaflet, Vite. Pas de Tailwind.
- **Packages** : Fortify (auth headless, vues Blade), spatie/laravel-permission, maatwebsite/excel, barryvdh/laravel-dompdf

## Installation

```bash
git clone <dépôt> gavi-r2 && cd gavi-r2
composer install
cp .env.example .env && php artisan key:generate
# Renseigner DB_*, puis :
php artisan migrate --seed          # rôles, règles, dictionnaire par défaut, référentiel, compte admin
npm install && npm run build
php artisan db:seed --class=DemoDataSeeder   # facultatif : ~400 soumissions fictives et comptes de démonstration
```

Le seeder par défaut crée le compte `ADMIN_EMAIL` / `ADMIN_PASSWORD`, par défaut
`admin@gavi.local` / `ChangeMoi!2026`. **Changez ce mot de passe dès la première connexion.**
Le seeder de démonstration ajoute aussi `datamanager@`, `superviseur@` (zone Tchaourou),
`superviseur.commune@` et `lecteur@gavi.local`, tous avec le mot de passe `Demo!2026`.

### Fichiers du formulaire

Le XLSForm `cov26c4v3.xls` et les CSV qu'il utilise (`l1.csv`, `l2.csv`, `l3.csv`, `village.csv`,
`l1_fieldworkers.csv`, `vaccines.csv`…) sont lus dans `GAVI_SETTINGS_PATH`, par défaut
`storage/app/public/settings`.

> ⚠️ Ce dossier se trouve sous le disque `public`. Si vous lancez `php artisan storage:link`, la liste
> des enquêteurs devient accessible publiquement. En production, déplacez ces fichiers vers
> `storage/settings` et définissez `GAVI_SETTINGS_PATH=storage/settings` (chemin absolu conseillé).

### Variables `.env` propres à l'application

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_TIMEZONE` | `Africa/Porto-Novo` | Fuseau des dates affichées et des contrôles horaires |
| `APP_LOCALE` | `fr` | Interface en français |
| `GAVI_SETTINGS_PATH` | `storage/app/public/settings` | Dossier du XLSForm et des CSV |
| `GAVI_DICTIONARY_FILE` | `cov26c4v3.xls` | XLSForm chargé par défaut |
| `GAVI_COUNTRY` | `bj` | Pays retenu dans les CSV de référence |
| `GAVI_DASHBOARD_REFRESH` | `60` | Rafraîchissement automatique des dashboards (secondes, 0 = désactivé) |
| `GAVI_DASHBOARD_CACHE` | `30` | Durée du cache des agrégations (secondes) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | — | Compte administrateur créé par le seeder |
| `QUEUE_CONNECTION` | `database` | `database` ou `redis` |

Les identifiants des sources ODK ou Kobo ne sont **pas** dans `.env` : ils se saisissent dans
l'interface et sont chiffrés en base avec `APP_KEY`. Si vous changez `APP_KEY`, il faudra les
ressaisir.

## Queue et scheduler

Les synchronisations, les imports et le moteur de queries tournent dans des jobs de queue.

```bash
php artisan queue:work --tries=1 --timeout=3600
```

En production, lancez ce worker sous Supervisor ou systemd. Le scheduler est déclaré dans
`routes/console.php` (Laravel 12, sans `Kernel.php`) :

| Tâche | Fréquence |
|---|---|
| `DispatchDueSyncsJob` : met en file la synchro de chaque source active dont l'intervalle est écoulé | chaque minute |
| `RunQualityChecksJob` : passage complet du moteur de queries | tous les jours à 02:00 |
| `queue:prune-failed` | tous les jours |

Entrée cron à ajouter :

```cron
* * * * * cd /chemin/vers/gavi-r2 && php artisan schedule:run >> /dev/null 2>&1
```

En développement, `composer dev` lance le serveur, le worker, les logs et Vite.

## Commandes artisan

| Commande | Rôle |
|---|---|
| `php artisan gavi:load-dictionary [chemin] [--no-references]` | Charge le XLSForm (par défaut `cov26c4v3.xls`), initialise le mapping des variables et recharge le référentiel (zones, communes, arrondissements, villages, enquêteurs) |
| `php artisan gavi:sync [source] [--full] [--no-checks]` | Synchronise une source, ou toutes les sources actives, puis relance le moteur de queries |
| `php artisan gavi:run-quality-checks` | Exécute toutes les règles actives et met à jour les queries |

## Modules

1. **Sources de données** (Paramètres › Sources) : CRUD des sources ODK Central (session `POST /v1/sessions`, OData `$expand=*`, pagination `$top`/`$skip`, filtre incrémental sur `submissionDate` et `updatedAt`) et KoboToolbox (`/api/v2/assets/{uid}/data.json`, `limit`/`start`, `query` sur `_submission_time`). L'écran propose « Tester la connexion », « Synchroniser maintenant », la synchro complète, la récupération du dictionnaire par API et un journal des synchros. Les appels API sont limités en débit et relancés en cas d'erreur réseau ou 5xx. Le jeton ODK est renouvelé automatiquement.
2. **Data dictionary** : parser XLSForm (`survey`, `choices`, `settings`) et contenu d'asset Kobo. Les dictionnaires sont versionnés et chaque soumission est rattachée à sa version. La page de consultation permet de chercher par variable, type et repeat. Le **mapping** associe les champs métier aux variables ; il est pré-rempli depuis `cov26c4v3.xls` (voir ci-dessous).
3. **Imports** : export CSV ODK (fichier principal et un CSV par repeat, liés par `KEY`/`PARENT_KEY`) ou XLSX Kobo (`_index`/`_parent_index`), traités dans un job de queue avec barre de progression. L'upsert est idempotent sur l'UUID (`instanceID`/`_uuid`) et une soumission inchangée n'est pas réécrite. La page importe aussi la liste des grappes avec leurs cibles (modèle CSV pré-rempli téléchargeable).
4. **Dashboard de progression** : filtres en cascade mémorisés par page. KPI, évolution journalière et cumulée, barres empilées par zone et par commune, productivité par binôme, tableau hiérarchique dépliable avec % réalisé et queries ouvertes, carte Leaflet colorée par grappe ou par binôme. Rafraîchissement automatique, export Excel et PDF.
5. **Queries** : moteur de règles, dashboard (KPI, sévérité, statut, top 10 des règles, variables, classements par zone, commune, village, grappe et binôme, heatmap binôme × jour), liste DataTables côté serveur avec action rapide unitaire ou groupée, fiche avec suggestion de résolution, historique et commentaires. Exports : un export Excel ou CSV selon des critères (regroupement possible par binôme, zone, commune, village, grappe, règle ou statut ; voir plus bas), et un rapport PDF quotidien par zone sanitaire.
6. **Rôles** : Administrateur (tout) ; Data manager (imports, dictionnaire, règles, queries) ; Superviseur (limité à sa zone ou sa commune, traite les queries) ; Lecteur (dashboards). Les filtres ne permettent jamais de sortir de son périmètre. Les actions sensibles sont tracées dans un journal d'audit (connexions, sources, synchros, imports, exports, règles, mapping, utilisateurs).

Cycle de vie d'une query : `Ouverte → Envoyée au binôme → Justifiée / Corrigée → Validée (fermée)`,
ou `Irrésoluble` depuis « Ouverte » ou « Envoyée » quand la donnée ne peut être ni corrigée ni confirmée
(ménage injoignable, carnet perdu…). « Justifiée » et « Irrésoluble » exigent un commentaire. Une query
est fermée automatiquement quand l'anomalie n'est plus détectée, et rouverte si elle réapparaît.

### Fiche d'une query et export

Chaque query porte :

| Champ | Contenu |
|---|---|
| Code | `Q-000123`, dérivé de l'identifiant : stable, et utilisable dans la recherche et le filtre « Code query » |
| Libellé | Nom de la règle + libellé de la question concernée (ex. « Ordre des doses non respecté – 88.5 Date de Penta 2 ») |
| Description de l'erreur | Message détaillé avec les valeurs en cause |
| Suggestion de résolution | Gabarit défini par règle (modifiable dans Paramètres › Règles qualité) complété avec la question, la valeur observée et la valeur attendue |
| Variable, question, valeurs | Nom XLSForm, libellé de la question, valeur observée et valeur attendue |
| Binôme, ménage | Binôme ayant collecté, ID du ménage, UUID de la soumission, membre concerné (ex. « Caregiver 1 › Enfant 2 ») |
| Localisation | Zone sanitaire, commune, village, grappe |
| Statut de résolution | **Non résolu** (ouverte, envoyée), **Résolu** (justifiée, corrigée, validée) ou **Irrésoluble**, plus le statut détaillé |
| Dates | Date de collecte, date de génération (détection) et date de résolution (passage à résolu ou irrésoluble) |

**Export** (Liste des queries › Exporter, ou `GET /queries/export`) : il reprend tous les filtres de la
liste, à savoir zone, commune, village, grappe, binôme, période de collecte, période de génération,
statut de résolution, statut détaillé, sévérité, règle, variable et code. Formats :
- **Excel** : une feuille « Critères » qui rappelle les filtres, puis soit une feuille unique, soit un
  récapitulatif suivi d'une feuille par groupe. Regroupements possibles : binôme, zone sanitaire,
  commune, village, grappe, règle, statut de résolution.
- **CSV** : séparateur `;` et BOM UTF-8, s'ouvre directement dans Excel en français.

Les superviseurs n'exportent que leur périmètre. Chaque export est tracé dans le journal d'audit.
Paramètres de l'URL : `format=xlsx|csv`, `group_by=collector|health_zone|commune|village|cluster|rule|resolution`,
et les mêmes paramètres de filtre que la liste (`resolution=non_resolu|resolu|irresoluble`,
`generated_from`, `generated_to`, `variable`, `code`…).

Voir [docs/regles-qualite.md](docs/regles-qualite.md) pour la liste complète des règles, leurs
sévérités, leurs seuils et leurs suggestions de résolution.

### Variables clés du formulaire (mapping par défaut)

| Champ métier | Variable | Remarque |
|---|---|---|
| Zone sanitaire / commune / arrondissement | `l1` / `l2` / `l3` | `country_meta.csv` : pas de niveau l4 au Bénin |
| Village | `village` | |
| Grappe | `cluster_number` | Masqué dans le formulaire (`relevant 1=2`) : **la grappe vaut le village** si la variable est vide |
| Binôme | `fieldworker` | Liste `l1_fieldworkers.csv` |
| ID ménage, visite | `household`, `visit_num` | |
| Consentement | `household_survey_status` = `0` | Refus = `1`, absent = `2,4`, pas d'enfant = `3` |
| Caregivers / enfants | repeats `cg` › `smc_cov_hh_cg_child_2023` | Participation `participation_agreed` = `1` |
| Âge | `child_dob`, `child_dob_reported`, `child_years`/`child_months`, `age_months_est` | Tranche `c_low`–`c_high` (12–35 mois au Bénin) |
| Vaccins | `bcg`, `hepb`, `opv0-3`, `ipv1-2`, `Penta1-3`, `pcv1-3`, `rota1-3`, `mal1-4`, `meas1-2`, `MenA`, `YF` | Motif `X`, `Xdat_known`, `Xdate`, `XInvalid` |
| GPS, paradonnées | `current_location`, `start`, `end`, `today` | |

## Architecture

```
app/Services/Connectors   DataSourceConnector, OdkCentralConnector, KoboConnector, ConnectorFactory
app/Services/Dictionary   XlsFormParser, DictionaryLoader, Dictionary (vue mémoire), VariableMap (mapping)
app/Services/Import       SubmissionNormalizer, TabularSubmissionReader, SubmissionProcessor, SyncService,
                          ClusterListImporter, ReferenceDataLoader
app/Services/Quality      QualityEngine, QueryWorkflow, RuleCatalog, SubmissionContext/Scope,
                          Expression/ (parser + évaluateur XPath), Rules/Dictionary/*, Rules/Business/*
                          (une classe par règle, QualityRuleInterface)
app/Services/Dashboard    ProgressStats, QueryStats (agrégations groupées, cache court invalidé par version)
app/Jobs                  SyncDataSourceJob, DispatchDueSyncsJob, ImportSubmissionsJob, RunQualityChecksJob
resources/views/components  kpi-card, filter-bar, chart-card, progress-table, layouts…
resources/js/pages        un module JS par écran (progress, queries-dashboard, queries-list…)
```

Les soumissions de toutes les sources sont normalisées dans un format interne commun
(`NormalizedSubmission` : variables à plat et repeats imbriqués). Les données brutes sont conservées
dans `households.raw_data`. Les caregivers et les enfants ont leurs propres tables ; les autres
repeats (roster, moustiquaires, SES) restent attachés au ménage et sont eux aussi contrôlés.

## Tests

```bash
php artisan test
```

Unit : parser XLSForm (sur le vrai `cov26c4v3.xls`), évaluateur d'expressions, règles de
vaccination. Feature : connecteurs ODK et Kobo (`Http::fake()`), import CSV ODK idempotent, moteur
(ouverture, déduplication, fermeture et réouverture automatiques), cycle de vie des queries,
périmètres par rôle, pages, endpoints JSON et exports.
#   g a v i - s i r i - o p t m v a c - c o v e r a g e - s u r v e y - 2 0 2 6  
 