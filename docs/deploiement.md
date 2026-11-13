# Intégration et déploiement continus (CI/CD)

Chaque push sur `main` est testé puis, si tous les tests passent, publié et installé automatiquement sur le
serveur de production (hébergement cPanel LWS, `https://gavi-coverage2.airid-africa.com`). Une pull request
vers `main` lance uniquement les tests. Rien n'est publié si un test échoue.

L'hébergement mutualisé n'accepte pas de connexion SSH depuis l'extérieur : GitHub ne pousse donc rien vers le
serveur. Il **publie** la release, et c'est le serveur qui vient la **chercher** (déploiement « pull »).

```
GitHub Actions                                              Serveur cPanel (cron toutes les 2 min)
push main ─► Tests PHP 8.2 + 8.3 ─► Construction ─► GitHub Release « deploy-… »
             Tests MySQL 8 (informatif)                              │
                                                  pull-deploy.sh ◄───┘ nouvelle release ?
                                                     ├─ téléchargement de release.tar.gz
                                                     ├─ deploy.sh : migrations, bascule de current
                                                     └─ contrôle APP_URL/up ─ échec : retour à la release précédente
```

Le workflow est dans [.github/workflows/ci-cd.yml](../.github/workflows/ci-cd.yml) et les scripts serveur
dans [deploy/](../deploy/).

## Ce que fait chaque étape

| Étape | Détail |
|---|---|
| **Tests** | `composer install`, `npm ci && npm run build` (les vues utilisent `@vite`), puis `php artisan test` sur PHP 8.2 et 8.3, base SQLite en mémoire. Le test du XLSForm est ignoré car `cov26c4v3.xls` n'est pas versionné. |
| **Tests MySQL 8** | Les mêmes tests sur MySQL 8. Informatif : un échec ne bloque pas le déploiement, mais doit être corrigé. |
| **Construction** | `composer install --no-dev --optimize-autoloader` et `npm run build`, puis une archive `release.tar.gz` sans `.git`, `node_modules`, `tests`, `storage` ni `.env`. Le serveur n'a besoin ni de Composer ni de Node. |
| **Publication** | L'archive est publiée en GitHub Release `deploy-<date>-<commit>`. Les 10 dernières sont conservées. |
| **Installation (serveur)** | [deploy/pull-deploy.sh](../deploy/pull-deploy.sh), lancé par cron, détecte la nouvelle release, la télécharge et lance [deploy/deploy.sh](../deploy/deploy.sh) : extraction dans `releases/<date>-<commit>`, liens vers `shared/.env` et `shared/storage`, `migrate --force`, `optimize`, bascule atomique du lien `current`, `queue:restart`. Puis `APP_URL/up` doit répondre 200, sinon retour à la release précédente. Journal : `shared/storage/logs/deploy.log`. |

Le service n'est pas interrompu : la nouvelle release est préparée à côté de l'ancienne, et le site bascule
d'un coup quand elle est prête. Une release qui échoue n'est pas retentée : corrigez puis poussez un nouveau commit.

> ⚠️ Les migrations sont lancées **avant** la bascule et ne sont pas annulées par un retour arrière.
> Écrivez des migrations compatibles avec la version précédente (ajouter une colonne plutôt que la
> renommer ou la supprimer en une seule fois).

## Arborescence sur le serveur

```
/home/cp2164318p43/gavi-coverage2/
├── current -> releases/20261113143000-a1b2c3d   racine web du sous-domaine : current/public
├── releases/                                    une release par déploiement (5 conservées)
├── shared/
│   ├── .env                                     configuration de production
│   ├── deploy.conf                              dépôt GitHub, binaire PHP (pull-deploy.sh)
│   └── storage/                                 logs (dont deploy.log), sessions, imports
│       └── app/public/settings/                 XLSForm, CSV de référence
├── uploads/                                     archives en cours de téléchargement
├── pull-deploy.sh, deploy.sh, rollback.sh       mis à jour à chaque déploiement
└── .last_release                                dernière release traitée
```

## 1. Préparer le serveur (une seule fois)

Prérequis cPanel : PHP 8.2 (CLI : `/usr/local/bin/php`) avec les extensions `intl gd zip bcmath pdo_mysql
mbstring exif fileinfo`, une base MySQL. Tout se fait depuis le **Terminal** et le **Gestionnaire de fichiers**
de cPanel.

1. **Arborescence** : charger [deploy/setup-server.sh](../deploy/setup-server.sh) dans le dossier personnel
   (pas dans `public_html`), puis `bash ~/setup-server.sh ~/gavi-coverage2`. Il crée `releases/`, `shared/`,
   `uploads/`, un `shared/.env` avec une `APP_KEY` neuve et `shared/deploy.conf`.
