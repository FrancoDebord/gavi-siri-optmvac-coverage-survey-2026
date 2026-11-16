#!/usr/bin/env bash
# Active une release envoyée par GitHub Actions, sans interruption de service.
#
#   bash deploy.sh <DEPLOY_PATH> <RELEASE>
#
# Arborescence sur le serveur :
#   <DEPLOY_PATH>/
#     current  -> releases/<RELEASE>   (racine web : current/public)
#     releases/<RELEASE>/              une release par déploiement, les plus anciennes sont supprimées
#     shared/.env                      configuration de production (jamais dans Git)
#     shared/storage/                  logs, sessions, fichiers importés, settings du formulaire
#     uploads/<RELEASE>.tar.gz         archive reçue, supprimée après extraction
#
# Variables facultatives : PHP_BIN (php), PHP_FPM_SERVICE (ex. php8.2-fpm), KEEP_RELEASES (5).
set -Eeuo pipefail

DEPLOY_PATH="${1:?chemin de déploiement manquant}"
RELEASE="${2:?nom de release manquant}"
PHP_BIN="${PHP_BIN:-php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

RELEASES="$DEPLOY_PATH/releases"
SHARED="$DEPLOY_PATH/shared"
ARCHIVE="$DEPLOY_PATH/uploads/$RELEASE.tar.gz"
TARGET="$RELEASES/$RELEASE"

log() { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[deploy]\033[0m %s\n' "$*" >&2; exit 1; }

# Si une étape échoue avant la bascule, la release incomplète est supprimée : le site reste sur l'ancienne.
SWITCHED=0
cleanup_on_error() {
  local code=$?
  if [ "$SWITCHED" -eq 0 ] && [ -d "$TARGET" ]; then
    log "Échec avant la bascule : suppression de $TARGET, la release en ligne est inchangée."
    rm -rf "$TARGET"
  fi
  exit "$code"
}
trap cleanup_on_error ERR

[ -f "$ARCHIVE" ] || fail "Archive introuvable : $ARCHIVE"
[ -f "$SHARED/.env" ] || fail "$SHARED/.env absent : lancez d'abord deploy/setup-server.sh puis renseignez-le."

# Un seul déploiement à la fois sur le serveur.
exec 9>"$DEPLOY_PATH/.deploy.lock"
flock -n 9 || fail "Un autre déploiement est en cours."

log "Release $RELEASE"
mkdir -p "$TARGET"
tar -xzf "$ARCHIVE" -C "$TARGET"
rm -f "$ARCHIVE"

# Fichiers partagés entre releases.
mkdir -p "$SHARED/storage"/{app/public,app/private,framework/cache/data,framework/sessions,framework/views,framework/testing,logs}
rm -rf "$TARGET/storage"
ln -sfn "$SHARED/storage" "$TARGET/storage"
ln -sfn "$SHARED/.env" "$TARGET/.env"
mkdir -p "$TARGET/bootstrap/cache"

# Réglages propres à l'hébergeur (cPanel : version PHP, MultiPHP INI) conservés dans shared/public/ :
# le .htaccess est placé avant celui de Laravel, les autres fichiers (.user.ini, php.ini) sont copiés tels quels.
if [ -d "$SHARED/public" ]; then
  if [ -f "$SHARED/public/.htaccess" ]; then
    { cat "$SHARED/public/.htaccess"; echo; cat "$TARGET/public/.htaccess"; } > "$TARGET/public/.htaccess.new"
    mv -f "$TARGET/public/.htaccess.new" "$TARGET/public/.htaccess"
  fi
  for f in .user.ini php.ini; do
    [ -f "$SHARED/public/$f" ] && cp -f "$SHARED/public/$f" "$TARGET/public/$f"
  done
fi

cd "$TARGET"

log "Découverte des packages et caches"
"$PHP_BIN" artisan package:discover --ansi
# Pas d'optimize:clear : il vide aussi le cache applicatif, stocké en base (CACHE_STORE=database), et
# échoue sur une base neuve dont la table cache n'est pas encore créée. Seuls les caches fichiers sont vidés.
for cache in config route view event; do
  "$PHP_BIN" artisan "$cache:clear" --ansi
done

log "Migrations"
"$PHP_BIN" artisan migrate --force --no-interaction --ansi

"$PHP_BIN" artisan optimize --ansi

# Bascule atomique du lien « current ».
log "Bascule vers la nouvelle release"
ln -sfn "$TARGET" "$DEPLOY_PATH/current.tmp"
mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"
SWITCHED=1

# Opcache : recharge PHP-FPM si le service est indiqué (sudo sans mot de passe requis, voir docs/deploiement.md).
if [ -n "${PHP_FPM_SERVICE:-}" ]; then
  log "Rechargement de $PHP_FPM_SERVICE"
  sudo -n systemctl reload "$PHP_FPM_SERVICE" || log "Avertissement : rechargement de $PHP_FPM_SERVICE impossible."
fi

# Les workers de queue (Supervisor) terminent leur job en cours puis redémarrent sur le nouveau code.
"$PHP_BIN" artisan queue:restart --ansi

# Nettoyage des anciennes releases.
log "Conservation des $KEEP_RELEASES dernières releases"
CURRENT_PATH="$(readlink -f "$DEPLOY_PATH/current")"
ls -1 "$RELEASES" | sort -r | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
  if [ "$RELEASES/$old" != "$CURRENT_PATH" ]; then rm -rf -- "${RELEASES:?}/$old"; fi
done

log "Déploiement terminé : $(cat "$TARGET/REVISION" 2>/dev/null || echo "$RELEASE")"
