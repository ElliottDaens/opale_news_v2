# docker/caddy/Dockerfile
#
# QUOI : Image Caddy avec copie figée de `public/` depuis l'image PHP prod.
#
# COMMENT : Stage `assets` = `FROM opale-news:prod` ; uploads via volume `app_uploads` au runtime.
#
# OÙ : Build du service `caddy` dans `compose.prod.yaml`.
#
# POURQUOI : Aligner les assets servis par Caddy et FPM sans volume partagé pour le build statique.

ARG OPALE_IMAGE=opale-news:prod

FROM ${OPALE_IMAGE} AS assets

FROM caddy:2-alpine

# Le dossier public/uploads (montant le volume `app_uploads`) reste vide ici ;
# il est remonté à l'exécution par compose.prod.yaml.
COPY --from=assets /var/www/html/public /var/www/html/public

# Caddy tourne en non-root par défaut (uid 1000).
