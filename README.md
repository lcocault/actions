# Actions

Gestionnaire de tâches (API REST, MCP, portail web). Voir [CLAUDE.md](CLAUDE.md), [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md).

## État (local uniquement)

**Existant**
- Schéma PostgreSQL : migrations `0001` (tâches, dépendances, refus des cycles par trigger) et `0002` (état et date de clôture).
- Couche commune `src/Application` : créer, lire, lister (filtres état et catégorie), modifier, supprimer une tâche; gérer ses actions requises; changer son état (À faire, En cours, Terminée). Terminer une tâche périodique crée l'occurrence suivante.
- API REST `/api/v1` : `GET`/`POST /tasks`, `GET`/`PATCH`/`DELETE /tasks/{id}` (`PATCH` avec `status` pour changer l'état), `PUT`/`DELETE /tasks/{id}/required-actions/{id}`, `GET /health`. Contrat et codes d'erreur : [docs/architecture.md](docs/architecture.md).
- Portail web (tranches B1 et B2) : `GET /` redirige vers `/tasks`. Pages HTML rendues par le serveur, sans JavaScript : liste (filtres état, catégorie, « masquer les terminées »), détail, création, modification, suppression avec page de confirmation (refusée si la tâche est requise par d'autres), ajout et retrait d'actions requises, changement d'état (boutons Démarrer, Terminer, Remettre à faire, Rouvrir; terminer une tâche périodique crée l'occurrence suivante; rouvrir une périodique terminée passe par une page d'avertissement). Protections : contrôle de `Host` (`PORTAL_ALLOWED_HOSTS`), `Origin` et jeton CSRF sur les `POST`, en-têtes de sécurité, échappement systématique. Contrat des pages : [docs/architecture.md](docs/architecture.md).
- MCP (`POST /mcp`) : squelette seulement (`ping`); il ne sert pas encore les tâches.

**Non fait** : outils MCP, authentification (ne lier qu'à `127.0.0.1`), déploiement AlwaysData (aucune migration lancée hors local).

## Démarrage local

Raccourci : `bin/start` démarre PostgreSQL, applique les migrations et lance le serveur web; le portail est alors sur http://127.0.0.1:8080/; `bin/stop` arrête tout (`--purge` supprime aussi les données locales). Sans PHP natif, l'image Docker `actions-php-dev` est construite au besoin. Procédure manuelle ci-dessous.

Prérequis : PHP >= 8.3 avec `pdo_pgsql`, Composer, Docker ou Podman.

1. `cp .env.example .env`, puis remplacer les mots de passe `changez-moi` (`.env` est ignoré par Git). Si le port 5432 est pris, changer `DB_PORT` (utilisé aussi pour publier le conteneur).
2. `docker compose up -d` (PostgreSQL 17, lié à `127.0.0.1`).
3. Base de test : `docker compose exec postgres createdb -U actions actions_test`.
4. `composer install`.
5. `php bin/migrate` (applique les migrations en attente; rejouable). `php bin/migrate status` liste appliquées/en attente.
6. `php -S 127.0.0.1:8080 -t public public/index.php`.
7. Vérifier : `curl http://127.0.0.1:8080/api/v1/health` (200 `{"status":"ok","database":"ok"}`), portail sur `/` (redirige vers `/tasks`), MCP : `POST /mcp` (JSON-RPC).

Le portail refuse (403) toute requête dont l'en-tête `Host` n'est pas `127.0.0.1`, `localhost` ou `[::1]` (protection contre le « DNS rebinding », puisqu'il n'y a pas d'authentification). Pour un autre nom d'hôte local, le déclarer dans `PORTAL_ALLOWED_HOSTS` (`.env`, noms séparés par des virgules, sans port). Ne jamais exposer le portail tel quel hors de la machine : toute personne qui l'atteint lit et supprime toutes les données.

## Tests

- `composer test` : tout (Unit, Http, Integration; les tests d'intégration exigent PostgreSQL et la base `actions_test`, et échouent si elle est absente).
- `composer test:unit` : Unit + Http, sans base.

## Migrations

Fichiers `database/migrations/NNNN_nom.sql`, voir [database/migrations/README.md](database/migrations/README.md). Refusées hors `APP_ENV` `local`/`test`.
