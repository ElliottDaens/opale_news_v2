#!/usr/bin/env bash
#
# scripts/deploy.sh
#
# QUOI : Déploiement zero-downtime (backup, build, migrations, warmup, rotation).
#
# COMMENT : `compose.prod.yaml` + `.env.prod` ; options `--skip-build`, `--first-run`.
#
# OÙ : Serveur de production, à la racine du dépôt cloné.
#
# POURQUOI : Séquence reproductible sans oublier backup ni migrations.
#
# Usage : ./scripts/deploy.sh | ./scripts/deploy.sh --skip-build | ./scripts/deploy.sh --first-run

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

COMPOSE=(docker compose -f compose.prod.yaml --env-file .env.prod)

SKIP_BUILD=0
FIRST_RUN=0
for arg in "$@"; do
    case "$arg" in
        --skip-build) SKIP_BUILD=1 ;;
        --first-run)  FIRST_RUN=1 ;;
        *) echo "Argument inconnu : $arg" >&2; exit 1 ;;
    esac
done

if [[ ! -f .env.prod ]]; then
    echo "[ERREUR] .env.prod introuvable. Copier .env.prod.example puis renseigner les secrets." >&2
    exit 1
fi

log() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }

# 1) Sauvegarde Postgres AVANT toute migration destructive.
if [[ $FIRST_RUN -eq 0 ]]; then
    log "Sauvegarde PostgreSQL pré-déploiement"
    "$PROJECT_ROOT/scripts/backup.sh" pre-deploy
fi

# 2) Build (ou skip si on relance simplement les conteneurs).
if [[ $SKIP_BUILD -eq 0 ]]; then
    log "Build des images Docker (php → caddy → cron)"
    "${COMPOSE[@]}" build --pull php
    "${COMPOSE[@]}" build caddy cron
fi

# 3) Migrations Doctrine (idempotentes). Pour le premier run, doctrine:migrations:migrate
#    crée le schéma initial. Pour les runs suivants, applique les nouvelles versions.
log "Migrations PostgreSQL"
"${COMPOSE[@]}" up -d database
"${COMPOSE[@]}" run --rm php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# 4) Pré-chauffe du cache prod (warmup en RO pour révéler les erreurs avant rotation).
log "Pré-chauffe du cache Symfony"
"${COMPOSE[@]}" run --rm php php bin/console cache:clear --env=prod --no-debug
"${COMPOSE[@]}" run --rm php php bin/console cache:warmup --env=prod --no-debug

# 5) Compilation .env → .env.local.php (gain de perfs au boot).
log "Compilation .env.local.php (composer dump-env prod)"
"${COMPOSE[@]}" run --rm php composer dump-env prod || \
    echo "[WARN] dump-env indisponible (Flex < 1.2 ?). Continuer sans optimisation."

# 6) Rotation propre des conteneurs (recreate uniquement si l'image a changé).
log "Rotation des conteneurs (php, caddy, cron)"
"${COMPOSE[@]}" up -d --remove-orphans

# 7) Premier démarrage : créer l'admin.
if [[ $FIRST_RUN -eq 1 ]]; then
    log "Création du premier compte administrateur (interactif)"
    "${COMPOSE[@]}" exec php php bin/console app:user:create
fi

# 8) État final.
log "État des services"
"${COMPOSE[@]}" ps

log "Déploiement terminé. Vérifier : https://$(grep '^SITE_DOMAIN=' .env.prod | cut -d= -f2)"
