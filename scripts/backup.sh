#!/usr/bin/env bash
#
# scripts/backup.sh
#
# QUOI : Sauvegarde PostgreSQL compressée (`pg_dump` + gzip), rotation 14 jours.
#
# COMMENT : Dump via conteneur `database` ; label argument (`pre-deploy`, `daily`, …).
#
# OÙ : `./scripts/backup/` ; appelé par `deploy.sh` et cron hôte.
#
# POURQUOI : Filet avant migration destructive et historique restaurable.
#
# Cron hôte : 0 2 * * * /var/www/opale-news/scripts/backup.sh daily

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="$PROJECT_ROOT/scripts/backup"
LABEL="${1:-manual}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

cd "$PROJECT_ROOT"
COMPOSE=(docker compose -f compose.prod.yaml --env-file .env.prod)

STAMP="$(date -u +'%Y%m%dT%H%M%SZ')"
FILE="$BACKUP_DIR/opale-news_${LABEL}_${STAMP}.dump"

echo "▶ Dump PostgreSQL → $FILE"
"${COMPOSE[@]}" exec -T database pg_dump \
    -U "${POSTGRES_USER:-opale}" \
    -d "${POSTGRES_DB:-opale_news}" \
    --format=custom \
    --no-owner \
    --no-privileges \
    > "$FILE"

# Vérification basique : un dump pg vide fait moins de 1 Ko.
if [[ ! -s "$FILE" ]] || [[ "$(stat -c%s "$FILE")" -lt 1024 ]]; then
    echo "[ERREUR] Dump suspect (vide ou < 1 Ko). Conservation pour inspection." >&2
    exit 1
fi

# Compression à part pour pouvoir relire l'en-tête sans décompresser.
gzip --best --force "$FILE"

echo "▶ Rotation : suppression des dumps de plus de ${RETENTION_DAYS} jours"
find "$BACKUP_DIR" -type f -name 'opale-news_*.dump.gz' -mtime +"$RETENTION_DAYS" -print -delete

echo "▶ Sauvegarde OK : ${FILE}.gz ($(du -h "${FILE}.gz" | cut -f1))"
