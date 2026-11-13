#!/usr/bin/env bash
# Préparation initiale du serveur (une seule fois), à lancer avec l'utilisateur de déploiement :
#
#   bash setup-server.sh /var/www/gavi-r2
#
# Crée l'arborescence releases/ shared/ uploads/, un shared/.env à compléter, et affiche
# les étapes restantes (base de données, Nginx, Supervisor, cron). Ne touche pas à un .env existant.
set -Eeuo pipefail

DEPLOY_PATH="${1:?usage : bash setup-server.sh <DEPLOY_PATH>}"
SHARED="$DEPLOY_PATH/shared"

mkdir -p "$DEPLOY_PATH"/{releases,uploads}
mkdir -p "$SHARED/storage"/{app/public/settings/points_gps,app/private,framework/cache/data,framework/sessions,framework/views,framework/testing,logs}
# PHP-FPM (www-data) et l'utilisateur de déploiement doivent tous deux écrire dans storage.
WEB_GROUP="${WEB_GROUP:-www-data}"
chgrp -R "$WEB_GROUP" "$SHARED" 2>/dev/null || echo "→ Avertissement : chgrp $WEB_GROUP impossible (ajoutez cet utilisateur au groupe $WEB_GROUP)."
chmod -R ug+rwX "$SHARED/storage"
find "$SHARED/storage" -type d -exec chmod g+s {} +

if [ ! -f "$SHARED/.env" ]; then
  KEY="base64:$(head -c 32 /dev/urandom | base64)"
  cat > "$SHARED/.env" <<EOF
APP_NAME="GAVI SIRI R2"
APP_ENV=production
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=https://gavi.example.org

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR
APP_TIMEZONE=Africa/Porto-Novo
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gavi_r2
DB_USERNAME=gavi
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@gavi.example.org"
MAIL_FROM_NAME="\${APP_NAME}"

GAVI_SETTINGS_PATH=$SHARED/storage/app/public/settings
GAVI_DICTIONARY_FILE=cov26c4v3.xls
GAVI_COUNTRY=bj
GAVI_DASHBOARD_REFRESH=60
GAVI_DASHBOARD_CACHE=30
ADMIN_EMAIL=admin@gavi.local
ADMIN_PASSWORD=
EOF
  chmod 640 "$SHARED/.env"
  chgrp "$WEB_GROUP" "$SHARED/.env" 2>/dev/null || true
  echo "→ $SHARED/.env créé avec une APP_KEY neuve. Complétez APP_URL, DB_* et ADMIN_PASSWORD."
  echo "  Conservez cette APP_KEY : elle chiffre les identifiants ODK/Kobo enregistrés en base."
else
  echo "→ $SHARED/.env existe déjà : inchangé."
fi

# Configuration du déploiement « pull » (deploy/pull-deploy.sh, lancé par cron).
if [ ! -f "$SHARED/deploy.conf" ]; then
  cat > "$SHARED/deploy.conf" <<EOF
GITHUB_REPO=FrancoDebord/gavi-siri-optmvac-coverage-survey-2026
PHP_BIN=$(command -v php || echo php)
KEEP_RELEASES=5
# Facultatif : jeton GitHub en lecture seule (dépôt privé, ou limite d'appels de l'API atteinte).
GITHUB_TOKEN=
EOF
  chmod 600 "$SHARED/deploy.conf"
  echo "→ $SHARED/deploy.conf créé."
fi

cat <<EOF

Étapes restantes (voir docs/deploiement.md) :
  1. Créer la base MySQL et l'utilisateur indiqués dans $SHARED/.env.
  2. Copier le XLSForm et les CSV dans $SHARED/storage/app/public/settings
     (cov26c4v3.xls, l1.csv, village.csv, l1_fieldworkers.csv, points_gps/…).
  3. Racine web du (sous-)domaine : $DEPLOY_PATH/current/public.
  4. Cron toutes les 2 minutes : bash $DEPLOY_PATH/pull-deploy.sh $DEPLOY_PATH
     (puis, après le premier déploiement, schedule:run et queue:work chaque minute).
  5. Premier déploiement : push sur main. Si la base est neuve, une fois ensuite :
       cd $DEPLOY_PATH/current && php artisan db:seed --force && php artisan gavi:load-dictionary
EOF
