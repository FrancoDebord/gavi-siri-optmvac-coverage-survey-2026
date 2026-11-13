#!/usr/bin/env bash
# Déploiement « pull » pour un hébergement sans SSH entrant (cPanel mutualisé) : lancé par cron toutes les
# 2 minutes, il regarde si GitHub a publié une nouvelle release « deploy-… » (job publish du workflow CI/CD),
# la télécharge, l'active avec deploy.sh puis contrôle /up ; en cas d'échec, retour à la release précédente.
#
#   bash pull-deploy.sh <DEPLOY_PATH>
#
# Configuration : <DEPLOY_PATH>/shared/deploy.conf
#   GITHUB_REPO=proprietaire/depot      (obligatoire)
#   PHP_BIN=/usr/local/bin/php          (facultatif, php par défaut)
#   GITHUB_TOKEN=                       (facultatif : dépôt privé, ou limite d'appels à l'API atteinte)
#   KEEP_RELEASES=5                     (facultatif)
# Journal : <DEPLOY_PATH>/shared/storage/logs/deploy.log
set -Eeuo pipefail

DEPLOY_PATH="${1:?usage : bash pull-deploy.sh <DEPLOY_PATH>}"
CONF="$DEPLOY_PATH/shared/deploy.conf"
LOG="$DEPLOY_PATH/shared/storage/logs/deploy.log"
STATE="$DEPLOY_PATH/.last_release"   # dernier tag traité (réussi ou non) : il n'est pas retenté en boucle

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG"; }

[ -f "$CONF" ] || { echo "$CONF absent" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
[ -n "${GITHUB_REPO:-}" ] || { log "GITHUB_REPO manquant dans $CONF"; exit 1; }
PHP_BIN="${PHP_BIN:-php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

# Un seul passage à la fois (un déploiement peut durer plus que l'intervalle du cron).
exec 8>"$DEPLOY_PATH/.pull.lock"
flock -n 8 || exit 0

AUTH=()
[ -n "${GITHUB_TOKEN:-}" ] && AUTH=(-H "Authorization: Bearer $GITHUB_TOKEN")

# Release de déploiement la plus récente (l'API les renvoie de la plus récente à la plus ancienne).
JSON="$(curl -fsSL --max-time 30 ${AUTH[@]+"${AUTH[@]}"} -H 'Accept: application/vnd.github+json' \
  "https://api.github.com/repos/$GITHUB_REPO/releases?per_page=10")" \
  || { log "API GitHub injoignable ou limite d'appels atteinte (renseignez GITHUB_TOKEN dans deploy.conf)."; exit 1; }

LATEST="$(printf '%s' "$JSON" | "$PHP_BIN" -r '
  foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $r) {
      if (!empty($r["draft"]) || strpos($r["tag_name"] ?? "", "deploy-") !== 0) continue;
      foreach ($r["assets"] ?? [] as $a) {
          if ($a["name"] === "release.tar.gz") { echo $r["tag_name"], " ", $a["url"]; exit; }
      }
  }')"
[ -n "$LATEST" ] || exit 0
TAG="${LATEST%% *}"
ASSET_URL="${LATEST#* }"
[ "$TAG" = "$(cat "$STATE" 2>/dev/null || true)" ] && exit 0

RELEASE="${TAG#deploy-}"
ARCHIVE="$DEPLOY_PATH/uploads/$RELEASE.tar.gz"
log "Nouvelle release $TAG : téléchargement"
mkdir -p "$DEPLOY_PATH/uploads"
curl -fsSL --max-time 600 ${AUTH[@]+"${AUTH[@]}"} -H 'Accept: application/octet-stream' -o "$ARCHIVE" "$ASSET_URL" \
  || { log "Téléchargement impossible, nouvel essai au prochain passage."; rm -f "$ARCHIVE"; exit 1; }

# Scripts de déploiement à jour, tirés de la release elle-même (mv : remplacement sans toucher au script en cours).
for script in deploy.sh rollback.sh pull-deploy.sh; do
  if tar -xzf "$ARCHIVE" -O "./deploy/$script" > "$DEPLOY_PATH/$script.new" 2>/dev/null; then
    mv -f "$DEPLOY_PATH/$script.new" "$DEPLOY_PATH/$script"
  else
    rm -f "$DEPLOY_PATH/$script.new"
  fi
done

echo "$TAG" > "$STATE"
if ! PHP_BIN="$PHP_BIN" KEEP_RELEASES="$KEEP_RELEASES" bash "$DEPLOY_PATH/deploy.sh" "$DEPLOY_PATH" "$RELEASE" >> "$LOG" 2>&1; then
  log "ÉCHEC du déploiement de $TAG : le site reste sur la release précédente (détails ci-dessus)."
  exit 1
fi

# Contrôle de santé : APP_URL/up doit répondre 200, sinon retour à la release précédente.
APP_URL="$(grep -E '^APP_URL=' "$DEPLOY_PATH/shared/.env" | tail -n 1 | cut -d= -f2- | tr -d "\"' \r")"
if [ -n "$APP_URL" ]; then
  for i in 1 2 3 4 5 6; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "${APP_URL%/}/up" || true)"
    if [ "$code" = "200" ]; then
      log "Release $TAG en ligne (/up : 200)."
      exit 0
    fi
    sleep 10
  done
  log "ÉCHEC : ${APP_URL%/}/up répond $code après la bascule : retour à la release précédente."
  PHP_BIN="$PHP_BIN" bash "$DEPLOY_PATH/rollback.sh" "$DEPLOY_PATH" >> "$LOG" 2>&1 || log "Aucune release précédente : la release $TAG reste en ligne."
  exit 1
fi
log "Release $TAG en ligne (APP_URL absent : pas de contrôle /up)."
