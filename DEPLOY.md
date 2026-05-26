# Déploiement Opale News — Procédure complète

## 0. Vue d'ensemble

Stack de production :

| Service    | Image                  | Rôle                                                         |
|------------|------------------------|--------------------------------------------------------------|
| `caddy`    | `opale-news:caddy`     | Reverse-proxy TLS automatique (Let's Encrypt), assets statiques |
| `php`      | `opale-news:prod`      | PHP-FPM 8.4 (Symfony 8.0), workers web                       |
| `cron`     | `opale-news:cron`      | Tâches planifiées (`app:radar:purge-expired`, `app:radar:reindex`) |
| `database` | `postgres:16-alpine`   | Base PostgreSQL (volume persistant, non exposée)             |

Réseau : un seul réseau `backend` interne. Seul Caddy publie les ports 80/443.

---

## 1. Pré-déploiement (hors serveur)

### 1.1 Révocation des clés exposées

Les clés présentes dans l'historique de `.env.local` doivent être révoquées AVANT le premier déploiement :

- **Google Cloud Console** → APIs & Services → Identifiants
  - Supprimer `GMAPS_API_KEY` (commence par `AIzaSyCYOUFfp5...`)
  - Supprimer `GEMINI_API_KEY` (commence par `AIzaSyAkU6JGPq...`)
  - Créer une **nouvelle clé Maps** restreinte HTTP referrer : `https://opale.news/*` et `https://www.opale.news/*`
  - Créer une **nouvelle clé Gemini** (sans restriction referrer, restreinte aux APIs Generative Language)
- **reCAPTCHA admin** → recaptcha.net/admin
  - Supprimer l'ancien site key
  - Créer une nouvelle paire site/secret pour le domaine `opale.news`
- **Pinecone Console** → API Keys
  - Créer une **nouvelle clé** (la garder ouverte)
  - Supprimer l'ancienne

### 1.2 Purger l'historique Git (si le repo public/partagé contient .env.local)

```bash
# Avec git-filter-repo (recommandé) :
pip install git-filter-repo
git filter-repo --path .env.local --invert-paths

# OU avec BFG :
bfg --delete-files .env.local
git reflog expire --expire=now --all && git gc --prune=now --aggressive

# Force-push (DESTRUCTIF — coordonner avec l'équipe) :
git push --force --all
```

---

## 2. Préparation du serveur (Debian/Ubuntu)

### 2.1 Pré-requis hôte

```bash
sudo apt update && sudo apt install -y docker.io docker-compose-plugin git
sudo usermod -aG docker $USER  # déconnecter/reconnecter ensuite
```

### 2.2 DNS

Pointer `opale.news` (et `www.opale.news` si désiré) vers l'IP publique du serveur (enregistrements A/AAAA).

### 2.3 Pare-feu

```bash
sudo ufw allow 22/tcp     # SSH
sudo ufw allow 80/tcp     # ACME challenge
sudo ufw allow 443/tcp    # HTTPS
sudo ufw allow 443/udp    # HTTP/3 (QUIC)
sudo ufw enable
```

### 2.4 Cloner le projet

```bash
sudo mkdir -p /var/www/opale-news && sudo chown $USER:$USER /var/www/opale-news
cd /var/www/opale-news
git clone <url-repo> .
```

---

## 3. Configuration `.env.prod`

```bash
cp .env.prod.example .env.prod
chmod 600 .env.prod
nano .env.prod
```

Remplir **toutes** les variables :

```bash
# Générer un APP_SECRET fort :
openssl rand -hex 32

# Générer un mot de passe Postgres fort :
openssl rand -base64 24 | tr -d '/=+'
```

⚠️ Coller les **nouvelles** clés Google/Pinecone/reCAPTCHA créées en 1.1.

---

## 4. Premier démarrage

```bash
./scripts/deploy.sh --first-run
```

Ce script :
1. Construit les trois images (`opale-news:prod`, `opale-news:caddy`, `opale-news:cron`)
2. Démarre PostgreSQL et lance les migrations Doctrine
3. Compile le cache Symfony en mode prod
4. Génère `.env.local.php` via `composer dump-env prod`
5. Démarre tous les conteneurs
6. **Vous demande de saisir l'email + mot de passe du premier administrateur**

À la fin, vérifier :

```bash
docker compose -f compose.prod.yaml --env-file .env.prod ps
curl -I https://opale.news/      # doit renvoyer 200 + en-têtes HSTS
```

---

## 5. Déploiements ultérieurs

```bash
git pull
./scripts/deploy.sh
```

Le script :
1. Effectue une sauvegarde Postgres pré-déploiement
2. Rebuild les images
3. Applique les nouvelles migrations
4. Effectue une rotation des conteneurs (zero-downtime sur Caddy)

---

## 6. Sauvegardes

### 6.1 Sauvegarde quotidienne (cron HÔTE, pas conteneur)

```bash
crontab -e
```

Ajouter :

```cron
# Sauvegarde quotidienne PostgreSQL à 02:00 UTC, rotation 14 jours
0 2 * * * /var/www/opale-news/scripts/backup.sh daily >> /var/log/opale-backup.log 2>&1
```

Les dumps sont écrits dans `/var/www/opale-news/scripts/backup/` (chmod 700, gitignore).

### 6.2 Restauration

```bash
./scripts/restore.sh scripts/backup/opale-news_daily_20260521T020000Z.dump.gz
```

Demande confirmation explicite (`RESTORE`) avant d'écraser la base.

---

## 7. Tâches planifiées

Le conteneur `cron` exécute **déjà** :

```cron
0 3 * * * www-data php bin/console app:radar:purge-expired --days=2 --no-interaction
0 * * * * www-data php bin/console app:radar:reindex --no-interaction
```

**Aucune ligne crontab à ajouter côté hôte pour ces tâches** — le sidecar `cron` s'en charge.

Vérifier les logs :

```bash
docker compose -f compose.prod.yaml --env-file .env.prod logs cron --tail=100 -f
```

---

## 8. Monitoring & observabilité

### Logs applicatifs

```bash
docker compose -f compose.prod.yaml --env-file .env.prod logs php   --tail=100 -f
docker compose -f compose.prod.yaml --env-file .env.prod logs caddy --tail=100 -f
```

Tous les services écrivent en JSON sur stdout (`monolog.formatter.json` côté Symfony, format JSON natif côté Caddy).

### État de santé

```bash
docker compose -f compose.prod.yaml --env-file .env.prod ps --format 'table {{.Service}}\t{{.Status}}\t{{.State}}'
```

Un service en `(unhealthy)` doit alerter immédiatement.

### Métriques PHP-FPM

Endpoint `/fpm-status` exposé en interne. Pour le consulter :

```bash
docker compose -f compose.prod.yaml --env-file .env.prod exec php \
    sh -c 'SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'
```

---

## 9. Rollback

```bash
# 1. Restaurer le dump pré-déploiement (le plus récent).
ls -1t scripts/backup/opale-news_pre-deploy_*.dump.gz | head -1

# 2. Restaurer la base.
./scripts/restore.sh scripts/backup/opale-news_pre-deploy_<STAMP>.dump.gz

# 3. Revenir à la version précédente du code.
git log --oneline -5
git checkout <commit-précédent>
./scripts/deploy.sh
```

---

## 10. Checklist Jour J

- [ ] DNS `opale.news` (A/AAAA) propagé
- [ ] Pare-feu hôte ouvert 80/443
- [ ] Clés Google Maps + Gemini + Pinecone + reCAPTCHA régénérées et restreintes
- [ ] `.env.prod` rempli, permissions `600`
- [ ] `./scripts/deploy.sh --first-run` exécuté sans erreur
- [ ] `curl -I https://opale.news` renvoie `200` + `Strict-Transport-Security`
- [ ] Compte admin créé, connexion `/admin` fonctionnelle
- [ ] Recherche sémantique testée (au moins une requête)
- [ ] Crontab hôte ajouté pour `scripts/backup.sh daily`
- [ ] Logs `cron` montrent l'exécution de `app:radar:reindex` à l'heure pleine
- [ ] Sauvegarde manuelle initiale : `./scripts/backup.sh initial`
