# Migrations PostgreSQL

Emplacement des migrations versionnées : `database/migrations/`.

**Existant** : exécuteur `bin/migrate` (`php bin/migrate`, `php bin/migrate status`) et table de suivi `schema_migrations`; migration `0001_tasks_and_dependencies.sql` (tâches, dépendances, trigger anti-cycle) et `0002_task_status.sql` (colonnes `status`, défaut `todo`, et `completed_at`, deux `CHECK`; additive, les tâches existantes passent à `todo` sans clôture), appliquées en local et en test seulement. Refus si `APP_ENV` n’est ni `local` ni `test` (sauf `--allow-env=<APP_ENV>`, uniquement avec autorisation explicite). Conception : [docs/architecture.md](../../docs/architecture.md), section « Cycle de vie ».

**Validé** (voir [docs/architecture.md](../../docs/architecture.md)) : fichiers SQL `NNNN_description.sql` appliqués dans l’ordre par `bin/migrate`, table de suivi `schema_migrations` avec checksum, transaction par migration, pas de migration « down ». Une migration appliquée n’est jamais réécrite : toute correction passe par une nouvelle migration. Ne jamais lancer une migration sur un environnement opérationnel sans autorisation explicite.
