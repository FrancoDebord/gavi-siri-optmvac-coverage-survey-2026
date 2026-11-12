#!/usr/bin/env bash
# Revient à la release précédente (ou à celle indiquée).
#
#   bash rollback.sh <DEPLOY_PATH> [RELEASE]
#
# Les migrations ne sont PAS annulées : si la release fautive a modifié le schéma,
# vérifiez la compatibilité ou lancez « php artisan migrate:rollback » à la main.
set -Eeuo pipefail

DEPLOY_PATH="${1:?chemin de déploiement manquant}"
PHP_BIN="${PHP_BIN:-php}"
RELEASES="$DEPLOY_PATH/releases"
CURRENT="$(readlink -f "$DEPLOY_PATH/current" || true)"

if [ -n "${2:-}" ]; then
  TARGET="$RELEASES/$2"
else
  # Les noms de release commencent par AAAAMMJJhhmmss : l'ordre alphabétique est l'ordre chronologique.
  CURRENT_NAME="$(basename "$CURRENT")"
  PREVIOUS="$(ls -1 "$RELEASES" | sort | awk -v cur="$CURRENT_NAME" '$0 < cur' | tail -n 1)"
  TARGET="${PREVIOUS:+$RELEASES/$PREVIOUS}"
fi

[ -n "$TARGET" ] && [ -d "$TARGET" ] || { echo "[rollback] Aucune release précédente disponible." >&2; exit 1; }

echo "[rollback] $(basename "$CURRENT") -> $(basename "$TARGET")"
ln -sfn "$TARGET" "$DEPLOY_PATH/current.tmp"
mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

cd "$TARGET"
"$PHP_BIN" artisan optimize --ansi
if [ -n "${PHP_FPM_SERVICE:-}" ]; then sudo -n systemctl reload "$PHP_FPM_SERVICE" || true; fi
"$PHP_BIN" artisan queue:restart --ansi
echo "[rollback] Terminé."
