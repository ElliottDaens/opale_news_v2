#!/usr/bin/env bash
#
# scripts/restore.sh
#
# QUOI : Restauration d’un dump `.dump.gz` produit par `backup.sh`.
#
# COMMENT : Décompression puis `pg_restore` dans le conteneur `database`.
#
# OÙ : Staging ou prod après incident ; argument = chemin du fichier.
#
# POURQUOI : Procédure DR testable et documentée.
#
# Usage : ./scripts/restore.sh scripts/backup/opale-news_pre-deploy_20260521T120000Z.dump.gz

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if [[ $# -ne 1 ]]; then
    echo "Usage : $0 <fichier.dump.gz>" >&2
    exit 1
fi

DUMP_FILE="$1"
if [[ ! -f "$DUMP_FILE" ]]; then
    echo "[ERREUR] Fichier introuvable : $DUMP_FILE" >&2
    exit 1
fi

COMPOSE=(docker compose -f compose.prod.yaml --env-file .env.prod)
POSTGRES_USER="$(grep '^POSTGRES_USER=' .env.prod | cut -d= -f2)"
POSTGRES_DB="$(grep '^POSTGRES_DB=' .env.prod | cut -d= -f2)"

echo "⚠️  ATTENTION : la base '${POSTGRES_DB}' va être ÉCRASÉE par '${DUMP_FILE}'."
read -rp "Taper 'RESTORE' pour confirmer : " CONFIRM
if [[ "$CONFIRM" != "RESTORE" ]]; then
    echo "Annulation."; exit 1
fi

echo "▶ Arrêt du conteneur php (les workers ne doivent pas écrire pendant la restauration)"
"${COMPOSE[@]}" stop php cron

echo "▶ Drop & recreate de la base"
"${COMPOSE[@]}" exec -T database psql -U "$POSTGRES_USER" -d postgres <<SQL
    DROP DATABASE IF EXISTS "${POSTGRES_DB}";
    CREATE DATABASE "${POSTGRES_DB}" OWNER "${POSTGRES_USER}";
SQL

echo "▶ Restauration depuis $DUMP_FILE"
gunzip -c "$DUMP_FILE" | "${COMPOSE[@]}" exec -T database pg_restore \
    -U "$POSTGRES_USER" \
    -d "$POSTGRES_DB" \
    --no-owner \
    --no-privileges \
    --exit-on-error

echo "▶ Redémarrage de php et cron"
"${COMPOSE[@]}" start php cron

echo "✓ Restauration terminée."
