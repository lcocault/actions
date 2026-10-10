# Migrations PostgreSQL

Emplacement des migrations versionnées : `database/migrations/`.

**Existant** : exécuteur `bin/migrate` (`php bin/migrate`, `php bin/migrate status`) et table de suivi `schema_migrations`; aucune migration de schéma métier. Refus si `APP_ENV` n’est ni `local` ni `test` (sauf `--allow-env=<APP_ENV>`, uniquement avec autorisation explicite).

**Validé** (voir [docs/architecture.md](../../docs/architecture.md)) : fichiers SQL `NNNN_description.sql` appliqués dans l’ordre par `bin/migrate`, table de suivi `schema_migrations` avec checksum, transaction par migration, pas de migration « down ». Une migration appliquée n’est jamais réécrite : toute correction passe par une nouvelle migration. Ne jamais lancer une migration sur un environnement opérationnel sans autorisation explicite.
