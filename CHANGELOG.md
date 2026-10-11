# Journal des changements

## 2026-10-11

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
