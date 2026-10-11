# Migrations PostgreSQL

Emplacement des migrations versionnées : `database/migrations/`.

**Existant** : exécuteur `bin/migrate` (`php bin/migrate`, `php bin/migrate status`) et table de suivi `schema_migrations`; migration `0001_tasks_and_dependencies.sql` (tâches, dépendances, trigger anti-cycle), appliquée en local et en test seulement. Refus si `APP_ENV` n’est ni `local` ni `test` (sauf `--allow-env=<APP_ENV>`, uniquement avec autorisation explicite).

**Proposé, non écrit** : `0002_task_status.sql` (colonnes `status` et `completed_at`, deux `CHECK`, défaut `todo` pour les tâches existantes), additive; conception dans [docs/architecture.md](../../docs/architecture.md), section « Cycle de vie ». À appliquer en local/test seulement.

**Validé** (voir [docs/architecture.md](../../docs/architecture.md)) : fichiers SQL `NNNN_description.sql` appliqués dans l’ordre par `bin/migrate`, table de suivi `schema_migrations` avec checksum, transaction par migration, pas de migration « down ». Une migration appliquée n’est jamais réécrite : toute correction passe par une nouvelle migration. Ne jamais lancer une migration sur un environnement opérationnel sans autorisation explicite.
