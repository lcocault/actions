# Journal des changements

## 2026-10-11

- Tranche « dépendances entre tâches », REST seul, local : `PUT` et `DELETE /api/v1/tasks/{id}/required-actions/{requiredId}` (200 + `Task`, idempotents), `GET /api/v1/tasks/{id}` (200), `DELETE /api/v1/tasks/{id}` (204). Rien d'autre du CRUD REST.
- Code : `Rest\TaskController` (requête -> `TaskService`, sans règle métier), `Rest\RestAdapter` (routage méthode + chemin, table unique code de domaine -> statut, conversion des erreurs), `Bootstrap::kernel()` passe le service. `TaskService`, dépôt, schéma, `Kernel`, `Request`, `Response` inchangés.
- Erreurs : 409 `dependency_cycle` (sans chemin) et `task_is_required` (avec `dependents`), 422 `dependency_self` et `required_task_not_found`, 404 `task_not_found`, 404 `not_found` pour un identifiant de chemin invalide (hors `[1-9][0-9]*` ou hors entier 64 bits), 405 `method_not_allowed` avec `Allow`, 503 `service_unavailable`, 500 `internal_error` pour toute autre exception dont `PDOException` (jamais exposée; classe, SQLSTATE et message journalisés par `error_log`). Un code de domaine absent de la table de statut donne 500.
- Comportement inchangé : ajouter ou retirer effectivement un lien met à jour `updated_at` de la tâche `{id}` (choix non encore tranché par l'utilisateur, voir BACKLOG); un ajout ou retrait idempotent n'y touche pas. `GET /api/v1/health` : mêmes réponses (200/503/405).
- Tests : `tests/Integration/RestTasksTest` (23 tests, PostgreSQL réel via `Kernel::handle`) : critères 1 à 8 du backlog, identifiants invalides, 405, 500 sans fuite (PDO simulé, SQLSTATE `XX000`), 503, `health` inchangé. Suite complète : 194 tests, OK.
- Migrations : aucune. Aucune dépendance ajoutée. Rien exécuté sur AlwaysData.
- Limites : pas d'authentification (local uniquement); corps et `Content-Type` des requêtes ignorés; MCP et portail ne servent pas encore ces cas d'usage; concurrence HTTP réelle non testée; `README.md` décrit encore l'état « squelette uniquement ».

- Tranche A, couche commune des tâches (`src/Application`), sans REST/MCP/portail ni état : `TaskService` (7 cas d'usage : `create`, `get`, `list`, `update`, `delete`, `addRequiredAction`, `removeRequiredAction`), `TaskValidator` (pur, fail-fast), `TaskRepository` (tout le SQL PDO paramétré, colonnes toujours nommées, traduction SQLSTATE -> exceptions, transaction `READ COMMITTED`), erreurs de domaine `DomainError`, `InvalidTask`, `TaskNotFound`, `RequiredTaskNotFound`, `InvalidDependency`, `CyclicDependency`, `TaskIsRequired`. `Bootstrap::taskService()` compose le service; les adaptateurs ne l'utilisent pas encore.
- Comportements : titre stocké tel quel (pas de `trim`), refusé s'il n'est fait que de blancs (espaces, tabulations, retours, espaces Unicode); clés inconnues refusées (`field_unknown`); date butée passée acceptée; ajout d'un lien existant et retrait d'un lien absent idempotents; suppression d'un id inconnu = `TaskNotFound`; `update` valide l'état fusionné et n'écrit pas si rien ne change; ajouter/retirer effectivement un lien met à jour `updated_at` de la tâche (proposé par l'architecture, non encore confirmé).
- Interprétations de la conception : `title` absent -> `title_blank`, `category` absente -> `category_invalid`; clé inconnue d'une périodicité -> `field_unknown` avec `field` = `recurrence.<clé>`; `recurrence_requires_due_date` porte `field` = `recurrence`.
- Tests : `tests/Unit/TaskValidatorTest` (57 tests) et `tests/Integration/TaskServiceTest` (54 tests, PostgreSQL réel, base `actions_test` remigrée dans `setUp`). Suite complète : 171 tests, OK.
- Migrations : aucune ajoutée (schéma 0001 inchangé); `bin/migrate` rejoué en local (« Rien à appliquer »). Rien exécuté sur AlwaysData. Aucune dépendance ajoutée.
- Limites : aucune concurrence réelle testée au niveau service (le trigger l'est dans `TaskSchemaTest`); les erreurs SQL inattendues sont relancées telles quelles (à convertir en `internal_error` par les adaptateurs); `mbstring` sur la version PHP AlwaysData cible non vérifié.

## 2026-10-10

- Migration `0001_tasks_and_dependencies.sql` : tables `tasks` et `task_dependencies`, contraintes nommées, index, fonction PL/pgSQL `task_dependencies_check_cycle()` et trigger `task_dependencies_no_cycle` (verrou `pg_advisory_xact_lock`, refus des cycles), `RESTRICT` côté tâche requise, `CASCADE` côté `task_id`. Pas de statut « terminée ». Appliquée uniquement sur la base locale de développement (`actions`, APP_ENV=local) et sur `actions_test` via les tests; rien exécuté sur AlwaysData.
- Deux précisions par rapport à l'esquisse de l'architecture (à faire valider) : (1) le `CHECK tasks_recurrence_valid` ajoute `IS NOT NULL` sur les deux colonnes, car l'esquisse laissait passer une paire à moitié renseignée (le `CHECK` valait `NULL`, donc accepté); (2) le trigger ignore l'ancienne version de la ligne lors d'un `UPDATE` et rend la main au `CHECK` pour une auto-dépendance (sinon `23514` avec le mauvais nom de contrainte).
- Tests : `tests/Integration/TaskSchemaTest.php` (24 tests, un par règle : SQLSTATE et nom de contrainte, cycles direct/indirect/multi-lignes/`UPDATE`, losange, suppression, concurrence à deux connexions, rejouabilité). `MigratorTest` nettoie désormais aussi les tables métier. Suite complète : 60 tests, OK.
- Limites : `btrim` ne retire que les espaces, un titre composé uniquement de tabulations/retours à la ligne est accepté par la base (à trancher); le nom de contrainte du trigger n'est pas exposé par `PDOException::errorInfo` (il figure dans le message); droits `CREATE FUNCTION`/trigger et pgBouncer sur AlwaysData non vérifiés.
- Squelette de l'application (PHP nu, PHP >= 8.3, zéro dépendance d'exécution, PHPUnit 12 en dev) : front controller `public/index.php`, adaptateurs REST (`GET /api/v1/health`), MCP (`POST /mcp` : initialize, notifications/initialized, tools/list, tools/call `ping`) et portail (`GET /`), tous branchés sur `Application\Diagnostic`.
- Configuration par variables d'environnement / `.env` (ignoré par Git), `.env.example` sans secret; `compose.yaml` (PostgreSQL 17 sur 127.0.0.1, port via `DB_PORT`).
- Migrations : exécuteur `bin/migrate` (+ `status`), table `schema_migrations` avec checksum, verrou consultatif, transaction par migration, garde-fou `APP_ENV`. Aucune migration de schéma métier ajoutée; seule la table de suivi est créée, sur une base locale de développement et la base de test. Rien exécuté sur AlwaysData.
- Tests : 36 tests (Unit, Http, Integration) exécutés avec PHP 8.3 en conteneur contre PostgreSQL 17 local.
- Limites : aucune authentification (local uniquement); réécriture d'URL, racine `public/` et configuration sur AlwaysData non vérifiées; PHP absent du poste (vérifié via une image Docker `php:8.3-cli` + `pdo_pgsql` construite hors dépôt).

## 2026-10-08

- État initial examiné : le dépôt contient uniquement un README minimal; aucun code PHP, dépendance, test, interface ou schéma n’est présent.
- Ajout du backlog priorisé, des décisions ouvertes et d’une proposition de prochaines tranches.
- Ajout d’une architecture initiale qui sépare l’état observé des conventions proposées.
- Migrations : aucune migration PostgreSQL n’existe ni n’est ajoutée; aucun schéma n’est défini et aucune migration n’a été exécutée.
- Limites : versions et capacités de l’offre AlwaysData cible non vérifiées; choix produit et contrats restent à valider.
