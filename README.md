# Actions

Gestionnaire de tâches (API REST, MCP, portail web). Voir [CLAUDE.md](CLAUDE.md), [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md).

État : squelette uniquement (points d'entrée vides, pas de fonctionnalité de tâche). Aucune authentification : ne lier qu'à `127.0.0.1`.

## Démarrage local

Prérequis : PHP >= 8.3 avec `pdo_pgsql`, Composer, Docker ou Podman.

1. `cp .env.example .env`, puis remplacer les mots de passe `changez-moi` (`.env` est ignoré par Git). Si le port 5432 est pris, changer `DB_PORT` (utilisé aussi pour publier le conteneur).
2. `docker compose up -d` (PostgreSQL 17, lié à `127.0.0.1`).
3. Base de test : `docker compose exec postgres createdb -U actions actions_test`.
4. `composer install`.
5. `php bin/migrate` (crée seulement la table `schema_migrations`; rejouable). `php bin/migrate status` liste appliquées/en attente.
6. `php -S 127.0.0.1:8080 -t public public/index.php`.
7. Vérifier : `curl http://127.0.0.1:8080/api/v1/health` (200 `{"status":"ok","database":"ok"}`), page d'accueil sur `/`, MCP : `POST /mcp` (JSON-RPC).

## Tests

- `composer test` : tout (Unit, Http, Integration; les tests d'intégration exigent PostgreSQL et la base `actions_test`, et échouent si elle est absente).
- `composer test:unit` : Unit + Http, sans base.

## Migrations

Fichiers `database/migrations/NNNN_nom.sql`, voir [database/migrations/README.md](database/migrations/README.md). Refusées hors `APP_ENV` `local`/`test`.
