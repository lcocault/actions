# Actions

Gestionnaire de tâches (API REST, MCP, portail web). Voir [CLAUDE.md](CLAUDE.md), [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md).

## État (local uniquement)

**Existant**
- Schéma PostgreSQL : migrations `0001` (tâches, dépendances, refus des cycles par trigger), `0002` (état et date de clôture) `0003` (projet en liste fermée et conclusions) et `0004` (projets configurables : table `projects`, la catégorie `Pro`/`Perso` d'une tâche découle de son projet; six projets créés : `MS-HR` Pro; `Jardin/Maison`, `OPF`, `Erendil` Perso; accueils `Divers Pro` et `Divers Perso`) et `0005` (importance d'une tâche : `Mineure` par défaut, `Majeure`, `Critique`).
- Couche commune `src/Application` : créer, lire, lister (filtres état, catégorie, projet et importance), modifier, supprimer une tâche; gérer ses actions requises; changer son état (À faire, En cours, Terminée). Terminer une tâche périodique crée l'occurrence suivante.
- API REST `/api/v1` : `GET`/`POST /projects`, `GET`/`PATCH`/`DELETE /projects/{id}` (la catégorie d'une tâche n'est plus saisie : `category` est refusée en écriture, `project` est obligatoire; `importance` facultative, `Mineure` par défaut, filtre `?importance=`), `GET`/`POST /tasks`, `GET`/`PATCH`/`DELETE /tasks/{id}` (`PATCH` avec `status` pour changer l'état), `PUT`/`DELETE /tasks/{id}/required-actions/{id}`, `GET /health`. Contrat et codes d'erreur : [docs/architecture.md](docs/architecture.md).
- Portail web (tranches B1 et B2) : `GET /` redirige vers `/tasks`. Pages HTML rendues par le serveur, sans JavaScript : liste (filtres état, catégorie, projet configuré, importance, « masquer les terminées »; champ « Importance » sans option vide; la catégorie découle du projet, il n'y a plus de champ Catégorie dans les formulaires), détail, création, modification, suppression avec page de confirmation (refusée si la tâche est requise par d'autres), ajout et retrait d'actions requises, changement d'état (boutons Démarrer, Terminer, Remettre à faire, Rouvrir; terminer une tâche périodique crée l'occurrence suivante; rouvrir une périodique terminée passe par une page d'avertissement). Protections : contrôle de `Host` (`PORTAL_ALLOWED_HOSTS`), `Origin` et jeton CSRF sur les `POST`, en-têtes de sécurité, échappement systématique. Contrat des pages : [docs/architecture.md](docs/architecture.md).
- MCP (`POST /mcp`, tranche MCP M1) : huit outils des tâches plus `ping` : `create_task`, `get_task`, `list_tasks`, `update_task`, `delete_task` (exige `confirm: true`), `add_required_action`, `remove_required_action`, `change_task_status`. Mêmes règles, champs (dont `importance`) et codes d'erreur que REST (texte JSON; erreur d'outil : `isError: true`). Contrat : [docs/architecture.md](docs/architecture.md).

**Non fait** : pages portail de gestion des projets et outil MCP `list_projects` (tranche 2; les projets se gèrent par REST), authentification (ne lier qu'à `127.0.0.1`), déploiement AlwaysData (aucune migration lancée hors local).

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

### Déclarer le serveur MCP dans Claude Code (local)

Serveur démarré (étape 6) : `claude mcp add --transport http actions http://127.0.0.1:8080/mcp`, ou fichier `.mcp.json` :

```json
{"mcpServers":{"actions":{"type":"http","url":"http://127.0.0.1:8080/mcp"}}}
```

`POST /mcp` refuse (403) un `Host` absent de `MCP_ALLOWED_HOSTS` (défaut : `127.0.0.1`, `localhost`, `[::1]`) et un `Origin` absent de `MCP_ALLOWED_ORIGINS`; il répond 400 si `MCP-Protocol-Version` est présent et non supporté (`2025-06-18`, `2025-03-26`). Aucune authentification : ne lier qu'à `127.0.0.1`. Le contenu des tâches est du texte libre renvoyé au modèle; `confirm: true` protège d'un appel accidentel, pas d'un texte hostile : laisser l'approbation d'outil du client activée pour `delete_task`. Compatibilité de Claude Code avec ce serveur : non vérifiée à ce jour.

Le portail refuse (403) toute requête dont l'en-tête `Host` n'est pas `127.0.0.1`, `localhost` ou `[::1]` (protection contre le « DNS rebinding », puisqu'il n'y a pas d'authentification). Pour un autre nom d'hôte local, le déclarer dans `PORTAL_ALLOWED_HOSTS` (`.env`, noms séparés par des virgules, sans port). Ne jamais exposer le portail tel quel hors de la machine : toute personne qui l'atteint lit et supprime toutes les données.

Préfixe d'URL : `APP_BASE_PATH` (ex. `/actions`, vide par défaut = aucun préfixe) fait servir portail, REST et MCP sous ce préfixe (`/actions/tasks`, `/actions/api/v1/...`, client MCP : `http://cocault.alwaysdata.net/actions/mcp`). Valeur invalide : erreur 500 au chargement. Avec `php -S` et un préfixe, la feuille de style `/actions/assets/portal.css` n'est pas servie (limite connue; pages et API utilisables); sans préfixe, elle l'est. Voir [docs/architecture.md](docs/architecture.md).

## Tests

- `composer test` : tout (Unit, Http, Integration; les tests d'intégration exigent PostgreSQL et la base `actions_test`, et échouent si elle est absente).
- `composer test:unit` : Unit + Http, sans base.

## Migrations

Fichiers `database/migrations/NNNN_nom.sql`, voir [database/migrations/README.md](database/migrations/README.md). Refusées hors `APP_ENV` `local`/`test`.

Avant `0004` sur une base qui contient déjà des tâches : sauvegarde (`pg_dump`), puis `php bin/report-project-conflicts` (lecture seule; code de sortie 1 et liste des tâches dont la catégorie diffère de celle de leur projet), arbitrage manuel, puis `php bin/migrate`.
