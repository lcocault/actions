# Journal des changements

## 2026-10-10

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
