# Actions

Gestionnaire de tâches (API REST, MCP, portail web). Voir [CLAUDE.md](CLAUDE.md), [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md).

## État (local uniquement)

**Existant**
- Schéma PostgreSQL : migrations `0001` (tâches, dépendances, refus des cycles par trigger) et `0002` (état et date de clôture).
- Couche commune `src/Application` : créer, lire, lister (filtres état et catégorie), modifier, supprimer une tâche; gérer ses actions requises; changer son état (À faire, En cours, Terminée). Terminer une tâche périodique crée l'occurrence suivante.
- API REST `/api/v1` : `GET`/`POST /tasks`, `GET`/`PATCH`/`DELETE /tasks/{id}` (`PATCH` avec `status` pour changer l'état), `PUT`/`DELETE /tasks/{id}/required-actions/{id}`, `GET /health`. Contrat et codes d'erreur : [docs/architecture.md](docs/architecture.md).
- MCP (`POST /mcp`) et portail (`GET /`) : squelette seulement (`ping`, page d'accueil); ils ne servent pas encore les tâches.

**Non fait** : outils MCP et portail sur les tâches, authentification (ne lier qu'à `127.0.0.1`), déploiement AlwaysData (aucune migration lancée hors local).

## Démarrage local

Raccourci : `bin/start` démarre PostgreSQL, applique les migrations et lance le serveur web (http://127.0.0.1:8080); `bin/stop` arrête tout (`--purge` supprime aussi les données locales). Sans PHP natif, l'image Docker `actions-php-dev` est construite au besoin. Procédure manuelle ci-dessous.

Prérequis : PHP >= 8.3 avec `pdo_pgsql`, Composer, Docker ou Podman.

1. `cp .env.example .env`, puis remplacer les mots de passe `changez-moi` (`.env` est ignoré par Git). Si le port 5432 est pris, changer `DB_PORT` (utilisé aussi pour publier le conteneur).
2. `docker compose up -d` (PostgreSQL 17, lié à `127.0.0.1`).
3. Base de test : `docker compose exec postgres createdb -U actions actions_test`.
4. `composer install`.
5. `php bin/migrate` (applique les migrations en attente; rejouable). `php bin/migrate status` liste appliquées/en attente.
6. `php -S 127.0.0.1:8080 -t public public/index.php`.
7. Vérifier : `curl http://127.0.0.1:8080/api/v1/health` (200 `{"status":"ok","database":"ok"}`), page d'accueil sur `/`, MCP : `POST /mcp` (JSON-RPC).

## Tests

- `composer test` : tout (Unit, Http, Integration; les tests d'intégration exigent PostgreSQL et la base `actions_test`, et échouent si elle est absente).
- `composer test:unit` : Unit + Http, sans base.

## Migrations

Fichiers `database/migrations/NNNN_nom.sql`, voir [database/migrations/README.md](database/migrations/README.md). Refusées hors `APP_ENV` `local`/`test`.
