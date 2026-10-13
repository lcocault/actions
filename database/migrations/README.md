# Migrations PostgreSQL

Emplacement des migrations versionnées : `database/migrations/`.

**Existant** : exécuteur `bin/migrate` (`php bin/migrate`, `php bin/migrate status`) et table de suivi `schema_migrations`; migration `0001_tasks_and_dependencies.sql` (tâches, dépendances, trigger anti-cycle), `0002_task_status.sql` (colonnes `status`, défaut `todo`, et `completed_at`, deux `CHECK`; additive, les tâches existantes passent à `todo` sans clôture) et `0003_task_project_conclusions.sql` (colonnes `project`, liste fermée par `CHECK tasks_project_valid`, et `conclusions`, nullables; additive, les tâches existantes reçoivent `NULL`), appliquées en local et en test seulement. Refus si `APP_ENV` n’est ni `local` ni `test` (sauf `--allow-env=<APP_ENV>`, uniquement avec autorisation explicite). Conception : [docs/architecture.md](../../docs/architecture.md), section « Cycle de vie ».

**`0003`** : conception validée par l'utilisateur (section « Champs "Projet" et "Conclusions" » de [docs/architecture.md](../../docs/architecture.md)); suite de tests OK sur PostgreSQL 17 local (rapporté par le Developer). **Non vérifié** : PostgreSQL 16 et 18, AlwaysData (aucune migration appliquée).

**Retour arrière de `0003`** : pas de « down »; restauration d'une sauvegarde, ou migration `0004` (`DROP COLUMN`) destructive, sur décision de l'utilisateur seulement.

**Validé** (voir [docs/architecture.md](../../docs/architecture.md)) : fichiers SQL `NNNN_description.sql` appliqués dans l’ordre par `bin/migrate`, table de suivi `schema_migrations` avec checksum, transaction par migration, pas de migration « down ». Une migration appliquée n’est jamais réécrite : toute correction passe par une nouvelle migration. Ne jamais lancer une migration sur un environnement opérationnel sans autorisation explicite.
