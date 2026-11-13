#!/usr/bin/env bash
# Déchiffre les fichiers du formulaire nécessaires aux tests (XLSForm, CSV de référence dont les noms des
# agents) dans storage/app/public/settings. Ils ne sont pas versionnés en clair : le dépôt est public.
#
#   SETTINGS_PASSPHRASE=... bash tests/fixtures/decrypt-settings.sh
#
# Rechiffrer après une modification des fichiers (depuis la racine du projet) :
#   cd storage/app/public/settings && tar -czf - binomes.csv campaigns.csv countries.csv country_meta.csv \
#     cov26c4v3.xls gavi_country_age_range.csv identifiants_villages_dagla_tchaourou.xlsx l1.csv \
#     l1_fieldworkers.csv l2.csv l3.csv l4.csv vaccines.csv village.csv \
#   | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:SETTINGS_PASSPHRASE \
#     -out ../../../../tests/fixtures/settings.tar.gz.enc
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ -z "${SETTINGS_PASSPHRASE:-}" ]; then
  echo "::error::Secret SETTINGS_PASSPHRASE manquant : Settings > Secrets and variables > Actions (voir docs/deploiement.md)."
  exit 1
fi

DEST="$ROOT/storage/app/public/settings"
mkdir -p "$DEST"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:SETTINGS_PASSPHRASE \
  -in "$ROOT/tests/fixtures/settings.tar.gz.enc" | tar -xz -C "$DEST"
echo "Fichiers du formulaire déchiffrés : $(ls "$DEST" | wc -l) fichier(s)."
