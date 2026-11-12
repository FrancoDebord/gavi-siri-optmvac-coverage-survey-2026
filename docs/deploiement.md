# Intégration et déploiement continus (CI/CD)

Chaque push sur `main` est testé puis, si tous les tests passent, déployé automatiquement sur le
serveur de production. Une pull request vers `main` lance uniquement les tests. Rien n'est déployé si un
test échoue.

```
push main ──► Tests PHP 8.2 + 8.3 (SQLite) ──► Construction de la release ──► Déploiement SSH ──► Contrôle /up
                Tests MySQL 8 (informatif, non bloquant)                                  └─ échec : retour automatique
                                                                                             à la release précédente
```

Le workflow est dans [.github/workflows/ci-cd.yml](../.github/workflows/ci-cd.yml) et les scripts serveur
dans [deploy/](../deploy/).

## Ce que fait chaque étape

| Étape | Détail |
|---|---|
| **Tests** | `composer install`, `npm ci && npm run build` (les vues utilisent `@vite`), puis `php artisan test` sur PHP 8.2 et 8.3, base SQLite en mémoire comme en local. Le test du XLSForm est ignoré car `cov26c4v3.xls` n'est pas versionné. |
| **Tests MySQL 8** | Les mêmes tests sur MySQL 8, pour repérer une requête SQL propre à SQLite. L'étape est informative : un échec ici ne bloque pas le déploiement, mais doit être corrigé. |
| **Construction** | `composer install --no-dev --optimize-autoloader` et `npm run build`, puis une archive `release.tar.gz` sans `.git`, `node_modules`, `tests`, `storage` ni `.env`. |
| **Déploiement** | Envoi de l'archive par `scp`, puis `deploy/deploy.sh` sur le serveur : extraction dans `releases/<date>-<commit>`, liens vers `shared/.env` et `shared/storage`, `migrate --force`, `optimize`, bascule atomique du lien `current`, rechargement de PHP-FPM (facultatif) et `queue:restart`. Les 5 dernières releases sont conservées. |
| **Contrôle /up** | Le workflow appelle `APP_URL/up` (route de santé de Laravel). Sans réponse 200 après une minute, il revient à la release précédente et le job échoue. |

Le service n'est pas interrompu : la nouvelle release est préparée à côté de l'ancienne, et le site
bascule d'un coup quand elle est prête. Si une étape échoue avant la bascule, la release incomplète est
supprimée et le site reste sur l'ancienne version.

> ⚠️ Les migrations sont lancées **avant** la bascule et ne sont pas annulées par un retour arrière.
> Écrivez des migrations compatibles avec la version précédente (ajouter une colonne plutôt que la
> renommer ou la supprimer en une seule fois).

## Arborescence sur le serveur

```
/var/www/gavi-r2/
├── current -> releases/20261110143000-a1b2c3d   racine web : current/public
├── releases/                                    une release par déploiement
├── shared/
│   ├── .env                                     configuration de production
│   └── storage/                                 logs, sessions, imports, pièces jointes
│       └── app/public/settings/                 XLSForm, CSV de référence, points_gps/
├── uploads/                                     archives en transit
├── deploy.sh, rollback.sh                       recopiés à chaque déploiement
└── .deploy.lock
```

## 1. Préparer le serveur (une seule fois)

Prérequis : Ubuntu 22.04 ou plus récent, PHP 8.2 avec PHP-FPM et les extensions `mbstring intl
pdo_mysql gd zip bcmath exif xml curl`, MySQL 8, Nginx, Supervisor. Composer et Node ne sont **pas**
nécessaires sur le serveur, car la release arrive déjà construite.

```bash
# Utilisateur de déploiement (ou un utilisateur existant)
sudo adduser --disabled-password deploy
sudo usermod -aG www-data deploy
sudo mkdir -p /var/www/gavi-r2 && sudo chown deploy:www-data /var/www/gavi-r2

# En tant que deploy : arborescence et .env de production
sudo -iu deploy
curl -fsSLO https://raw.githubusercontent.com/FrancoDebord/gavi-siri-optmvac-coverage-survey-2026/main/deploy/setup-server.sh
bash setup-server.sh /var/www/gavi-r2
nano /var/www/gavi-r2/shared/.env           # APP_URL, DB_*, ADMIN_PASSWORD
```

Si le dépôt est privé, copiez `deploy/setup-server.sh` sur le serveur avec `scp` au lieu de `curl`.

Puis :

1. **Base de données**
   ```sql
   CREATE DATABASE gavi_r2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'gavi'@'localhost' IDENTIFIED BY 'mot-de-passe-fort';
   GRANT ALL PRIVILEGES ON gavi_r2.* TO 'gavi'@'localhost';
   ```
2. **Fichiers du formulaire** : copiez `cov26c4v3.xls`, les CSV (`l1.csv`, `village.csv`,
   `l1_fieldworkers.csv`…) et `points_gps/` dans `shared/storage/app/public/settings`.
   Le `.env` généré pointe déjà `GAVI_SETTINGS_PATH` vers ce dossier.
