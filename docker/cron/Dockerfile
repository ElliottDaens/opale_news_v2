# docker/cron/Dockerfile
#
# QUOI : Sidecar cron basé sur `opale-news:prod` pour les commandes Symfony planifiées.
#
# COMMENT : Installe `cron`, copie `docker/cron/crontab` ; commandes en `www-data`.
#
# OÙ : Service `cron` dans `compose.prod.yaml`.
#
# POURQUOI : Isoler les jobs batch du pool FPM web.

FROM opale-news:prod

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && rm -rf /var/lib/apt/lists/*

# Le crontab applicatif est monté en lecture seule par compose.prod.yaml.
# Format /etc/cron.d/* : la colonne "user" est obligatoire dans chaque ligne (cf. docker/cron/crontab).
COPY docker/cron/crontab /etc/cron.d/opale-news
RUN chmod 0644 /etc/cron.d/opale-news

# Cron tourne en root (impératif pour lire /etc/cron.d/), mais lance ses commandes en www-data.
CMD ["cron", "-f", "-L", "15"]
