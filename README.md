# Opale News v2

[![Version](https://img.shields.io/badge/version-2.3.0-blue.svg)](VERSION)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.0-000000.svg)](https://symfony.com/)

Plateforme web d’agrégation et de découverte d’événements sur la **Côte d’Opale**, avec recherche sémantique par embeddings (Google Gemini + Pinecone), modération éditoriale et déploiement Docker prêt pour la production.

Site cible : [opale.news](https://opale.news)

---

## Fonctionnalités

| Domaine | Description |
|---------|-------------|
| **Découverte** | Grille d’événements, carte Google Maps, filtres catégorie / période |
| **Recherche** | Requête en langage naturel, similarité vectorielle, boost géographique |
| **Soumission** | Formulaire public protégé reCAPTCHA, emails de confirmation |
| **Modération** | Back-office admin, approbation / rejet, notifications organisateur |
| **Technique** | Export ICS, signalement, purge automatique, réindexation horaire Pinecone |

---

## Stack technique

- **Backend** : Symfony 8.0, PHP 8.4, Doctrine ORM 3, PostgreSQL 16
- **IA / recherche** : Google Gemini (embeddings), Pinecone (index vectoriel)
- **Front** : Twig, JavaScript vanilla, Google Maps API
- **Infra** : Docker Compose (dev & prod), Caddy (TLS Let's Encrypt en prod)
- **Sécurité** : Symfony Security, reCAPTCHA v3, rate limiting

---

## Prérequis

- [Docker](https://docs.docker.com/get-docker/) et Docker Compose v2+
- Clés API (dev) : Gemini, Pinecone, Google Maps, reCAPTCHA (voir `.env`)

---

## Démarrage rapide (développement)

```bash
# 1. Cloner le dépôt
git clone https://github.com/ElliottDaens/opale_news_v2.git
cd opale_news_v2

# 2. Configurer les variables locales (secrets, clés API)
cp .env .env.local
# Éditer .env.local : APP_SECRET, DATABASE_URL, GEMINI_API_KEY, etc.

# 3. Lancer la stack
docker compose up -d --build

# 4. Installer les dépendances et migrer la base
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 5. (Optionnel) Créer un compte admin et des données de démo
docker compose exec php php bin/console app:user:create
docker compose exec php php bin/console app:seed:events
```

L’application est accessible sur **http://localhost:8080**.

- **Mailpit** (emails dev) : port mappé dynamiquement — `docker compose port mailer 8025`
- **PostgreSQL** : `localhost:5432` (identifiants dans `.env`)

---

## Commandes utiles

```bash
# Réindexer tous les événements approuvés dans Pinecone
docker compose exec php php bin/console app:radar:reindex

# Purger les événements expirés (base + Pinecone)
docker compose exec php php bin/console app:radar:purge-expired --days=2

# Réinitialiser le mot de passe admin
docker compose exec php php bin/console app:user:reset-password
```

---

## Production

La procédure complète (DNS, secrets, premier déploiement, sauvegardes, rollback) est décrite dans [DEPLOY.md](DEPLOY.md).

Résumé :

```bash
cp .env.prod.example .env.prod   # Renseigner tous les secrets
./scripts/deploy.sh --first-run  # Premier déploiement + création admin
./scripts/deploy.sh              # Déploiements suivants
```

Stack prod : **Caddy** (443) → **PHP-FPM** + **cron** + **PostgreSQL** (réseau interne).

---

## Structure du projet

```
├── config/           # Configuration Symfony
├── docker/           # Caddy, Nginx, PHP, cron
├── migrations/       # Migrations Doctrine
├── public/           # Point d'entrée web, assets statiques
├── scripts/          # deploy.sh, backup.sh, restore.sh
├── src/
│   ├── Command/      # Tâches CLI (reindex, purge, users)
│   ├── Controller/   # HTTP (public, admin, API search)
│   ├── Entity/       # Event, User
│   └── Service/      # Gemini, Pinecone, Geo, notifications…
├── templates/        # Vues Twig
├── compose.yaml      # Stack développement
├── compose.prod.yaml # Stack production
└── Dockerfile        # Image PHP multi-stage (dev / prod)
```

---

## Variables d'environnement

| Variable | Description |
|----------|-------------|
| `APP_SECRET` | Secret Symfony (`openssl rand -hex 32`) |
| `DATABASE_URL` | Connexion PostgreSQL |
| `GEMINI_API_KEY` | API Google Generative Language (embeddings) |
| `PINECONE_API_KEY` / `PINECONE_INDEX_URL` | Index vectoriel |
| `GMAPS_API_KEY` | Google Maps (front + géocodage) |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` | Protection formulaires |
| `MAILER_DSN` | Envoi d'emails transactionnels |
| `ADMIN_EMAIL` | Destinataire alertes modération |

Voir `.env` (dev) et `.env.prod.example` (production) pour la liste complète.

> **Ne jamais committer** `.env.local`, `.env.prod` ni de clés API réelles.

---

## Versioning

Ce projet suit [Semantic Versioning](https://semver.org/). La version courante est indiquée dans le fichier [VERSION](VERSION).

Historique des releases : [CHANGELOG.md](CHANGELOG.md).

```bash
git tag -l                    # Lister les tags
git checkout v2.0.0           # Se placer sur une release
```

---

## Licence

Logiciel propriétaire — voir [LICENSE](LICENSE). Tous droits réservés © 2026 Elliott Daens.

---

## Auteur

**Elliott Daens** — [github.com/ElliottDaens](https://github.com/ElliottDaens)