3. **Nginx** : modèle [deploy/nginx/gavi-r2.conf](../deploy/nginx/gavi-r2.conf). Il utilise
   `$realpath_root`, qui suit la bascule du lien `current` sans redémarrage. Pour le HTTPS :
   `sudo certbot --nginx -d votre-domaine`.
4. **Worker de queue** : modèle [deploy/supervisor/gavi-r2-worker.conf](../deploy/supervisor/gavi-r2-worker.conf)
   à copier dans `/etc/supervisor/conf.d/`, puis `sudo supervisorctl reread && sudo supervisorctl update`.
5. **Scheduler** (`crontab -e` de l'utilisateur deploy) :
   ```cron
   * * * * * cd /var/www/gavi-r2/current && php artisan schedule:run >> /dev/null 2>&1
   ```
6. **Rechargement de PHP-FPM** (facultatif, vide le cache opcache) : autorisez la commande sans mot de passe.
   ```bash
   echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.2-fpm' | sudo tee /etc/sudoers.d/deploy-fpm
   ```
   Puis définissez la variable GitHub `PHP_FPM_SERVICE=php8.2-fpm`.
7. **Clé SSH de déploiement** (sur votre poste) :
   ```bash
   ssh-keygen -t ed25519 -C "github-actions-gavi" -f gavi_deploy -N ""
   ssh-copy-id -i gavi_deploy.pub deploy@SERVEUR     # ou ajoutez la clé publique à ~deploy/.ssh/authorized_keys
   ssh-keyscan -p 22 SERVEUR                         # sortie à mettre dans le secret SSH_KNOWN_HOSTS
   ```

Après le premier déploiement réussi, une seule fois :

```bash
cd /var/www/gavi-r2/current
php artisan db:seed --force          # rôles, règles qualité, compte administrateur
php artisan gavi:load-dictionary     # XLSForm, mapping et référentiel
```

## 2. Configurer GitHub

Dans **Settings › Environments**, créez l'environnement `production`. Ajoutez-y les éléments
ci-dessous (ou dans **Settings › Secrets and variables › Actions**).

**Secrets**

| Nom | Exemple | Rôle |
|---|---|---|
| `SSH_HOST` | `203.0.113.10` | Adresse du serveur |
| `SSH_PORT` | `22` | Port SSH (facultatif, 22 par défaut) |
| `SSH_USER` | `deploy` | Utilisateur de déploiement |
| `SSH_PRIVATE_KEY` | contenu de `gavi_deploy` | Clé privée, sans phrase de passe |
| `SSH_KNOWN_HOSTS` | sortie de `ssh-keyscan` | Empreinte du serveur, contre l'usurpation (fortement conseillé) |
| `DEPLOY_PATH` | `/var/www/gavi-r2` | Dossier de déploiement |

**Variables**

| Nom | Exemple | Rôle |
|---|---|---|
| `APP_URL` | `https://gavi.example.org` | URL publique, pour le contrôle `/up` après déploiement |
| `PHP_BIN` | `php8.2` | Binaire PHP sur le serveur (facultatif, `php` par défaut) |
| `PHP_FPM_SERVICE` | `php8.2-fpm` | Service à recharger après la bascule (facultatif) |
| `KEEP_RELEASES` | `5` | Nombre de releases conservées (facultatif) |

Conseillé :

- **Settings › Branches** : protégez `main` et exigez que le contrôle « Tests PHP 8.2 » passe avant la
  fusion d'une pull request.
- **Environnement `production` › Required reviewers** : ajoutez-vous si vous voulez valider chaque
  mise en production d'un clic. Sans cette option, le déploiement est immédiat après les tests.

## 3. Utilisation au quotidien

- Déployer : `git push origin main`. Suivez le déroulement dans l'onglet **Actions** du dépôt.
- Relancer un déploiement sans nouveau commit : **Actions › CI/CD › Run workflow**.
- Revenir à la release précédente :
  ```bash
  ssh deploy@SERVEUR 'bash /var/www/gavi-r2/rollback.sh /var/www/gavi-r2'
  # ou vers une release précise :
  ssh deploy@SERVEUR 'bash /var/www/gavi-r2/rollback.sh /var/www/gavi-r2 20261110143000-a1b2c3d'
  ```
- Voir la version en ligne : `cat /var/www/gavi-r2/current/REVISION` (hash du commit).
- Journaux : `shared/storage/logs/laravel-*.log` et `shared/storage/logs/worker.log`.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `shared/.env absent` | `setup-server.sh` n'a pas été lancé, ou `DEPLOY_PATH` est différent du dossier préparé. |
| `Permission denied (publickey)` | Clé publique absente de `authorized_keys`, ou `SSH_USER` incorrect. |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` ne correspond plus au serveur (réinstallation) : relancez `ssh-keyscan`. |
| Erreur 500 après bascule, `/up` en échec | Retour automatique effectué. Consultez `laravel-*.log` ; souvent une variable `.env` manquante ou un droit d'écriture sur `shared/storage`. |
| Les jobs tournent sur l'ancien code | Le worker n'est pas sous Supervisor, ou `queue:restart` ne peut pas écrire dans le cache. |
| `Un autre déploiement est en cours` | Un déploiement précédent a été interrompu : vérifiez qu'aucun ne tourne, puis relancez. |