2. **Base de données** : cPanel › Assistant Base de données MySQL (base, utilisateur, tous les privilèges).
3. **`shared/.env`** : `APP_URL`, `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
   `ADMIN_EMAIL`, `ADMIN_PASSWORD`. Si la base est importée depuis une autre installation, reprendre son
   `APP_KEY` : elle chiffre les identifiants ODK/Kobo enregistrés en base.
4. **Sous-domaine** : racine du document `gavi-coverage2/current/public`. cPanel crée alors de vrais dossiers
   `current/public` : les supprimer (`rm -rf ~/gavi-coverage2/current`), sinon la bascule échoue.
   Puis SSL/TLS Status › Run AutoSSL (le `.env` impose des cookies sécurisés : HTTPS obligatoire).
5. **Fichiers du formulaire** : XLSForm et CSV dans `shared/storage/app/public/settings`.
6. **`shared/deploy.conf`** (créé par l'étape 1, à vérifier) :
   ```
   GITHUB_REPO=FrancoDebord/gavi-siri-optmvac-coverage-survey-2026
   PHP_BIN=/usr/local/bin/php
   KEEP_RELEASES=5
   GITHUB_TOKEN=
   ```
   `GITHUB_TOKEN` est facultatif tant que le dépôt est public. Il devient nécessaire si le dépôt passe en
   privé, ou si `deploy.log` signale la limite d'appels de l'API atteinte (l'IP du serveur mutualisé est
   partagée). Jeton à créer dans GitHub › Settings › Developer settings › Fine-grained tokens, limité à ce
   dépôt, permission **Contents : Read-only**.
7. **Script de déploiement** (une fois ; ensuite il se met à jour seul) :
   ```bash
   curl -fsSL https://raw.githubusercontent.com/FrancoDebord/gavi-siri-optmvac-coverage-survey-2026/main/deploy/pull-deploy.sh -o ~/gavi-coverage2/pull-deploy.sh
   ```
8. **Tâche cron** (cPanel › Tâches Cron, « Toutes les 2 minutes », `*/2 * * * *`) :
   ```
   bash /home/cp2164318p43/gavi-coverage2/pull-deploy.sh /home/cp2164318p43/gavi-coverage2 >/dev/null 2>&1
   ```

Après le premier déploiement réussi, ajouter deux tâches cron « Une fois par minute » :

```
cd /home/cp2164318p43/gavi-coverage2/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
cd /home/cp2164318p43/gavi-coverage2/current && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

Si la base est neuve (non importée), une seule fois :
`php artisan db:seed --force && php artisan gavi:load-dictionary` dans `current`.

## 2. GitHub

Aucun secret à créer. Seul réglage : **Settings › Actions › General › Workflow permissions** doit laisser le
workflow écrire (« Read and write permissions »), ou au minimum ne pas bloquer la permission `contents: write`
demandée par le job de publication.

Variable facultative (**Settings › Secrets and variables › Actions › Variables**) : `APP_URL`, affichée dans le
résumé du workflow.

Conseillé : **Settings › Branches**, protéger `main` et exiger que « Tests PHP 8.2 » passe avant la fusion.

## 3. Utilisation au quotidien

- Déployer : `git push origin main`. Les tests et la publication se suivent dans l'onglet **Actions** ; le
  serveur installe la release dans les 2 minutes qui suivent.
- Suivre l'installation (Terminal cPanel) : `tail -n 30 ~/gavi-coverage2/shared/storage/logs/deploy.log`.
- Voir la version en ligne : `cat ~/gavi-coverage2/current/REVISION` (hash du commit).
- Republier sans nouveau commit : **Actions › CI/CD › Run workflow**.
- Forcer la réinstallation de la dernière release : `rm ~/gavi-coverage2/.last_release`.
- Revenir à la release précédente :
  ```bash
  PHP_BIN=/usr/local/bin/php bash ~/gavi-coverage2/rollback.sh ~/gavi-coverage2
  # ou vers une release précise :
  PHP_BIN=/usr/local/bin/php bash ~/gavi-coverage2/rollback.sh ~/gavi-coverage2 20261113143000-a1b2c3d
  ```
  La release suivante publiée sur GitHub sera installée normalement.
- Journaux de l'application : `shared/storage/logs/laravel-*.log`.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Rien dans `deploy.log` | Tâche cron absente ou chemin erroné ; lancer la commande du cron à la main dans le Terminal pour voir l'erreur. |
| `API GitHub injoignable ou limite d'appels atteinte` | Renseigner `GITHUB_TOKEN` dans `shared/deploy.conf`. |
| Job « Publier la release » en échec (`403`) | Settings › Actions › General › Workflow permissions : autoriser l'écriture. |
| `shared/.env absent` | `setup-server.sh` non lancé, ou chemin de déploiement différent. |
| `mv: cannot overwrite directory` | Le dossier `current` créé par cPanel existe encore : `rm -rf ~/gavi-coverage2/current`, puis `rm ~/gavi-coverage2/.last_release`. |
| `/up répond 000` puis retour arrière | HTTPS pas encore actif (AutoSSL) ou sous-domaine qui ne pointe pas sur `current/public`. |
| `/up répond 500` puis retour arrière | Consulter `laravel-*.log` : souvent une variable `.env`, une extension PHP manquante ou un droit d'écriture sur `shared/storage`. |
| `Un autre déploiement est en cours` | Un déploiement précédent a été interrompu : vérifier qu'aucun ne tourne, puis `rm ~/gavi-coverage2/.last_release`. |
