# Actions

Gestionnaire de tâches (API REST, MCP, portail web). Voir [CLAUDE.md](CLAUDE.md), [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md).

État (local uniquement) : squelette, schéma et couche commune des tâches; en REST, lecture et suppression d'une tâche et gestion des actions requises (`GET`/`DELETE /api/v1/tasks/{id}`, `PUT`/`DELETE .../required-actions/{id}`). Création, liste et modification (`POST`/`GET /api/v1/tasks`, `PATCH /api/v1/tasks/{id}`) : contrat écrit, pas encore implémenté. MCP et portail ne servent pas encore les tâches; états des tâches à venir. Aucune authentification : ne lier qu'à `127.0.0.1`.

## Démarrage local

Raccourci : `bin/start` démarre PostgreSQL, applique les migrations et lance le serveur web (http://127.0.0.1:8080); `bin/stop` arrête tout (`--purge` supprime aussi les données locales). Sans PHP natif, l'image Docker `actions-php-dev` est construite au besoin. Procédure manuelle ci-dessous.

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
