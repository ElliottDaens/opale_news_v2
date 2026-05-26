# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [2.0.0] - 2026-05-26

### Ajouté

- Application Symfony 8 / PHP 8.4 pour l’agrégation d’événements sur la Côte d’Opale.
- Recherche sémantique via embeddings Gemini et index vectoriel Pinecone.
- Carte interactive (Google Maps), filtres par catégorie et période, pagination.
- Soumission publique d’événements avec reCAPTCHA et workflow de modération.
- Interface d’administration (validation, édition, rejet, notifications email).
- Export calendrier ICS et signalement d’événements.
- Stack Docker dev (PHP-FPM, Nginx, PostgreSQL, Mailpit).
- Stack Docker prod (Caddy TLS, PHP-FPM, cron Symfony, PostgreSQL isolé).
- Scripts de déploiement, sauvegarde et restauration PostgreSQL.
- Commandes CLI : réindexation Pinecone, purge des événements expirés, gestion utilisateurs.
- Documentation de déploiement (`DEPLOY.md`) et modèle de secrets (`.env.prod.example`).

### Technique

- Doctrine ORM 3 avec migrations, soft-delete et statuts de modération.
- Rate limiting, logs JSON Monolog, healthchecks Docker.
- Parsing temporel des requêtes de recherche et scoring hybride (similarité + proximité).

[2.0.0]: https://github.com/ElliottDaens/opale_news_v2/releases/tag/v2.0.0
