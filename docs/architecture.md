# Architecture

Légende des statuts : **existant** (dans le dépôt, vérifiable), **proposé** (soumis à validation, non opposable au Developer), **validé** (accepté par l’utilisateur). Sans mention, un élément est **proposé**.

## État du dépôt

**Existant** (relu le 2026-10-11) : documentation; squelette (`src/`, `bin/migrate`, `compose.yaml`, `.env.example`, `tests/`, `README.md`); migration `database/migrations/0001_tasks_and_dependencies.sql` (tables `tasks`, `task_dependencies`, trigger anti-cycle) et `tests/Integration/TaskSchemaTest.php`, appliqués en local seulement (rien sur AlwaysData). La couche commune des tâches (tranche A) existe : `TaskService`, `TaskValidator`, `TaskRepository`, six erreurs de domaine, `Bootstrap::taskService()`, `TaskValidatorTest`, `TaskServiceTest`; aucun adaptateur (REST, MCP, portail) ne l'appelle encore, `RestAdapter` ne connaît que `GET /api/v1/health`. La tranche REST « dépendances » (`GET`/`DELETE /tasks/{id}`, liens `PUT`/`DELETE`) existe en local (`TaskController`, `RestAdapter`, `RestTasksTest`); le contrat de `POST`/`GET /tasks` et `PATCH /tasks/{id}` est écrit (section « Tranche CRUD REST »), non implémenté. Relecture de l'Architecte au 2026-10-11 : SQL de 0001, `TaskRepository`, `TaskService`, `RestAdapter`, `Kernel`, `Bootstrap`, `Request`/`Response`, tests `TaskSchemaTest`, `TaskServiceTest` (noms et passages cités) et `RestTest`. Aucun test n'a été rejoué par l'Architecte (pas d'exécution possible dans son rôle) : la vérification reste celle du Developer/PO.

## Décisions validées (tranche « squelette »)

- **Validé** : PHP sans framework + Composer minimal (chaque dépendance justifiée).
- **Validé** : MCP en HTTP distant.
- **Validé** : squelette sans authentification, jamais exposé publiquement ni déployé sur AlwaysData dans cette tranche. L’authentification est un élément séparé du backlog, requis avant toute exposition.
- **Validé** (2026-10-10, utilisateur) : PostgreSQL local par conteneur Docker/Podman (`compose.yaml`).
- **Validé** (2026-10-10, utilisateur) : contrat REST `GET /api/v1/health` (200/503/404/405, format d'erreur commun).
- **Validé** (2026-10-10, utilisateur) : contrat MCP minimal : `POST /mcp`, sans session, JSON seul (GET/DELETE : 405), batchs refusés, contrôle de l'en-tête `Origin`, outil `ping`, erreurs JSON-RPC décrites.
- **Validé** (2026-10-10, utilisateur) : zéro dépendance d'exécution, `phpunit/phpunit` en dev, PHP >= 8.3.
- **Validé** (2026-10-10, utilisateur) : migrations en `NNNN_nom.sql`, table `schema_migrations` avec checksum, pas de « down », refus hors `local`/`test` sauf option explicite.
- **Validé** (décisions de l'utilisateur transmises pour la tranche A, 2026-10-11) : titre fait seulement de tabulations/retours à la ligne refusé (validation de domaine, en plus du `CHECK`); ajout d'un lien déjà existant idempotent (succès, aucun doublon); date butée passée autorisée; suppression d'une tâche requise refusée avec la liste des dépendants; cycles et auto-dépendance refusés.
- **Validé, non livré (tranche B)** : états `À faire` / `En cours` / `Terminée`, transitions, terminaison avec actions requises, occurrence d'une périodique, suppression (décisions explicites); Q1 à Q12 **validées par délégation** (non confirmées explicitement). Conception **proposée** (migration `0002`, erreurs, contrat REST) : voir « Cycle de vie (tranche B) ».
- **Validé par l'Architecte** (2026-10-11), les deux écarts du Developer par rapport à l'esquisse de 0001 : voir « Schéma des tâches ».
- **Validé** (utilisateur, 2026-10-11, décisions PW1 à PW12 du backlog, recommandations du Product Owner retenues) : portail local sans authentification, jamais déployé ni exposé avant l'authentification; pages rendues par le serveur PHP appelant `TaskService` (confirmé techniquement par l'Architecte); aucune bibliothèque, CSS maison local, pas de CDN; dates `JJ/MM/AAAA`, horodatages Europe/Paris, durée en minutes; confirmation avant suppression; tâches terminées visibles par défaut avec filtre; message de confirmation avec lien pour `next_occurrence`; dépendances choisies dans une liste, refus des cycles en base; boutons de transition selon l'état, avertissement sans blocage avant réouverture; CSRF par formulaire + session minimale + contrôle d'`Origin` + en-têtes de sécurité + échappement; périodicité « tous les N unité », catégorie sans défaut, titre tel quel; deux tranches B1/B2. La **conception** qui en découle est **proposée** : voir « Portail web (tranches B1 et B2) ».

Les sections « Contrat REST » et « Contrat MCP » du squelette sont validées et implémentées; les autres sections « (proposé) » ci-dessous couvrant des points de cette liste (migrations, dépendances, PHP) ont le statut de la liste. Restent **proposés** ou **à vérifier** : tous les points « non vérifiés » AlwaysData, la conception du portail (décisions PW1 à PW12 validées, contrat B1/B2 proposé), la procédure de démarrage détaillée, les variables de configuration et la convention de tests (acceptées de fait par le Developer, sans validation explicite de l'utilisateur).

## Prérequis AlwaysData (vérifiés le 2026-10-10)

Sources : documentation officielle `help.alwaysdata.com`, pages ouvertes à cette date.

| Prérequis | Statut | Source / remarque |
|---|---|---|
| PHP : versions 4.4 à 8.5 « provisionnées » | Vérifié | `/en/docs/web-hosting/languages/php/`. La disponibilité effective d’une version précise (8.3, 8.4) sur le compte est à confirmer dans Environnement > PHP. |
| PHP exécuté en FastCGI derrière Apache; `.htaccess` possible | Vérifié | même page |
| Extensions `pdo_pgsql`, `pgsql`, `mbstring`, `json`, `curl` activées dans le `php.ini` fourni | Vérifié pour l’exemple PHP 8.2 | `/en/docs/web-hosting/languages/php/configuration/`; `php.ini` lisible dans `/home/[compte]/admin/config/php/php.ini`, extensions PECL installables. Autres versions de PHP non vérifiées. |
| Composer installé (`composer install`, `composer2 install`) | Vérifié | `/en/docs/web-hosting/languages/php/packages/` |
| PostgreSQL 16, 17, 18; hôte `postgresql-[compte].alwaysdata.net:5432`; pgBouncer sur 5433; accès via SSH/`psql` ou phpPgAdmin | Vérifié | `/en/docs/web-hosting/databases/postgresql/` |
| Accès SSH (`ssh-[compte].alwaysdata.net`, désactivé par défaut, à activer par utilisateur) | Vérifié de façon indirecte | Résumé d’une recherche sur `/en/docs/web-hosting/remote-access/ssh/`; page non ouverte directement. À reconfirmer avant le déploiement. |
| Endpoint HTTP POST/JSON (MCP sans SSE) servi par PHP/Apache | Déduit, non vérifié par un test | Rien dans la documentation ne l’interdit; à tester lors de la tranche de déploiement. |
| Réécriture d’URL (`.htaccess` vers un front controller) | **Partiellement vérifié** (2026-10-11) | `/en/docs/web-hosting/sites/http-servers/htaccess-file/` contient une section « URL rewriting » avec `RewriteEngine On`, `RewriteCond %{REQUEST_FILENAME} !-f`, `RewriteRule` (exemple à cible factice, aucun exemple de front controller PHP; le nom `mod_rewrite` n'y figure pas). Le comportement réel (règle vers `index.php`) n'est pas testé : **non vérifié** tant qu'aucun essai n'a été fait sur un compte. |
| Racine web pointant sur `public/` | **Non vérifié** (2026-10-11) | `/en/docs/web-hosting/sites/add-a-site/` dit seulement que l'ajout d'un site ne crée pas le répertoire racine (à créer par accès distant); elle ne dit pas si la racine peut être un sous-dossier tel que `public/`. Les pages `/en/sites/` et `/en/languages/php/` essayées renvoient 404. |
| Sessions PHP (cookie de session, fichiers) | **Partiellement vérifié** (2026-10-11) | Le `php.ini` d'exemple (PHP 8.2) de `/en/docs/web-hosting/languages/php/configuration/` contient `session.save_path = /home/<compte>/admin/tmp`, ce qui indique un stockage de sessions par fichiers prévu. L'extension `session` (incluse par défaut dans PHP, non listée par la page), le ramasse-miettes et les réglages de cookie ne sont pas documentés dans ce que l'Architecte a lu : **non vérifié** pour la version cible. |
| Variables d’environnement pour la configuration | **Non vérifié** | Non couvert par la page de configuration PHP. |
| Transmission de l’en-tête `Authorization` à PHP (FastCGI) | **Non vérifié** | Sans objet dans cette tranche; à vérifier avant la tranche d’authentification. |
| Délais maximaux d’exécution/requête | **Non vérifié** | Sans objet pour le squelette (réponses JSON courtes). |

Conséquences : aucun choix du squelette ne dépend d’un point non vérifié, car rien n’est déployé. Le front controller unique (`public/index.php`) fonctionne sans réécriture si l’on accepte des URL de la forme `/index.php/...` en secours; la réécriture reste à confirmer avant déploiement.

## Organisation du code (proposé)

```
public/index.php          Point d’entrée HTTP unique (front controller) : portail, REST, MCP
src/
  Http/                   Request, Response, Router minimal (traduction HTTP uniquement)
  Rest/                   Adaptateur REST (contrôleurs, format d’erreur)
  Mcp/                    Adaptateur MCP (JSON-RPC, outils)
  Portal/                 Adaptateur portail (pages); détail proposé : « Portail web (tranches B1 et B2) »
  Application/            Cas d’usage et règles métier, partagés par les trois adaptateurs
    Diagnostic.php        Cas d’usage du squelette (existant)
    TaskService.php       Cas d’usage des tâches (proposé, tranche A)
    TaskValidator.php     Validation/normalisation pure des entrées, sans base (proposé)
    TaskRepository.php    SQL PDO + traduction PDOException -> exceptions de domaine (proposé)
    DomainError.php + exceptions  InvalidTask, TaskNotFound, ... (proposé, voir « Erreurs de domaine »)
  Infrastructure/         PostgreSQL (PDO), chargement de la configuration
  Bootstrap.php           Composition : config -> connexion -> cas d’usage -> adaptateurs
bin/migrate               Exécuteur de migrations (CLI)
database/migrations/      Fichiers SQL ordonnés
config/                   Exemples de configuration (sans secret)
tests/Unit, tests/Http, tests/Integration
compose.yaml              PostgreSQL local (conteneur)
composer.json
.env.example              Exemple sans secret
```

Principes :
- Namespace racine `Actions\`, autoload PSR-4 de `src/` par Composer (sans paquet tiers).
- Dépendances dirigées vers l’intérieur : adaptateurs (`Rest`, `Mcp`, `Portal`) -> `Application` -> `Infrastructure` (via interface si un test l’exige, pas avant). Les adaptateurs ne contiennent ni règle métier ni SQL; ils valident la forme du transport et traduisent en appels de cas d’usage et en erreurs d’interface.
- Un seul point d’entrée HTTP, un routage par chemin : `/` portail, `/api/v1/*` REST, `/mcp` MCP. Les adaptateurs sont aussi appelables sans serveur web (objet `Request` en entrée), ce qui permet de tester sans HTTP réel.
- Les cas d’usage lèvent des exceptions de domaine ; chaque adaptateur les convertit (REST : code HTTP + corps d’erreur; MCP : erreur JSON-RPC ou résultat `isError`). Aucune règle de conversion métier n’est dupliquée : une table de correspondance par adaptateur seulement.

### Couche commune du squelette

`Application\Diagnostic` expose deux méthodes :
- `ping(): array` renvoie un résultat fixe `{"status":"ok","service":"actions"}`, sans accès base.
- `health(): array` appelle `ping()` puis exécute `SELECT 1` en PDO; renvoie `database` = `ok` ou lève `DatabaseUnavailable`.

Appels : REST `GET /api/v1/health` -> `health()`; MCP `ping` -> `ping()`; portail `GET /` -> `ping()` (page statique affichant « fonctionne » à partir du statut). Les trois passent donc par `Diagnostic` (critère 7).

## Contrat REST du squelette (validé le 2026-10-10, implémenté)

Versionnement : préfixe de chemin `/api/v1`. Une évolution compatible (champ ajouté) reste en v1; une rupture crée `/api/v2` à côté.

`GET /api/v1/health`
- 200, `Content-Type: application/json` : `{"status":"ok","database":"ok"}`
- 503 si la base est injoignable : `{"error":{"code":"service_unavailable","message":"Service indisponible."}}`. Aucun détail (hôte, utilisateur, message PDO) dans la réponse; le détail est journalisé côté serveur (`error_log`).
- Autre méthode sur ce chemin : 405 avec en-tête `Allow: GET`. Chemin inconnu : 404 `{"error":{"code":"not_found",...}}`. Erreur imprévue : 500 `{"error":{"code":"internal_error","message":"Erreur interne."}}`.

Format d’erreur REST commun : `{"error":{"code":"<snake_case>","message":"<français court>"}}`, extensible plus tard par `details` pour les erreurs de validation (400/422).

Tests écrits avant le code : 200 avec base up; 503 avec configuration pointant un port fermé (et corps sans le nom d’hôte ni le mot de passe); 405; 404.

## Contrat MCP du squelette (validé le 2026-10-10, implémenté)

- Transport : Streamable HTTP (spécification MCP du 2025-06-18 consultée le 2026-10-10; une révision plus récente peut exister, à contrôler à l’implémentation). Endpoint unique `POST /mcp`, sans session (pas de `Mcp-Session-Id`), réponses `application/json` uniquement, sans SSE.
- `GET /mcp` et `DELETE /mcp` : 405 avec `Allow: POST` (cas prévu par la spécification pour l’absence de flux SSE et de gestion de session).
- Authentification : **aucune** dans cette tranche, endpoint à ne lier qu’à `127.0.0.1`. Défense minimale : si un en-tête `Origin` est présent, il doit figurer dans une liste autorisée de la configuration (sinon 403), conformément à l’avertissement de sécurité de la spécification.
- Corps : un seul message JSON-RPC 2.0 par requête; les tableaux (batch) sont refusés (`-32600`).
- Versions de protocole acceptées : `2025-06-18`, `2025-03-26`. `initialize` renvoie la version demandée si elle est supportée, sinon la plus récente supportée.

| Message | Réponse |
|---|---|
| `initialize` | `result` : `protocolVersion`, `capabilities` `{"tools":{}}`, `serverInfo` `{"name":"actions","version":"<version>"}` |
| `notifications/initialized` (notification) | HTTP 202, sans corps |
| `tools/list` | un seul outil `ping`, `inputSchema` `{"type":"object","properties":{},"additionalProperties":false}` |
| `tools/call` `ping` | `result` : `{"content":[{"type":"text","text":"{\"status\":\"ok\",\"service\":\"actions\"}"}],"isError":false}` (résultat de `Diagnostic::ping()`) |

Erreurs (HTTP 200 avec objet JSON-RPC `error`, sauf mention) :
- JSON invalide : `-32700`, HTTP 400, `id` nul.
- Message non conforme JSON-RPC, batch, mauvais `Content-Type` : `-32600` (HTTP 400; 415 pour le type de contenu).
- Méthode inconnue : `-32601`.
- Outil inconnu ou arguments non vides pour `ping` : `-32602`.
- Erreur interne : `-32603`, message générique sans détail.
- `Origin` non autorisé : 403.

Dépend du cas d’usage : `ping` -> `Diagnostic::ping()`. Les futurs outils CRUD appelleront les mêmes cas d’usage que REST.

Alternative écartée : SDK PHP MCP tiers. Non évalué en détail (maturité, dépendances PSR, compatibilité hébergement non vérifiées); le protocole minimal du squelette (4 messages) s’écrit en environ 150 lignes. À réévaluer quand les outils CRUD arriveront.

## Contrat du portail

**Existant** (squelette) : `GET /` : 200 `text/html; charset=utf-8`, page HTML statique indiquant que l’application fonctionne, via `Diagnostic::ping()`; `PortalTest` (2 tests). **Proposé** : cette page est remplacée par le portail des tâches, voir « Portail web (tranches B1 et B2) » plus bas.

## Configuration (proposé)

- Lue depuis des variables d’environnement ; en local, un chargeur minimal (une trentaine de lignes, sans dépendance) lit un fichier `KEY=VALUE` dont le chemin vient de `ACTIONS_ENV_FILE` (défaut : `.env` à la racine, ignoré par Git). Les variables déjà définies priment.
- Variables : `APP_ENV` (`local`, `test`, `production`; **défaut `production` si absente**, choix le plus restrictif pour le garde-fou des migrations), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `MCP_ALLOWED_ORIGINS` (liste séparée par des virgules, vide par défaut), `TEST_DB_NAME` (défaut `actions_test`), `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` (utilisées par `compose.yaml` seulement).
- État au 2026-10-10 : implémenté par le Developer (existant, relu dans `src/Infrastructure/Config.php`, `compose.yaml`, `.env.example`); le comportement reste à valider par l'utilisateur.
- `.env.example` fourni, sans secret; `.env` et tout fichier de secrets dans `.gitignore`.
- **Proposé** (portail) : `PORTAL_ALLOWED_HOSTS` (noms d'hôte sans port, séparés par des virgules; défaut `127.0.0.1,localhost,[::1]`).
- Le mécanisme de configuration sur AlwaysData (variables d’environnement ou fichier hors racine web) n’est pas vérifié : à trancher à la tranche de déploiement.

## Données et migrations

- **Existant** : `database/migrations/` contient un README et `0001_tasks_and_dependencies.sql` (appliquée en local seulement).
- **Validé / existant** : fichiers SQL `NNNN_description.sql`, appliqués dans l’ordre numérique par `bin/migrate`. La tranche A n'ajoute aucune migration.
- Table de suivi `schema_migrations(version text primary key, checksum text not null, applied_at timestamptz not null default now())`, créée par `bin/migrate` lui-même avec `CREATE TABLE IF NOT EXISTS` (donc rejouable sans effet).
- Comportement : verrou consultatif PostgreSQL pendant l’exécution; chaque migration dans une transaction (DDL transactionnel de PostgreSQL) avec échec explicite et retour de code non nul; une migration déjà appliquée dont le checksum SHA-256 a changé fait échouer l’exécution (jamais de réécriture). Pas de migration « down » : correction par nouvelle migration, retour arrière par restauration de sauvegarde.
- Commandes : `php bin/migrate` (applique), `php bin/migrate status` (liste appliquées / en attente). Garde-fou : refus si `APP_ENV` n’est ni `local` ni `test`, sauf option explicite `--allow-env=<APP_ENV>`; aucune exécution sur un environnement opérationnel sans autorisation de l’utilisateur.
- Alternative écartée : Phinx ou Doctrine Migrations (dépendances lourdes pour un besoin de quelques dizaines de lignes; compatibilité hébergement non évaluée).
- **Existant** (relu dans `src/Infrastructure/Migrator.php`) : chaque fichier est exécuté par un unique `PDO::exec($sql)` dans une transaction. Conséquences : plusieurs instructions par fichier, corps de fonction `$$ ... $$` avec points-virgules internes et `CREATE TRIGGER` sont supportés (protocole de requête simple, sans découpage côté PHP; `ATTR_EMULATE_PREPARES=false` ne concerne que `prepare`). Interdits dans un fichier : `BEGIN`/`COMMIT`, `CREATE INDEX CONCURRENTLY`, tout ce qui ne tourne pas en transaction. Le verrou consultatif de session de `bin/migrate` suppose une connexion directe (port 5432, pas pgBouncer 5433 : non vérifié, à respecter pour la migration AlwaysData). Le nom doit respecter `^\d{4}_[a-z0-9_]+$`.

### Schéma des tâches (**existant** : migration 0001 livrée en local; décisions produit du 2026-10-10 **validées**)

**Validé par l'utilisateur (2026-10-10)** : suppression d'une tâche requise par d'autres refusée; périodicité = entier > 0 + unité (jour/semaine/mois/année), colonnes nulles ou renseignées ensemble; mono-utilisateur (ni table `users`, ni propriétaire); périodicité non nulle ⇒ date butée non nulle.

**Existant** : migration `0001_tasks_and_dependencies.sql` (une seule : table, liens, fonction et trigger atomiques). Le fichier fait foi; l'esquisse ci-dessous en reprend le contenu, corrigé des deux écarts validés :

**Écarts du Developer par rapport à l'esquisse initiale — validés par l'Architecte le 2026-10-11** (correction technique conforme aux règles produit déjà validées, pas une nouvelle décision produit) :
1. `tasks_recurrence_valid` avec `IS NOT NULL` sur les deux colonnes : **validé**. L'esquisse initiale était erronée (logique à trois valeurs : pour `(1, NULL)`, la seconde branche vaut `TRUE AND NULL` = `NULL`, et un `CHECK` évalué à `NULL` est accepté, donc une périodicité à moitié renseignée passait malgré la règle validée « ensemble ou nuls »).
2. Trigger : auto-dépendance rendue au `CHECK` et exclusion de l'ancienne version de la ligne en `UPDATE` : **validé**. Sans le premier, `(A,A)` serait refusé par le trigger sous le nom `task_dependencies_no_cycle` (A est atteignable depuis A), au lieu de `task_dependencies_no_self` : deux erreurs de domaine confondues. Sans le second, ré-aiguiller un lien existant serait refusé à tort. Effet de bord sans conséquence : le cas `(A,A)` ne prend pas le verrou consultatif. Note : la couche Application n'exécute jamais d'`UPDATE` de lien (ajout/retrait seulement); la branche `UPDATE` est une défense en profondeur. Les `UPDATE` multi-lignes de liens ne sont pas garantis exacts (les anciennes versions des autres lignes visées restent visibles) : ne pas en écrire.

Esquisse (le fichier SQL fait foi) :

```sql
CREATE TABLE tasks (
  id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  title               text        NOT NULL,
  category            text        NOT NULL,
  due_date            date,
  recurrence_interval integer,
  recurrence_unit     text,
  estimated_minutes   integer,
  description         text,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT tasks_title_not_blank CHECK (btrim(title) <> '' AND char_length(title) <= 255),
  CONSTRAINT tasks_category_valid  CHECK (category IN ('Pro', 'Perso')),
  CONSTRAINT tasks_recurrence_valid CHECK (
    (recurrence_interval IS NULL AND recurrence_unit IS NULL)
    OR (recurrence_interval IS NOT NULL AND recurrence_unit IS NOT NULL
        AND recurrence_interval > 0 AND recurrence_unit IN ('day','week','month','year'))),
  CONSTRAINT tasks_recurrence_needs_due_date CHECK (recurrence_interval IS NULL OR due_date IS NOT NULL),
  CONSTRAINT tasks_estimated_minutes_positive CHECK (estimated_minutes IS NULL OR estimated_minutes > 0)
);
CREATE INDEX tasks_due_date_idx ON tasks (due_date) WHERE due_date IS NOT NULL;

CREATE TABLE task_dependencies (
  task_id          bigint NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
  required_task_id bigint NOT NULL REFERENCES tasks (id) ON DELETE RESTRICT,
  PRIMARY KEY (task_id, required_task_id),
  CONSTRAINT task_dependencies_no_self CHECK (task_id <> required_task_id)
);
CREATE INDEX task_dependencies_required_idx ON task_dependencies (required_task_id);
-- + fonction task_dependencies_check_cycle() et trigger task_dependencies_no_cycle (ci-dessous)
```

Sens : la ligne `(task_id, required_task_id)` signifie « `task_id` requiert `required_task_id` ». Les contraintes sont nommées explicitement pour que l'application les reconnaisse. **Correction (2026-10-11)** : `PDOException::errorInfo` ne porte que SQLSTATE, code driver et message; le nom de contrainte n'y est pas un champ (les tests le vérifient dans le message, et le Developer a constaté que `CONSTRAINT_NAME` n'est pas exposé par PDO). L'application reconnaît donc la contrainte par **SQLSTATE + présence du nom connu dans le message** (`str_contains` sur une liste fermée de noms que nous avons choisis; le nom d'un identifiant n'est pas traduit par `lc_messages`), jamais en analysant le texte des messages. Exception : la contrainte de clé étrangère `task_dependencies_required_task_id_fkey` et `task_dependencies_task_id_fkey` portent le nom généré par défaut par PostgreSQL (non écrit dans 0001); un test d'intégration en verrouille le nom.

Choix de types :
- **`due_date` en `date`, pas `timestamptz`** : une date butée est un jour calendaire; `timestamptz` ajouterait une heure fictive et des décalages de fuseau (une échéance « le 12 » pourrait devenir le 11 ou le 13 selon le client) et rendrait l'arithmétique de périodicité mois/année ambiguë. Évolution compatible si une heure devient utile : colonne facultative `due_time` ajoutée. Hypothèse produit à confirmer (question 1).
- **Estimation en minutes entières** (`estimated_minutes integer > 0`) : triable, additionnable, sans ambiguïté de format; `interval` PostgreSQL écarté (sérialisation JSON/MCP plus lourde, sémantique jours/mois inutile). Les interfaces exposent des minutes entières.
- **Catégorie en `text` + `CHECK`**, pas de type `ENUM` : ajouter une valeur ne demande qu'une nouvelle migration sur le `CHECK`, sans les contraintes d'`ALTER TYPE`. Valeurs exactes `Pro` et `Perso` (sensibles à la casse; la normalisation, si elle existe, est dans la couche Application).
- **Périodicité** : `recurrence_interval` + `recurrence_unit` (valeurs anglaises stables en base, libellés français côté interfaces). La règle « la tâche se répète après sa date butée » est portée par la donnée; le calcul de la prochaine occurrence est un cas d'usage futur, hors schéma.
- Identifiants `bigint` identité : opaques pour les clients. `updated_at` est positionné par la couche Application (pas de trigger, règle visible au même endroit que les autres). Aucune colonne d'état dans 0001; ajoutée par la migration `0002` proposée (voir « Cycle de vie »).
- Suppression : `ON DELETE RESTRICT` côté tâche requise, donc `DELETE` d'une tâche requise par d'autres échoue (SQLSTATE `23503`, contrainte `task_dependencies_required_task_id_fkey`); `ON DELETE CASCADE` côté `task_id` : supprimer une tâche retire ses propres prérequis (elle n'en a plus besoin), ce qui ne viole aucune règle validée.

#### Refus des cycles indirects (proposé)

Règle métier unique, portée par **un trigger PL/pgSQL en base**; la couche Application ne réimplémente pas l'algorithme : elle traduit l'erreur en exception de domaine (`CyclicDependency`), commune à REST (422), MCP (erreur outil) et portail.

Trigger `BEFORE INSERT OR UPDATE OF task_id, required_task_id ON task_dependencies FOR EACH ROW`, fonction `task_dependencies_check_cycle()` :
1. `PERFORM pg_advisory_xact_lock(hashtext('actions.task_dependencies'))` : sérialise toute écriture de lien (verrou global de transaction, relâché au `COMMIT`; coût négligeable en mono-utilisateur, compatible pgBouncer en mode transaction).
2. Atteignabilité depuis `NEW.required_task_id` en suivant « requiert » : `WITH RECURSIVE reach(id) AS (SELECT NEW.required_task_id UNION SELECT d.required_task_id FROM task_dependencies d JOIN reach ON d.task_id = reach.id) SELECT 1 FROM reach WHERE id = NEW.task_id`. Si une ligne est trouvée, le nouveau lien fermerait un cycle. `UNION` (et non `UNION ALL`) garantit la terminaison même sur des données déjà cycliques.
3. Si cycle : `RAISE EXCEPTION '...' USING ERRCODE = '23514', CONSTRAINT = 'task_dependencies_no_cycle'` (le nom figure aussi dans le texte du message, seul endroit lisible via PDO). L'auto-dépendance (`NEW.task_id = NEW.required_task_id`) est rendue sans traitement au `CHECK` `task_dependencies_no_self` (même SQLSTATE, autre nom de contrainte); en `UPDATE`, l'ancienne version de la ligne est exclue du parcours.
4. Sinon `RETURN NEW`.

Compromis :
- **Trigger seul (retenu)** : garantit l'invariant quel que soit le chemin d'écriture (REST, MCP, portail, `psql`, futures importations), sans duplication. Coût : logique en PL/pgSQL, à tester en base réelle (donc tests d'intégration obligatoires), et un message d'erreur sans le chemin du cycle.
- **Application seule (écarté)** : lecture du graphe puis `INSERT` en deux temps; non sûr en concurrence (deux transactions voient chacune un graphe sans cycle, `A→B` et `B→A` sont validées ensemble) sauf à reprendre un verrou équivalent, et contournable par tout accès hors Application.
- **Les deux (écarté pour l'instant)** : doublon de règle. Si un message plus riche est voulu (chemin `A → B → A`), l'Application peut, *après* une erreur du trigger, relire le chemin pour l'afficher : affichage seulement, pas de décision.
- **Concurrence** : en `READ COMMITTED` (défaut PostgreSQL, à ne pas changer), chaque instruction prend un instantané neuf : après obtention du verrou, la requête de l'étape 2 voit les liens validés par la transaction concurrente, donc la seconde insertion échoue. En `REPEATABLE READ`/`SERIALIZABLE` l'instantané antérieur au verrou pourrait ne pas les voir : ne pas utiliser ces niveaux pour écrire des liens (à écrire dans les conventions du Developer). Un `INSERT` multi-lignes (`A→B, B→A` en une instruction) doit être refusé : les lignes précédentes de la même instruction sont visibles du trigger (fonction volatile); **existant** : couvert par `TaskSchemaTest` (deux et trois lignes), résultat rapporté par le Developer, non rejoué par l'Architecte. Risque d'interblocage : une seule clé de verrou, donc pas de cycle de verrous entre liens; un `DELETE` de tâche concurrent est arbitré par PostgreSQL via les clés étrangères.
- Performance : parcours borné par le nombre de tâches atteignables; sans objet à l'échelle d'un outil personnel. Index `task_dependencies_required_idx` utile aux recherches inverses (« qui requiert X ») et aux contrôles `RESTRICT`; la clé primaire couvre `task_id`.

Mapping d'erreurs SQL : voir « Couche commune des tâches » (section « Traduction des erreurs SQL »), qui remplace la version initiale de ce paragraphe (le doublon de lien est **validé idempotent**, plus de 409).

Compatibilité hébergement : PostgreSQL 16–18 (vérifié le 2026-10-10, voir tableau AlwaysData). Utilisés : `GENERATED ... AS IDENTITY`, CTE récursive, PL/pgSQL (inclus par défaut), advisory locks : tous disponibles dans PostgreSQL 16-18 (`CREATE TRIGGER ... EXECUTE FUNCTION` exige PostgreSQL 11 ou plus; la formulation initiale « depuis PostgreSQL 10 » était inexacte, sans effet pour 16-18); aucune extension. `hashtext()` est une fonction interne que l'Architecte ne sait pas documentée comme interface stable (non vérifié dans la documentation de PostgreSQL 16-18) : risque faible, une clé entière constante serait l'alternative via une future migration. **Non vérifié** : droit de créer une fonction/un trigger avec le rôle de base AlwaysData et comportement de pgBouncer; à tester sur une base AlwaysData jetable avant la tranche de déploiement (aucune migration sans autorisation de l'utilisateur).

Analyse d'impact (mise à jour 2026-10-11) : 0001 n'est appliquée qu'en local; aucune donnée de production. Consommateurs : `TaskSchemaTest` seul (et la couche commune à venir). REST, MCP et portail n'ont pas encore de contrat sur les tâches. Évolutions compatibles prévues : colonnes ajoutées par migration avec valeur par défaut (statut en tranche B, `due_time`), nouvelle valeur de catégorie par modification du `CHECK`. Condition de compatibilité imposée au Developer de la tranche A : les requêtes nomment toujours leurs colonnes (`INSERT (cols...)`, `SELECT cols...`, jamais `SELECT *` ni `INSERT` sans liste), afin que la colonne d'état de `0002` soit sans effet sur elles.

#### Tests d'intégration du schéma (proposé)

Base `actions_test` migrée par `bin/migrate` (donc la migration 0001 est testée sur base vide, et sa rejouabilité); chaque test isole ses données (transaction annulée ou `TRUNCATE tasks RESTART IDENTITY CASCADE`). Un test par règle, vérifiant le SQLSTATE et le nom de contrainte :
- tâche minimale valide; titre vide ou blanc, catégorie hors `Pro`/`Perso`, minutes `<= 0`, intervalle `<= 0`, unité inconnue : refusés.
- périodicité à moitié renseignée refusée; périodicité sans date butée refusée; date seule acceptée.
- auto-dépendance refusée; lien en double refusé; tâche requise inexistante refusée.
- cycles : `A→B, B→A`; indirect `A→B, B→C, C→A`; insertion multi-lignes formant un cycle; `UPDATE` d'un lien qui créerait un cycle; graphe en losange (sans cycle) accepté.
- suppression : tâche requise par une autre refusée (`23503`); tâche sans dépendant supprimée avec retrait de ses propres liens.
- concurrence déterministe, sans processus parallèle : connexion 1 `BEGIN` + insertion `A→B` non validée; connexion 2 avec `SET lock_timeout = '500ms'` tente `B→A` et doit échouer en `55P03` (le verrou sérialise); après `COMMIT` de la connexion 1, `B→A` échoue en `23514`.
- les tests de la couche Application vérifient seulement la traduction SQLSTATE -> exceptions de domaine.

## Couche commune des tâches (tranche A) — proposé, sauf mention « validé »

Aucun contrat REST/MCP/portail n'est défini ici : cette section fixe l'API interne `Application` et les erreurs de domaine, que les adaptateurs traduiront. Aucune migration, aucune dépendance, aucune modification du schéma.

### Organisation (proposé)

- `TaskService` : un cas d'usage par méthode publique, seul point d'entrée des adaptateurs. Orchestre validation puis dépôt.
- `TaskValidator` : pur (pas de base), testé en `tests/Unit`.
- `TaskRepository` : tout le SQL et la traduction `PDOException` -> exception de domaine. Classe concrète, sans interface (aucun test ne l'exige; tests contre PostgreSQL réel). Placé dans `Application` comme `Diagnostic` (qui utilise déjà PDO) pour ne pas faire dépendre `Infrastructure` des exceptions de `Application`; à rediscuter si un second stockage apparaît.
- Connexion : même `\Closure():\PDO` que `Diagnostic`; une transaction explicite par cas d'usage qui écrit, niveau `READ COMMITTED` (voir contrainte du trigger). Composition dans `Bootstrap` ; les adaptateurs REST/MCP/portail reçoivent `TaskService` plus tard.
- Entrées/sorties : tableaux PHP (comme `Diagnostic`), pas d'objet de transfert.

### Cas d'usage (proposé)

Forme d'une tâche en sortie (`Task`) :
`{id:int, title:string, category:"Pro"|"Perso", due_date:"YYYY-MM-DD"|null, recurrence:{interval:int, unit:"day"|"week"|"month"|"year"}|null, estimated_minutes:int|null, description:string|null, required_actions:[{id:int,title:string}], created_at:string, updated_at:string}`. Horodatages ISO 8601 UTC (`2026-10-11T08:30:00Z`). Les unités sont les codes anglais de la base; les libellés français relèvent des interfaces. `required_actions` trié par `id`.

| Méthode | Entrée | Sortie | Erreurs |
|---|---|---|---|
| `create(array $data)` | `title`, `category` obligatoires; `due_date`, `recurrence`, `estimated_minutes`, `description` facultatifs (absent = `null` = non renseigné) | `Task` créée (donc `id`) | `InvalidTask` |
| `get(int $id)` | id | `Task` | `TaskNotFound` |
| `list()` | aucune | `Task[]` triées `due_date` croissante (sans date en dernier), puis `id` | — |
| `update(int $id, array $changes)` | clés présentes = modifiées; `null` efface un champ facultatif; clé absente = inchangé | `Task` mise à jour | `TaskNotFound`, `InvalidTask` |
| `delete(int $id)` | id | `void` | `TaskNotFound`, `TaskIsRequired` |
| `addRequiredAction(int $taskId, int $requiredTaskId)` | ids | `Task` de `taskId` | `TaskNotFound`, `RequiredTaskNotFound`, `InvalidDependency`, `CyclicDependency` |
| `removeRequiredAction(int $taskId, int $requiredTaskId)` | ids | `Task` de `taskId` | `TaskNotFound` |

Règles transverses :
- `update` valide l'état **fusionné** (ancienne valeur + changements) : effacer `due_date` d'une tâche périodique est refusé (`recurrence_requires_due_date`); `recurrence: null` efface intervalle et unité ensemble. Lecture de la ligne `SELECT ... FOR UPDATE` dans la même transaction que l'écriture. `update` n'accepte ni `id`, ni `required_actions`, ni `created_at`/`updated_at`, ni (tranche B) l'état : clé inconnue = `InvalidTask` `field_unknown` (une faute de frappe ne doit pas être ignorée en silence, notamment côté MCP).
- `updated_at = now()` est positionné par l'UPDATE du dépôt (pas de trigger). Un `update` dont aucun champ ne change la valeur n'écrit pas et ne change pas `updated_at`. **Validé** (utilisateur, 2026-10-11, option (i)) : ajouter ou retirer un lien effectif modifie `updated_at` de la tâche `taskId`; un ajout idempotent ou le retrait d'un lien absent le laisse inchangé.
- `addRequiredAction` : ordre des contrôles fixe : `taskId` connu, `requiredTaskId` connu, puis `INSERT ... ON CONFLICT (task_id, required_task_id) DO NOTHING` (idempotence **validée** : le lien existant renvoie un succès, sans doublon; le trigger ne produit pas de faux cycle sur un lien existant, car un graphe valide est acyclique). L'auto-dépendance (`taskId = requiredTaskId`, tâche connue) est refusée **par le `CHECK`** puis traduite, sans prévérification en PHP (pas de règle dupliquée). `(A, A)` avec `A` inconnue renvoie `TaskNotFound`.
- `removeRequiredAction` : lien absent = succès (idempotent; **validé**, 2026-10-11).
- `delete` : `DELETE` direct, **en autocommit** (instruction unique donc atomique; **validé par l'Architecte le 2026-10-11**, écart (c) du Developer accepté : une transaction explicite n'apporterait rien); `23503` -> la liste des dépendants est lue ensuite (`id`, `title`) et `TaskIsRequired` est levée. Tolérance documentée : si un lien est retiré entre le refus et cette lecture, `dependents` peut être incomplet ou vide, le refus restant exact au moment où il a eu lieu. Aucune prévérification (la base arbitre aussi la concurrence). Identifiant inconnu (0 ligne) : `TaskNotFound` (**validé**, non idempotent). Les liens propres de la tâche partent par `ON DELETE CASCADE`.
- `list` : sans pagination (outil mono-utilisateur); filtres `status`/`category` prévus en tranche B (voir « Cycle de vie »), ajout facultatif compatible.

### Validation (`TaskValidator`, proposé)

Validation côté Application, fail-fast (première erreur). Le `CHECK` de la base reste le filet : il n'est jamais plus strict que le domaine. La duplication des règles `CHECK` / validateur est acceptée : la base ne peut pas fournir d'erreur distincte exploitable, et le domaine doit refuser avant d'écrire. Les erreurs `23514` sur `tasks_*` qui atteindraient quand même le dépôt sont des bogues du validateur : erreur interne (500 / `-32603`), journalisée, non traduite.

| Champ | Règle | `code` |
|---|---|---|
| `title` | obligatoire, chaîne UTF-8 valide sans octet NUL; refusée si vide ou **uniquement blancs** (`\s` et `\p{Z}`, donc espaces, tabulations, retours à la ligne, espace insécable : **validé** pour tabulations/retours, généralisation proposée); 255 caractères au plus (`mb_strlen`) | `title_blank`, `title_too_long`, `text_invalid` |
| `category` | exactement `Pro` ou `Perso`, sensible à la casse, aucune normalisation (assouplir plus tard est compatible, resserrer ne l'est pas) | `category_invalid` |
| `due_date` | `null` ou `YYYY-MM-DD` strict, date calendaire réelle (`checkdate`), année 0001-9999; **date passée autorisée (validé)** | `due_date_invalid` |
| `recurrence` | `null` ou objet `{interval, unit}`; `interval` entier > 0 et <= 2147483647; `unit` dans `day/week/month/year`; objet à moitié renseigné refusé; non nul sans `due_date` refusé | `recurrence_interval_invalid`, `recurrence_unit_invalid`, `recurrence_incomplete`, `recurrence_requires_due_date` |
| `estimated_minutes` | `null` ou entier > 0 et <= 2147483647 | `estimated_minutes_invalid` |
| `description` | `null` ou chaîne UTF-8 valide sans NUL (la chaîne vide est conservée telle quelle) | `text_invalid` |
| autre | type incorrect (ex. `title` non chaîne, `estimated_minutes` décimal ou chaîne) ; clé inconnue | `field_type_invalid`, `field_unknown` |

Les valeurs sont stockées **telles que fournies** (pas de `trim`) : **proposé, à valider** (voir questions).

### Erreurs de domaine (proposé)

`abstract DomainError extends \RuntimeException` porte `code()` (snake_case stable, contractuel), `message` (français court, sans détail technique) et `details()` (tableau, éventuellement vide). `DatabaseUnavailable` (existant) reste à part (503). Le corps d'erreur est donc produit **une seule fois**, par l'exception; les adaptateurs ne tiennent qu'une table de statut.

| Exception | `code` | `details` | REST | MCP |
|---|---|---|---|---|
| `InvalidTask` (un `code` par règle ci-dessus) | voir tableau de validation | `{field}` | 422 | `isError` |
| `TaskNotFound` | `task_not_found` | `{id}` | 404 | `isError` |
| `RequiredTaskNotFound` | `required_task_not_found` | `{required_task_id}` | 422 | `isError` |
| `InvalidDependency` (auto-dépendance) | `dependency_self` | `{task_id}` | 422 | `isError` |
| `CyclicDependency` | `dependency_cycle` | `{task_id, required_task_id}` | 409 | `isError` |
| `TaskIsRequired` | `task_is_required` | `{id, dependents:[{id,title}]}` | 409 | `isError` |
| `DatabaseUnavailable` (existant) | `service_unavailable` | — | 503 | `isError` |
| autre `Throwable` | `internal_error` | — | 500 | `-32603` |

Principe 409 / 422 : 422 = l'entrée est invalide en soi; 409 = l'entrée est correcte mais en conflit avec l'état courant des données. Le « critère 2 » du backlog (« exception de domaine distincte » par règle) est interprété comme **un `code` distinct par règle** au sein d'une même famille `InvalidTask`, et non une classe par règle (sept classes sans comportement différent); si une classe par règle est exigée, c'est un changement local sans effet sur les contrats.

Mapping futur (aucune règle dupliquée) :
- **REST** : corps `{"error":{"code","message","details"?}}` (format commun existant, `details` déjà prévu); statut = table ci-dessus, définie une seule fois dans `RestAdapter`.
- **MCP** : une erreur de domaine est un résultat d'outil `isError: true` dont le contenu texte est le **même JSON** `{"error":{...}}` que REST (permet au modèle de se corriger, conforme à la distinction de la spécification MCP entre erreur d'exécution d'outil et erreur de protocole). `-32602` reste réservé à l'outil inconnu et à des `arguments` qui ne sont pas un objet; `-32603` aux erreurs imprévues. Les schémas d'entrée des outils seront écrits avec le contrat MCP des tâches.
- **Portail** : mêmes exceptions, affichées sur la page; hors tranche.

### Traduction des erreurs SQL (dans `TaskRepository`, un seul endroit; proposé)

| SQLSTATE | Reconnaissance | Contexte | Résultat |
|---|---|---|---|
| `23514` | nom `task_dependencies_no_cycle` | ajout de lien | `CyclicDependency` |
| `23514` | nom `task_dependencies_no_self` | ajout de lien | `InvalidDependency` |
| `23503` | nom `..._required_task_id_fkey` | ajout de lien | `RequiredTaskNotFound` (course avec une suppression) |
| `23503` | nom `..._task_id_fkey` | ajout de lien | `TaskNotFound` (course) |
| `23503` | — | `DELETE` d'une tâche | `TaskIsRequired` (+ lecture des dépendants) |
| `23505` | clé primaire des liens | ajout de lien | ne se produit pas (`ON CONFLICT DO NOTHING`) |
| `55P03`, `40P01`, autres | — | tout | erreur interne générique |
| connexion impossible | — | tout | `DatabaseUnavailable` |

### Tests (proposé)

`tests/Unit/TaskValidatorTest` (sans base, une assertion de `code` par règle, dont : titre de tabulations seules, de `\n` seuls, mélange, espace insécable; date passée acceptée; `due_date` `2026-02-30` refusée; entier hors `int4`; octet NUL) et `tests/Integration/TaskServiceTest` (PostgreSQL réel, base `actions_test` migrée par `Migrator` dans `setUp`, données isolées par `TRUNCATE tasks RESTART IDENTITY CASCADE`; `TaskSchemaTest` supprime les tables : chaque classe remigre dans son `setUp`). Cas : critères 1 à 6 du backlog, plus idempotence de l'ajout (aucun doublon, `updated_at` inchangé), retrait idempotent, mise à jour partielle, `null` qui efface, effacer `due_date` d'une périodique refusé, clé inconnue, aucune écriture après refus (comptage de lignes), cycles A→B→A et A→B→C→A sans lien créé, suppression refusée avec dépendants complets puis réussie après retrait du lien. `updated_at` : forcer d'abord `updated_at` dans le passé par SQL, puis vérifier qu'il avance (déterministe, sans `sleep`).

### Dépendance et hébergement

Aucune dépendance nouvelle. Fonctions PHP utilisées : `mbstring` (déjà en contrainte, listé activé dans l'exemple PHP 8.2 AlwaysData, **non vérifié** pour la version cible) et PCRE `/u` (noyau PHP). SQL : `ON CONFLICT`, `FOR UPDATE`, déjà couverts par PostgreSQL 16-18 (vérifié le 2026-10-10).

## Tranche « dépendances entre tâches » : REST des actions requises

Statuts : **validé** = décision de l'utilisateur transmise le 2026-10-11 (REST seul et local; ajout/retrait unitaire d'un lien en sous-ressource; cycle refusé en 409 `dependency_cycle` sans chemin; retrait d'un lien absent et ajout d'un lien existant idempotents). Ont aussi été validés ensuite (2026-10-11) : `updated_at` modifié par un lien effectif (option (i)) et les codes `task_not_found`, `dependency_self`, `required_task_not_found`, `task_is_required` avec leurs statuts (404, 422, 422, 409). Le reste de la section est **proposé** (ou livré, voir le code), non opposable au Developer tant que non validé; `Allow` de `/tasks/{id}` est désormais `GET, PATCH, DELETE` (voir la tranche CRUD REST).

### Relecture de la tranche A (2026-10-11)

Conforme, sans reprise de migration : schéma et trigger de 0001, ordre des contrôles de `addRequiredAction`, idempotence (`ON CONFLICT DO NOTHING` : le trigger s'exécute avant la détection de conflit mais ne voit pas de faux cycle, le graphe étant acyclique; le `CHECK` d'auto-dépendance passe avant le conflit), noms de contraintes verrouillés par `TaskSchemaTest` (dont les deux clés étrangères), niveau d'isolation imposé par `TaskRepository::transaction()`. Écarts et points à connaître :

1. **Documentation périmée** (état du dépôt, « PostgreSQL 10 », test multi-lignes « à confirmer ») : corrigée ci-dessus.
2. **`delete` en autocommit** : accepté (voir « Cas d'usage »).
3. **`PDOException` imprévues relancées** par le dépôt (point (d) du Developer) : conforme à la conception; l'adaptateur REST les convertit (voir « Erreurs »). Aucune ne doit sortir de `RestAdapter`.
4. **Concurrence au niveau service non testée** : la sérialisation par verrou est testée au niveau SQL (`testConcurrentOppositeLinksAreSerialisedThenRejected`); les traductions `23503` de course (suppression concurrente d'une tâche pendant l'ajout) ne sont pas testables de façon déterministe, acceptées non testées. Aucun interblocage identifié à la lecture (une seule clé de verrou consultatif; `UPDATE tasks` de `touch` ne conflit pas avec les verrous de clé étrangère); `40P01`/`55P03` restent des erreurs internes.
5. **AlwaysData, non vérifié** (page PostgreSQL relue le 2026-10-11 : elle ne dit rien sur le droit de créer fonction ou trigger, sur le mode de pooling de pgBouncer ni sur les verrous consultatifs). Le verrou est de portée transaction et `SET TRANSACTION` est émis dans la transaction : sûrs en pooling par session ou par transaction. À tester sur base jetable avant déploiement; sans objet pour cette tranche (local).
6. **Écart de périmètre** : le critère 1 (lire A) et le critère 6 (supprimer B) du backlog exigent `GET /tasks/{id}` et `DELETE /tasks/{id}`, qui relèvent de l'élément P2 « CRUD REST ». Proposé : cette tranche livre ces deux routes (contrat ci-dessous, repris tel quel par P2) et rien d'autre du CRUD; les tâches de test sont créées par `TaskService`, pas par REST. **À valider par l'utilisateur.**

### Routes (proposé)

Préfixe `/api/v1` (versionnement du squelette). Corps de réponse en `application/json`; `{id}` et `{requiredId}` : entiers positifs.

| Méthode et chemin | Cas d'usage | Succès | Erreurs |
|---|---|---|---|
| `PUT /api/v1/tasks/{id}/required-actions/{requiredId}` | `addRequiredAction` | 200, `Task` de `{id}` (lien présent; identique que le lien soit nouveau ou existant) | 404 `task_not_found`, 422 `required_task_not_found`, 422 `dependency_self`, 409 `dependency_cycle` |
| `DELETE /api/v1/tasks/{id}/required-actions/{requiredId}` | `removeRequiredAction` | 200, `Task` de `{id}` (lien absent ensuite, qu'il ait existé ou non) | 404 `task_not_found` |
| `GET /api/v1/tasks/{id}` | `get` | 200, `Task` | 404 `task_not_found` |
| `DELETE /api/v1/tasks/{id}` | `delete` | 204, sans corps | 404 `task_not_found`, 409 `task_is_required` |

Choix de forme : `PUT` sur l'URL du lien car il est naturellement idempotent et ne demande ni corps ni validation de JSON (donc aucune règle de forme à dupliquer côté MCP). Alternative écartée : `POST /tasks/{id}/required-actions` avec `{"required_task_id":N}`, qui oblige à valider un corps et, pour distinguer 201/200, à changer la sortie de `TaskService`. Pas de `GET .../required-actions` : la `Task` contient déjà `required_actions` (`[{id,title}]` triées par `id`). Corps et `Content-Type` des requêtes `PUT`/`DELETE` sont ignorés.

Sortie `Task` : l'objet défini en « Cas d'usage » (`id`, `title`, `category`, `due_date`, `recurrence`, `estimated_minutes`, `description`, `required_actions`, `created_at`, `updated_at`), directement comme corps, sans enveloppe.

Ordre des contrôles de `PUT` (celui du service, à ne pas modifier) : identifiants du chemin, `task_not_found`, `required_task_not_found`, `dependency_self`, `dependency_cycle`. Donc `PUT /tasks/7/required-actions/7` : 404 si 7 n'existe pas, sinon 422 `dependency_self`. `DELETE` d'un lien dont `{requiredId}` n'existe pas : 200 (conséquence du retrait idempotent; le service ne contrôle pas `{requiredId}`).

Identifiants du chemin : motif `[1-9][0-9]*` et valeur tenant dans un entier PHP 64 bits (contrôle par `filter_var(FILTER_VALIDATE_INT)`, jamais un simple `(int)` qui sature); sinon 404 `not_found` (ressource inexistante, pas une erreur de domaine). Un chemin connu avec une méthode non prévue : 405 `method_not_allowed` avec `Allow` (`PUT, DELETE` pour le lien; `GET, PATCH, DELETE` pour la tâche depuis la tranche CRUD REST, `GET, DELETE` auparavant). Un `{requiredId}` valide mais inexistant dans `PUT` reste 422 (cohérent avec la table des erreurs de domaine, valable aussi pour MCP) plutôt que 404.

### Erreurs (proposé)

Format commun existant `{"error":{"code","message","details"?}}`; `message` en français court (celui de l'exception); `details` = `details()` de l'exception, absent s'il est vide.

| `code` | HTTP | Origine | `details` |
|---|---|---|---|
| `task_not_found` | 404 (**validé**) | `TaskNotFound` | `{id}` |
| `required_task_not_found` | 422 (**validé**) | `RequiredTaskNotFound` | `{required_task_id}` |
| `dependency_self` | 422 (**validé**) | `InvalidDependency` | `{task_id}` |
| `dependency_cycle` | 409 | `CyclicDependency` (**validé** : sans chemin du cycle) | `{task_id, required_task_id}` |
| `task_is_required` | 409 (**validé**) | `TaskIsRequired` | `{id, dependents:[{id,title}]}` |
| `not_found`, `method_not_allowed` | 404, 405 | routage (existant) | — |
| `service_unavailable` | 503 | `DatabaseUnavailable` (existant), journalisée | — |
| `internal_error` | 500 | tout autre `Throwable` | — |

Conversion des exceptions, **un seul endroit** (`RestAdapter`) : `DomainError` -> statut par table `code` -> statut, corps construit à partir de l'exception; `DatabaseUnavailable` -> 503; tout autre `Throwable`, en particulier toute `PDOException` relancée par le dépôt (`55P03`, `40P01`, `23xxx` inattendues dont `tasks_*` qui seraient un bogue du validateur, erreurs de syntaxe, etc.) -> 500 `internal_error` « Erreur interne. ». Journal (`error_log`) : classe, SQLSTATE (`errorInfo[0]`) pour une `PDOException`, et message; la réponse n'en contient rien (ni SQL, ni noms de table, ni valeurs). Un code inconnu de la table de statut est une erreur interne (500), jamais un 4xx par défaut. Le dépôt ne convertit rien de plus : la traduction SQLSTATE -> domaine reste dans `TaskRepository`, celle domaine -> HTTP dans `RestAdapter`.

### Effet sur `updated_at` (tranché : option (i), **validé** le 2026-10-11)

Décision : un ajout ou retrait effectif de lien avance `updated_at` de la tâche `{id}`. État **existant** et conforme : `TaskService::addRequiredAction`/`removeRequiredAction` appellent `touch()` après un changement effectif, et `TaskServiceTest::testEffectiveLinkChangesAdvanceUpdatedAtOfTheTask` le fixe; aucun changement de code. L'option (ii) est écartée. Comparatif conservé pour mémoire. Dans les deux options, un ajout idempotent ou un retrait d'un lien absent ne modifie jamais `updated_at`, et seule la tâche `{id}` (jamais `{requiredId}`) est concernée.

| | (i) le lien modifie la tâche | (ii) seuls les champs comptent |
|---|---|---|
| `updated_at` renvoyé par `PUT`/`DELETE` du lien | avance | inchangé |
| Code | aucun changement | retirer les deux appels à `touch()` (et `touch()` si plus utilisé) |
| Tests | aucun changement | inverser `testEffectiveLinkChangesAdvanceUpdatedAtOfTheTask` |
| Effet produit | un tri par « dernière modification » remonte une tâche dont seul un lien a changé | les liens sont invisibles dans l'horodatage; il faudra une autre information pour savoir qu'ils ont changé |
| Contrat REST | inchangé (seule la valeur du champ diffère) | inchangé |
| Concurrence | `touch` prend un verrou de ligne sur la tâche, sans conséquence en mono-utilisateur | aucun verrou de ligne supplémentaire |

Aucune migration, aucun contrat REST ne dépend du choix; plus de blocage du critère 9 du backlog.

### Impact sur le squelette et organisation du code (proposé)

- `RestAdapter` : le constructeur reçoit aussi `TaskService`; `handle()` passe de l'égalité de chemin à un routage par motifs (méthode + chemin) et conserve la conversion d'erreurs. Les chemins sans route restent 404, `health` est inchangé (200/503/405).
- Nouvelle classe `Rest\TaskController` : traduit requête -> appel de `TaskService` -> `Response` (aucune règle métier, aucun SQL); `RestAdapter` garde le routage et la table d'erreurs. Séparation proposée dès maintenant pour que P2 (CRUD) ajoute des méthodes sans grossir `RestAdapter`; alternative acceptable : tout dans `RestAdapter` jusqu'à P2.
- `Bootstrap::kernel()` : construit `taskService($config)` et le passe à `RestAdapter` (la connexion du dépôt est paresseuse : `health` n'ouvre pas de connexion supplémentaire). `Kernel`, `Request`, `Response` : inchangés (`new Response(204)` sans corps suffit; `Request` n'a besoin ni de corps ni de paramètres de requête pour cette tranche).
- `TaskService`, `TaskRepository`, erreurs de domaine, schéma : inchangés, sauf option (ii) ci-dessus. Aucune migration, aucune dépendance.
- Consommateurs existants : aucune route existante ne change (ajout seulement); `RestTest` reste valable (`/api/v1/nope` toujours 404). MCP et portail non concernés : ils appelleront les mêmes cas d'usage et réutiliseront le même corps d'erreur (voir « Erreurs de domaine »).
- Tests : `HttpTestCase::call()` fixe une base injoignable (port 1), donc inutilisable pour les routes de tâches. Proposé : nouvelle classe sous `tests/Integration/` (PostgreSQL réel, suite `DbTestCase`, base remigrée comme `TaskServiceTest`) qui construit `Bootstrap::kernel($this->config)` et appelle `Kernel::handle(new Request(...))`. Cas, un test par ligne : critères 1 à 8 du backlog (ajout puis lecture; cycles A→B→A et A→B→C→A en 409 sans lien créé; auto-dépendance 422; deux 404/422 distincts; ajout deux fois; suppression refusée 409 avec dépendants puis réussie en 204 après retrait; retrait existant et absent en 200); forme exacte du corps d'erreur; identifiant de chemin invalide (`0`, `abc`, `99999999999999999999`) en 404 `not_found`; 405 avec `Allow`; erreur imprévue : un `TaskService` dont le dépôt lève une `PDOException` de SQLSTATE `XX000` donne 500 `internal_error` sans fuite (aucun SQLSTATE ni message dans le corps) et une connexion refusée donne 503.

### À faire valider avant implémentation

1. Les deux routes `GET`/`DELETE /tasks/{id}` dans cette tranche (écart de périmètre n° 6).
2. `PUT` sur l'URL du lien (au lieu de `POST` avec corps), réponses 200 avec la `Task`, 204 pour la suppression d'une tâche.
3. Statuts : 422 `required_task_not_found` et `dependency_self`, 404 `not_found` pour un identifiant de chemin non entier.
4. `DELETE` d'un lien dont la tâche requise n'existe pas : 200.
5. Séparation `RestAdapter` / `TaskController`.
6. `updated_at` : **tranché**, option (i) validée le 2026-10-11 (voir plus haut).

## Tranche « CRUD REST » : `POST`, liste et `PATCH` des tâches

Statuts (rédigé le 2026-10-11) : **existant** = présent dans le dépôt (relu : `RestAdapter`, `TaskController`, `Request`, `Response`, `TaskService`, `TaskValidator`, `RestTasksTest`); **validé** = décisions de l'utilisateur du 2026-10-11 (BACKLOG, « Décisions validées (CRUD REST) ») : `PATCH` partiel (`null` efface, absent inchangé); liste sans option, triée date butée croissante (sans date en dernier) puis `id`; clé inconnue ou non modifiable (`id`, `required_actions`, `created_at`, `updated_at`) en 422 `field_unknown`; titre stocké tel quel; titre <= 255 caractères; estimation en minutes entières; codes `task_not_found`, `dependency_self`, `required_task_not_found`, `task_is_required` (ces quatre passent de « proposé » à **validé** dans la section précédente); `DELETE` d'un `id` inconnu en 404 non idempotent; **validé aussi** (utilisateur, 2026-10-11, transmis par la session principale) : le contrat dans son ensemble, dont 400 `invalid_json` contrôlé avant le 404 d'un `PATCH` et `Location` sur le 201; `null` explicite sur `title`/`category` = 422 `field_type_invalid` (absent au `POST` : `title_blank` / `category_invalid`); `Content-Type` non contrôlé; chaîne de requête ignorée sur `GET /tasks`. Les mentions « (proposé) » plus bas dans cette section valent donc **validé**, sauf la taille du corps (aucune limite applicative; `post_max_size` non vérifié sur AlwaysData).

**Implémenté** (Developer) : `POST /tasks`, `GET /tasks`, `PATCH /tasks/{id}` sont livrés; 271 tests OK; appel HTTP réel fait sur la base de test (rapport du Developer, non rejoué par l'Architecte). Les cas d'usage `create`, `list`, `update` (tranche A) sont inchangés.

### Contrat

Versionnement : `/api/v1`, ajout seulement (routes nouvelles, aucune route existante modifiée). Corps JSON UTF-8; `Content-Type: application/json` en réponse. Sortie `Task` : objet défini en « Cas d'usage », sans enveloppe. Format d'erreur : `{"error":{"code","message","details"?}}` (existant, `details` absent s'il est vide).

**`POST /api/v1/tasks`** (cas d'usage `create`)
- Corps : objet JSON. Obligatoires : `title` (chaîne, 1 à 255 caractères, pas uniquement des blancs, stockée telle quelle), `category` (`"Pro"` ou `"Perso"`, casse exacte). Facultatifs, absent ou `null` = non renseigné : `due_date` (`"YYYY-MM-DD"`, date réelle, passée autorisée), `recurrence` (`{"interval":int>0,"unit":"day"|"week"|"month"|"year"}`, exige `due_date`), `estimated_minutes` (entier > 0, en minutes), `description` (chaîne). Toute autre clé, dont `id`, `required_actions`, `created_at`, `updated_at` : 422 `field_unknown`.
- 201, corps `Task`, en-tête `Location: /api/v1/tasks/{id}` (proposé). Tous les champs facultatifs absents valent `null`; `required_actions` vaut `[]`.
- Erreurs : 400 `invalid_json`; 422 (voir « Codes de validation »); 500 / 503.

**`GET /api/v1/tasks`** (cas d'usage `list`)
- Aucun paramètre ni option : une chaîne de requête éventuelle est ignorée (validé : « sans option »; pas d'erreur sur un paramètre inconnu, pour ne pas figer un comportement que filtres et pagination futurs devront changer).
- 200, corps : tableau JSON de `Task`, trié par `due_date` croissante, tâches sans date à la fin, puis `id` croissant. Aucune tâche : 200 et `[]`. Le tri est fait par `TaskRepository::findAll` (existant) : l'adaptateur ne retrie pas.

**`PATCH /api/v1/tasks/{id}`** (cas d'usage `update`)
- Corps : objet JSON, mêmes champs et mêmes règles que `POST`, tous facultatifs. Clé présente : champ modifié; `null` : champ facultatif effacé (`due_date`, `recurrence`, `estimated_minutes`, `description`); clé absente : inchangé. `title` et `category` ne sont pas effaçables (`null` : 422 `field_type_invalid`, comportement existant du validateur, relu : `text()` et `category()` refusent un non-chaîne; à `POST`, un `title`/`category` absent donne `title_blank` / `category_invalid`, un `null` explicite `field_type_invalid`). La validation porte sur l'état fusionné : effacer `due_date` d'une tâche périodique, ou donner `recurrence` à une tâche sans date butée, est refusé (`recurrence_requires_due_date`). `{}` est un corps valide : no-op.
- 200, corps `Task` à jour. Modification sans effet (valeurs identiques) : 200 et `updated_at` inchangé; modification effective : `updated_at` avance, `created_at` jamais (existant).
- Erreurs : 400 `invalid_json`; 404 `task_not_found`; 422; 405; 500 / 503.
- `PUT /tasks/{id}` (remplacement complet) n'existe pas : 405.

**Déjà existants, inchangés** : `GET /tasks/{id}` (200 / 404), `DELETE /tasks/{id}` (204 / 404 `task_not_found` non idempotent / 409 `task_is_required`), `PUT` et `DELETE /tasks/{id}/required-actions/{requiredId}`. Les liens ne se gèrent que par cette sous-ressource (validé) : `required_actions` dans un corps `POST`/`PATCH` est 422 `field_unknown`.

### Codes d'erreur ajoutés ou précisés

| Situation | HTTP | `code` | `details` |
|---|---|---|---|
| Corps vide, JSON invalide, ou JSON valide qui n'est pas un objet (tableau, chaîne, nombre, `null`) | 400 | `invalid_json` (**proposé**), message « Le corps doit être un objet JSON. » | — (aucun détail de l'analyseur) |
| Règle de validation violée (`InvalidTask`) | 422 | le `code` de la règle | `{field}` |
| Clé inconnue ou non modifiable | 422 | `field_unknown` (**validé**) | `{field}` (`recurrence.<clé>` pour une clé inconnue de `recurrence`) |
| Identifiant inconnu (`PATCH`, `GET`, `DELETE`) | 404 | `task_not_found` (**validé**) | `{id}` |
| Identifiant de chemin non entier positif | 404 | `not_found` | — |
| Méthode non prévue sur un chemin connu | 405 | `method_not_allowed`, en-tête `Allow` | — |
| Base injoignable | 503 | `service_unavailable` | — |
| Toute autre exception, dont `PDOException` | 500 | `internal_error`, « Erreur interne. » | — : ni SQL, ni SQLSTATE, ni trace, ni valeur; détail au journal (`error_log`) |

Codes de validation (existants, `TaskValidator`, tous en 422 avec `details.field`) : `title_blank`, `title_too_long`, `text_invalid`, `category_invalid`, `due_date_invalid`, `recurrence_interval_invalid`, `recurrence_unit_invalid`, `recurrence_incomplete`, `recurrence_requires_due_date`, `estimated_minutes_invalid`, `field_type_invalid`, `field_unknown`. Les `code` ne sont jamais réécrits par l'adaptateur. `InvalidTask` est un `DomainError` : il lui faut une entrée **422 pour toute instance d'`InvalidTask`** dans la conversion d'`RestAdapter`. Comme les `code` de validation sont une famille ouverte, la table `DOMAIN_STATUS` (par `code`) ne convient pas : conversion par classe d'abord (`InvalidTask` -> 422), puis par table pour les autres. Si une classe d'erreur sans statut arrive, c'est 500 (comportement existant).

**405 et `Allow`** (le statut et le corps `method_not_allowed` sont existants, les valeurs de `Allow` nouvelles) :

| Chemin | `Allow` |
|---|---|
| `/api/v1/tasks` | `GET, POST` |
| `/api/v1/tasks/{id}` | `GET, PATCH, DELETE` (remplace `GET, DELETE`) |
| `/api/v1/tasks/{id}/required-actions/{requiredId}` | `PUT, DELETE` (inchangé) |

`HEAD` et `OPTIONS` ne sont pas gérés : 405 (pas de CORS dans cette tranche, API locale sans navigateur).

### Corps de requête et ordre des contrôles (proposé)

- Décodage : `json_decode($body, false, 32)` avec `JSON_THROW_ON_ERROR` ou équivalent; un objet JSON (`stdClass`) est exigé, ce qui distingue `{}` (valide) de `[]` (400). L'objet est ensuite converti en tableau associatif pour `TaskService` (les sous-objets, dont `recurrence`, sont convertis de même; un `recurrence` fourni sous forme de liste JSON reste une liste PHP et est donc refusé par le validateur existant (**existant**, relu le 2026-10-11) : `[]` donne 422 `recurrence_incomplete`, `[1,"day"]` donne 422 `field_unknown` avec `details.field` = `recurrence.0`; ce n'est pas `field_type_invalid`. Le statut (422) et l'absence d'écriture sont seuls contractuels pour ce cas; l'adaptateur ne le détecte pas lui-même, pour ne pas dupliquer de règle). Un objet avec une clé en double : la dernière gagne (comportement de PHP, accepté). `Content-Type` n'est pas contrôlé (cohérent avec les routes existantes qui ignorent le corps); à resserrer plus tard serait une rupture : décision proposée, voir question 3.
- Ordre pour `PATCH` : chemin (`not_found`), méthode (405), **corps JSON (400)**, puis `TaskService::update` qui contrôle l'existence (404) avant la validation (422) (existant, point (b) de la tranche A). Donc `PATCH /tasks/999` avec un corps invalide : 400; avec un JSON valide mais un champ faux : 404. Pour `POST` : corps (400), puis validation (422).
- Le validateur ne voit que des types JSON : les nombres décimaux (`1.5`) arrivent en `float`, les gros entiers au-delà de `PHP_INT_MAX` en `float` aussi, tous deux refusés par `estimated_minutes_invalid` / `field_type_invalid` selon le champ (comportement existant, tests à écrire).
- Taille du corps : aucune limite applicative; limites de PHP (`post_max_size`) valables; non vérifié sur AlwaysData.

### Organisation du code (proposé, cohérente avec l'existant)

- `RestAdapter` (existant) : garde le routage par motifs, `id()`, les réponses 404/405 et la conversion unique des erreurs. Ajouts : route exacte `^/api/v1/tasks$` (`GET`, `POST`), `PATCH` sur `/tasks/{id}`, 422 pour `InvalidTask`. Le seul endroit où un statut HTTP est choisi pour une erreur de domaine reste `RestAdapter`. Le corps d'erreur `invalid_json` est produit via `RestAdapter::error()` (existant, public), pas par une exception de domaine : c'est une erreur de transport (le MCP aura la sienne, `-32700`), pas une règle métier partagée.
- `TaskController` (existant) : gagne `create(Request)`, `list()`, `update(int $id, Request)`. Le décodage JSON du corps y est fait par une méthode privée (ou une petite classe `Rest\JsonBody` si une troisième route en a besoin) qui renvoie un tableau ou la réponse 400. Aucune règle de validation de champ dans l'adaptateur : il ne vérifie que la forme du transport (corps = objet JSON). Les types, longueurs, énumérations restent dans `TaskValidator`, partagés avec MCP et portail.
- `Request`, `Response`, `Kernel`, `Bootstrap`, `TaskService`, `TaskValidator`, `TaskRepository`, erreurs de domaine : **inchangés**. `Request::$body` et `Response::json` (avec en-têtes) existent déjà; `Response::json(200, [])` produit `[]` (le tableau vide), pas `{}`.
- Aucune classe de validation de corps séparée, aucun schéma JSON, aucune dépendance.

### Impact (analyse)

- **Schéma : aucun.** Pas de migration. **Dépendances : aucune.** Fonctions PHP : `json_decode` (ext-json, déjà en contrainte).
- **Consommateurs** : `RestTasksTest::testWrongMethodsGive405WithAllowHeader` (ligne 241) attend `Allow: GET, DELETE` sur `/tasks/{id}` : à mettre à jour en `GET, PATCH, DELETE` (conséquence directe et voulue de l'ajout de `PATCH`, pas une rupture d'API : l'en-tête `Allow` décrit les méthodes permises). Il attend aussi, selon sa ligne 289, `GET` sur `health` : inchangé. `RestTasksTest` ligne 229 attend 404 sur `/api/v1/tasks` (avec trois autres chemins sans route) : `/api/v1/tasks` devient une route, donc ce chemin sort de la liste (les trois autres restent 404).
- **Rupture** : aucune. Le 405 sur `/tasks/{id}` en `PATCH` devient un succès; aucun client réel (local seulement). MCP et portail non concernés : ils réutiliseront `TaskService` et les mêmes `code` d'erreur.
- **Sécurité** : toute validation côté serveur (cas d'usage); requêtes préparées (existant); aucune authentification (local `127.0.0.1` seulement, requise avant exposition, élément séparé); les erreurs 500 et 503 ne révèlent ni SQL, ni SQLSTATE, ni nom de table; une valeur invalide n'est jamais répétée dans `message` ni `details` (seul `field` l'est).

### Tests à écrire avant le code (proposé)

Suite d'intégration (PostgreSQL réel, comme `RestTasksTest`; nouvelle classe ou extension de celle-ci) :
1. `POST` minimal : 201, `Location`, champs facultatifs `null`, `required_actions` `[]`, `created_at` = `updated_at` non vides; `POST` complet : valeurs renvoyées identiques; relecture par `GET /tasks/{id}` identique.
2. `POST` invalide, un test par code de validation avec `details.field` attendu, dont : titre `"   "`, titre de 256 caractères (et 255 accepté), titre `"  a  "` conservé tel quel, catégorie `"pro"`, `due_date` `2026-02-30`, `estimated_minutes` `0`, `1.5`, `"10"`, `recurrence` sans date butée, `recurrence` à moitié renseignée, `recurrence` en liste JSON (`[]` : `recurrence_incomplete`; `[1,"day"]` : `field_unknown`, `recurrence.0`; dans les deux cas 422 et rien créé), clé inconnue, `id`/`required_actions`/`created_at`/`updated_at` fournis (`field_unknown`); après chaque refus le nombre de tâches est inchangé.
3. 400 `invalid_json` : corps vide, `{`, `[]`, `"x"`, `null`, `123`; aucune tâche créée; le corps ne contient aucun texte de l'analyseur JSON.
4. `GET /tasks` : `[]` sur base vide; tri : dates différentes, deux tâches de même date (ordre `id`), tâches sans date après les datées; une chaîne de requête inconnue est ignorée.
5. `PATCH` : un champ modifié, autres inchangés; `null` efface chacun des quatre champs facultatifs; `{}` : 200 et `updated_at` inchangé; valeurs identiques : idem; modification effective : `updated_at` avance (méthode de `TaskServiceTest` : forcer d'abord `updated_at` dans le passé) et `created_at` inchangé; effacer `due_date` d'une périodique : 422 `recurrence_requires_due_date`, tâche inchangée; clés non modifiables : 422 `field_unknown`, tâche inchangée; id inconnu : 404 `task_not_found` (JSON valide) ; `PATCH` d'une tâche ayant des actions requises ne les modifie pas.
6. `DELETE` d'un id inconnu : 404 `task_not_found`; deuxième `DELETE` d'une même tâche : 404 (non idempotent).
7. 405 : `PUT`/`DELETE` sur `/tasks` (`Allow: GET, POST`), `PUT` sur `/tasks/{id}` (`Allow: GET, PATCH, DELETE`).
8. 500 : dépôt qui lève une `PDOException` (SQLSTATE `XX000`) pendant `POST` : corps `internal_error` sans SQLSTATE ni message; 503 sur connexion refusée.
9. Parcours bout en bout : `POST` -> `GET` -> `PATCH` -> `GET /tasks` -> `DELETE` -> `GET` 404.

Non vérifiable par ces tests : appel HTTP réel (`php -S` + curl), à faire une fois par le Developer.

### À faire valider avant implémentation

Rien : les quatre points ont été validés le 2026-10-11 (voir les statuts en tête de section). Resserrer `Content-Type` (415) ou traiter les paramètres de requête serait une évolution future, à valider à part.

## Cycle de vie (tranche B) — conception du 2026-10-11, **non livrée**

Statuts. **Validé** = décision explicite de l'utilisateur (BACKLOG, « Décisions validées (cycle de vie) ») : états `À faire` / `En cours` / `Terminée`, créée « À faire »; transitions permises À faire → En cours → Terminée, et retour à « À faire » depuis les deux autres; toute autre refusée par une erreur de domaine nommée; terminer avec des actions requises non Terminées : refusé; terminer une périodique : passe `Terminée`, crée une occurrence (mêmes titre, catégorie, durée, description, périodicité; sans lien; « À faire »; échéance = ancienne + intervalle, fin de mois ramenée au dernier jour); suppression d'une Terminée : mêmes règles; périmètre migration `0002` + couche commune + REST (`PATCH`, filtres) + README. **Validé par délégation** = Q1 à Q12 du backlog, option recommandée retenue par la session principale, **non confirmée explicitement** par l'utilisateur. **Proposé** = tout le reste (noms, codes, statuts HTTP, SQL, organisation du code) : non opposable au Developer avant validation. Hors périmètre : MCP, portail, authentification.

### Codes d'état

Codes stables en base et dans les interfaces : `todo`, `in_progress`, `done` (anglais, comme les unités de périodicité); libellés français (« À faire », « En cours », « Terminée ») à la charge des interfaces d'affichage (portail, texte MCP). **Proposé.**

### Migration `0002_task_status.sql` (proposé; le fichier fera foi une fois écrit)

```sql
ALTER TABLE tasks
  ADD COLUMN status       text        NOT NULL DEFAULT 'todo',
  ADD COLUMN completed_at timestamptz,
  ADD CONSTRAINT tasks_status_valid CHECK (status IN ('todo', 'in_progress', 'done')),
  ADD CONSTRAINT tasks_completed_at_consistent CHECK ((status = 'done') = (completed_at IS NOT NULL));
```

- Backfill (Q7, validé par délégation) : la valeur par défaut donne `todo` et `completed_at` nul à toutes les lignes existantes, sans `UPDATE` : aucune donnée modifiée ni perdue. Les deux `CHECK` sont vrais pour ces lignes.
- « Renseignée si et seulement si `done` » : `status` étant `NOT NULL`, les deux membres sont des booléens non nuls, donc pas le piège de logique à trois valeurs rencontré en 0001.
- Rejouabilité et absence d'état partiel : assurées par `bin/migrate` (table de suivi, transaction). Pas de `IF NOT EXISTS` : ce serait masquer une divergence. Aucun `BEGIN`/`COMMIT`, rien de `CONCURRENTLY`.
- `completed_at` en `timestamptz` (instant absolu, rendu en UTC `...Z` par le dépôt comme `created_at`). Valeur positionnée par `now()` dans l'`UPDATE`, comme `updated_at`; pas de trigger. Pas d'index sur `status` (volume d'un outil personnel; à ajouter par migration si besoin).
- Compatibilité PostgreSQL : `ADD COLUMN` avec défaut constant ne réécrit pas la table; `ADD CONSTRAINT ... CHECK` la parcourt sous verrou `ACCESS EXCLUSIVE` sans réécriture (documentation PostgreSQL 17 `ALTER TABLE`, consultée le 2026-10-11; la page ne date pas l'apparition du défaut sans réécriture, PostgreSQL 11 selon la connaissance de l'Architecte, non vérifié par source). Sans effet à cette échelle. AlwaysData propose PostgreSQL 16 à 18 (vérifié le 2026-10-10, tableau ci-dessus). Aucune extension, aucun droit particulier (pas de fonction ni de trigger) : le seul point non vérifié reste celui de 0001 (droits `CREATE FUNCTION`/trigger, pgBouncer). Aucune migration sur AlwaysData sans autorisation.
- Noms de contraintes verrouillés par test d'intégration (`23514`). Un `23514` sur ces deux noms qui atteindrait le dépôt est un bogue du service : erreur interne (500), journalisée, non traduite (règle existante des `tasks_*`).
- Tests de schéma (`TaskSchemaTest`) : défaut `todo`; valeur d'état inconnue refusée; `done` sans clôture refusé; `todo`/`in_progress` avec clôture refusé; `done` avec clôture accepté; **backfill** : appliquer 0001 seule via un `Migrator` pointant un répertoire temporaire contenant uniquement 0001, insérer des tâches, puis migrer le répertoire complet et vérifier `todo` / `NULL`.

### Modèle et contrat de données partagé

`Task` gagne deux champs : `status` (`"todo"|"in_progress"|"done"`) et `completed_at` (ISO 8601 UTC ou `null`). Ajout compatible : aucun champ existant ne change. `required_actions` reste `[{id,title}]` (ajouter `status` aux éléments est possible plus tard, non retenu : non demandé). `create` et `update` n'acceptent toujours pas `status`/`completed_at` comme champ ordinaire (`field_unknown` à la création; voir `update` ci-dessous). Les requêtes du dépôt nomment déjà leurs colonnes : `TASK_COLUMNS` ajoute `status` et `completed_at`, rien d'autre ne change dans `insert`/`update`.

### Couche commune (proposé)

- `TaskStatus` : énumération PHP 8.3 adossée (`Todo`, `InProgress`, `Done`; PHP natif, sans dépendance) portant **l'unique table des transitions** : `todo -> {in_progress}`, `in_progress -> {done, todo}`, `done -> {todo}`. Même état ou autre couple : refusé.
- `NextDueDate` (classe pure, `Application`) : `compute(string $dueDate, int $interval, string $unit): string`, testée en `tests/Unit` sans base. Règle : jour = + N jours; semaine = + 7 N jours; mois = + N mois; année = + 12 N mois; pour mois et année, le jour est conservé et **ramené au dernier jour du mois d'arrivée s'il n'existe pas** (31 janvier + 1 mois = 28/29 février; 29 février + 1 an = 28 février; 29 février 2028 + 4 ans = 29 février 2032). Calcul sur (année, mois, jour) entiers, **jamais** `DateTime::modify('+1 month')` (déborde sur mars). Pas de colonne d'ancrage : l'échéance suivante part de la date stockée, donc dérive acceptée (31 janv. → 28 févr. → 28 mars; Q2). Date butée suivante = ancienne + intervalle même si le résultat est passé (Q2). **Dépassement** : résultat après `9999-12-31` (borne du validateur) -> `NextDueDateOutOfRange`; pour ne pas dépendre de la taille des entiers, tester d'abord un intervalle manifestement hors plage (jours/semaines > ~4 millions) avant toute addition; les mois se calculent en entiers 64 bits (intervalle max 2 147 483 647 x 12 tient). Test d'oracle possible dans la suite d'intégration : comparer à `date + make_interval(...)` de PostgreSQL, qui ramène aussi au dernier jour du mois (comportement connu de l'Architecte, **non vérifié** ici).
- `TaskService::changeStatus(int $id, mixed $status): array` renvoie `{task: Task, next_occurrence: Task|null}`. Un cas d'usage unique pour toutes les transitions (la terminaison n'est que la transition vers `done`). Une transaction (`READ COMMITTED`, existante), ordre fixe :
  1. `find($id, forUpdate: true)` : verrou de ligne; inconnu : `TaskNotFound`. Une seconde demande concurrente attend le verrou, relit l'état `done` et reçoit l'erreur de transition (Q6; en `READ COMMITTED`, la ligne est relue après attente);
  2. valeur : non-chaîne ou `null` -> `InvalidTask` `field_type_invalid`; chaîne hors des trois codes -> `InvalidTask` `status_invalid` (sensible à la casse);
  3. transition permise par `TaskStatus`, sinon `InvalidStatusTransition`;
  4. si cible `done` : actions requises non `done` (lues avec `FOR SHARE` sur les lignes requises, pour qu'aucune ne soit rouverte entre le contrôle et le commit) -> `RequiredActionsNotDone` avec la liste complète; démarrer (`in_progress`) avec prérequis non terminés reste permis (Q11);
  5. si cible `done` et tâche périodique : calcul de l'échéance (`NextDueDateOutOfRange` si dépassement); tout refus laisse la tâche, `updated_at` et la table inchangés (aucune écriture avant ce point);
  6. écriture : `UPDATE tasks SET status, completed_at = (now() si done, sinon NULL), updated_at = now()`; si périodique et cible `done` : `INSERT` de l'occurrence (mêmes `title`, `category`, `estimated_minutes`, `description`, `recurrence`; `due_date` calculée; `status` `todo`), **aucun lien** (Q3).
  Pas de validation métier hors de ce cas d'usage. L'occurrence est créée avec les valeurs d'une ligne déjà valide : elle ne repasse pas par `TaskValidator`.
- `TaskService::patch(int $id, array $changes): array` (nouveau, sortie toujours `{task, next_occurrence}`) porte l'aiguillage de `PATCH` : clé `status` présente **avec** une autre clé -> `InvalidTask` `status_not_alone` (`details.field` = `status`); `status` seul -> `changeStatus`; sans `status` -> `update` (puis `next_occurrence` = `null`). Raison : la règle « `status` seul » (Q8) vit à un seul endroit, partagée par REST et un futur MCP. `update` reste identique à la tranche A (`status` y reste `field_unknown`). Alternative écartée : porter la règle dans `TaskController` (à dupliquer à l'arrivée de MCP). Conséquence : le contrôleur appelle `patch` pour tout `PATCH`, et n'ajoute `next_occurrence` à la réponse que si la clé `status` figure dans le corps (décision de présentation REST, voir contrat).
- `TaskService::list(array $filters = [])` : clés permises `status`, `category`; clé inconnue -> `InvalidTask` `field_unknown`. Valeur : non-chaîne -> `field_type_invalid`; chaîne vide ou hors liste -> `status_invalid` / `category_invalid` (mêmes codes que dans les corps, `details.field` = `status` ou `category`; catégorie sensible à la casse); filtres combinés en ET; **une seule valeur** par filtre. Sans filtre : toutes les tâches, tri inchangé. SQL : clauses `WHERE` fixes avec paramètres liés, jamais de nom de colonne issu de l'entrée.
- Inchangés : `create` (créée `todo`, sans clôture par le défaut), `get`, `delete` (mêmes règles pour une tâche `done`), `addRequiredAction`/`removeRequiredAction` (Q11 : ajouter une action requise non terminée à une `done` est permis), `TaskValidator` pour les champs existants. `update` d'une `done` : autres champs modifiables (Q8).
- Retour à `todo` : `completed_at` effacé; l'occurrence déjà créée n'est pas touchée, aucune filiation, doublon possible si on re-termine (Q4, accepté).
- `Request` : gagne un paramètre `query` (tableau, défaut `[]`, dernier argument : appels existants valides), rempli par `fromGlobals()` avec `parse_str(parse_url($uri, PHP_URL_QUERY))`. C'est la **seule** modification d'une classe HTTP.

### Erreurs de domaine ajoutées (proposé; noms corrigés par rapport au backlog)

Convention constatée : `<champ>_invalid` pour une valeur (`category_invalid`, `due_date_invalid`), `<sujet>_<état>` pour un refus d'état (`task_not_found`, `task_is_required`, `dependency_cycle`); le vocabulaire du modèle est « actions requises », pas « prérequis ». D'où :

| Classe | `code` | `details` | REST | Remplace |
|---|---|---|---|---|
| `InvalidTask` (famille existante) | `status_invalid`, `status_not_alone` ; `field_type_invalid` | `{field}` | 422 (par classe) | — |
| `InvalidStatusTransition` | `status_transition_invalid` | `{id, from, to}` | 409 | `invalid_status_transition` : corrigé (suffixe `_invalid` aligné sur `status_invalid`; la famille `status_*` se range ensemble) |
| `RequiredActionsNotDone` | `required_actions_not_done` | `{id, required_actions:[{id,title,status}]}` triées par `id` | 409 | `prerequisites_not_done` : corrigé (« prerequisites » n'existe nulle part dans le modèle ni les contrats; `required_actions` est le nom du champ) |
| `NextDueDateOutOfRange` | `next_due_date_out_of_range` | `{id, due_date}` (échéance actuelle) | 409 | nom proposé par le backlog sans code : à valider |

409 : l'entrée est correcte mais en conflit avec l'état courant (principe existant); `RestAdapter::DOMAIN_STATUS` reçoit trois lignes, rien d'autre. Messages en français, sans valeur utilisateur répétée. Aucune classe `InvalidTask` supplémentaire n'est nécessaire (le statut 422 se décide par classe).

Traduction SQL (`TaskRepository`) : aucune nouvelle traduction; `23514` sur `tasks_status_valid`/`tasks_completed_at_consistent` = bogue = 500.

### Contrat REST (proposé; versionnement `/api/v1` conservé, ajouts compatibles)

**`PATCH /api/v1/tasks/{id}`** avec la clé `status` (appelle `TaskService::patch`)
- Corps : `{"status":"todo"|"in_progress"|"done"}` seul. Avec un autre champ : 422 `status_not_alone`. `status` `null`, non chaîne : 422 `field_type_invalid`; chaîne inconnue ou vide : 422 `status_invalid`. Sans clé `status`, le comportement de la tranche CRUD est inchangé.
- 200 : la `Task` **à plat** plus la clé `next_occurrence` : `{...Task, "next_occurrence": Task|null}`. Présente dans toute réponse à un `PATCH` contenant `status` (`null` sauf terminaison d'une périodique); absente des autres réponses (`GET`, `POST`, `PATCH` sans `status`). **À valider** : variante « toujours présente » écartée pour ne pas changer la forme des réponses existantes.
- Ordre des contrôles : 400 `invalid_json` -> 404 `task_not_found` -> 422 (`status_not_alone`, `field_type_invalid`, `status_invalid`) -> 409 `status_transition_invalid` -> 409 `required_actions_not_done` -> 409 `next_due_date_out_of_range`. Chaque refus : tâche et `updated_at` inchangés.

**`GET /api/v1/tasks?status=<code>&category=<Pro|Perso>`** : filtres facultatifs, une valeur chacun, combinés en ET. Valeur invalide : 422 `status_invalid` / `category_invalid` avec `details.field`; valeur répétée sous forme de tableau (`status[]=`) : 422 `field_type_invalid`; `status=` vide : 422. Paramètres inconnus ignorés (l'adaptateur ne transmet que `status` et `category`; la tranche CRUD « chaîne de requête ignorée » reste vraie sans filtre). Sans filtre : toutes les tâches, 200, `[]` si aucune.

Les `Task` de toutes les réponses (`GET`, `POST` 201, `PATCH`, liens `PUT`/`DELETE`) exposent `status` et `completed_at`. `POST` ne les accepte pas : 422 `field_unknown`.

Tableau des erreurs de la tranche : 404 `task_not_found`; 422 `status_invalid`, `status_not_alone`, `field_type_invalid`, `category_invalid`; 409 `status_transition_invalid`, `required_actions_not_done`, `next_due_date_out_of_range`; 400 `invalid_json`; 405/500/503 inchangés (`Allow` inchangé).

### Impact sur la suppression et les dépendances

- `DELETE` : règle inchangée. Une tâche `done` requise par une autre : 409 `task_is_required` (Q3 : après terminaison d'une périodique, les liens restent sur l'ancienne occurrence, qui reste requise); sinon supprimée avec ses propres liens. Rien dans le schéma ne relie une occurrence à sa source : supprimer l'une n'affecte pas l'autre.
- Dépendances : le trigger anti-cycle est inchangé; `done` ne joue aucun rôle dans le cycle. Le contrôle des prérequis ne vit que dans `changeStatus`. Tolérés (Q4, Q11) : une `done` qui a une action requise non terminée (ajoutée après coup ou rouverte en amont).
- Concurrence : verrous pris dans l'ordre « tâche, puis ses actions requises »; ce graphe est acyclique, donc pas d'interblocage entre deux terminaisons; une réouverture ne verrouille que sa ligne. Un lien ajouté pendant une terminaison attend le verrou de la tâche (clé étrangère). Non testé en déterministe, seule la terminaison double (deuxième demande -> `status_transition_invalid`) l'est par l'état final; à lire comme tel.

### Analyse d'impact

- Schéma : une migration additive, aucune perte; consommateurs : `TaskSchemaTest` et suites qui remigrent (deviennent 0001 + 0002), tests comparant la forme exacte d'une `Task` (à mettre à jour pour `status`, `completed_at`; une recherche rapide des clés dans `tests/` n'a pas trouvé de comparaison de la forme complète, non exhaustive : à vérifier par le Developer en rejouant la suite).
- REST : ajouts seulement (clé `status`, `next_occurrence`, deux champs de `Task`, deux paramètres de requête). Aucune rupture; aucun client réel. 
- Dépendances et hébergement : aucune dépendance Composer; PHP natif (enum depuis 8.1, contrainte `>=8.3` respectée); `mbstring` inchangé.
- Sécurité : validation côté serveur dans le cas d'usage, requêtes préparées, valeurs jamais répétées dans les erreurs, filtres liés. Authentification : aucune (local).
- Impact futur MCP : un outil de changement d'état appelle `TaskService::patch` (ou `changeStatus`), renvoie les mêmes JSON d'erreur en `isError` et la même forme `{task, next_occurrence}`; schéma d'entrée : `status` énuméré aux trois codes. Portail : table code -> libellé français, affichage de `completed_at`; aucune règle de transition à réécrire (la table de `TaskStatus` peut être exposée à l'interface si besoin). Authentification : sans effet.

### Tests à écrire avant le code (proposé)

- Unit : `NextDueDate` (31 janv. + 1 mois = 28 févr. 2026 et 29 févr. 2028; 2028-02-29 + 1 an = 2029-02-28; + 4 ans = 2032-02-29; 2026-12-31 + 1 mois = 2027-01-31; semaine = + 7 jours; jours; intervalle immense; `9999-12-31` + 1 jour/mois/année et `9999-12-30` + 1 jour = `9999-12-31` accepté); `TaskStatus` (les 9 couples).
- Intégration service : chaque transition permise (4) et refusée (au moins `todo`->`done`, `done`->`in_progress`, même état), avec `updated_at` et ligne inchangés (forcer d'abord `updated_at` dans le passé); terminaison avec prérequis non `done` : liste exacte, aucune occurrence, ligne inchangée; sans prérequis ou tous `done` : réussit; périodique : `done` conservée, occurrence aux bons champs, sans lien, `todo`, bonne échéance, `next_occurrence`; non périodique : `next_occurrence` `null`, une seule ligne; dépassement : refusé, aucune écriture; `completed_at` posé à la terminaison, effacé au retour à `todo`; seconde terminaison -> `status_transition_invalid`, une seule occurrence; `patch` : `status` + autre champ, `status` `null`/inconnu; `list` : filtres seuls et combinés, valeurs invalides, tri; démarrer avec prérequis non terminés; ajouter une action requise non terminée à une `done`; `delete` d'une `done` requise (409) et non requise.
- REST : `PATCH` (200 + `next_occurrence`, 409 x3, 422 x3, 404, 400 prioritaire sur 404), `GET /tasks` filtré (422 et `details.field`, paramètre inconnu ignoré), `Task` avec `status`/`completed_at` partout.
- Non vérifiable par ces tests : appel HTTP réel; concurrence réelle sur deux connexions (un test déterministe à deux connexions est possible, comme pour les liens : connexion 1 tient `FOR UPDATE`, connexion 2 avec `lock_timeout` échoue en `55P03`).

### À faire valider avant implémentation

1. Noms corrigés : `status_transition_invalid`, `required_actions_not_done`; nouveau `next_due_date_out_of_range`; `status_not_alone`; statut 409 pour les trois refus d'état.
2. Colonnes `status` (codes anglais) et `completed_at timestamptz`, deux `CHECK` nommés (schéma, donc étape structurante).
3. `Task` expose `status` et `completed_at` (contrat partagé, ajout compatible).
4. `next_occurrence` seulement dans les réponses de `PATCH` avec `status`.
5. `TaskService::patch` (nouveau cas d'usage d'aiguillage) plutôt que modifier `update`.
6. `Request::$query`.
7. Les décisions Q1 à Q12 restent « validées par délégation » : l'utilisateur peut les confirmer ou les rouvrir; la migration étant additive, une réouverture de Q1 à Q6 coûte une règle de service, pas une migration, sauf Q5 (contrainte de clôture).

## Portail web (tranches B1 et B2) — conception du 2026-10-11, **non livrée**

Statuts. **Validé** : PW1 à PW12 (voir « Décisions validées »). **Proposé** : tout le reste (routes, formulaires, classes, en-têtes, tests) : non opposable au Developer avant validation. **Conçu maintenant** : B1 et B2 en détail (B1 n'est pas encore validée, B2 en dépend). Hors périmètre : MCP, authentification, déploiement, migration (aucune), dépendance (aucune).

### Décisions d'architecture

- **Rendu serveur appelant `TaskService` (PW2) : confirmé.** Les règles (validation, cycles, transitions, terminaison) vivent déjà dans `TaskService` et en base; une application JavaScript sur REST ajouterait un second contrat et ferait de l'API sans authentification l'unique surface. Le portail est un troisième adaptateur : il traduit formulaire -> tableau PHP -> cas d'usage, et exception de domaine -> page. Alternative écartée : portail appelant REST en HTTP (aller-retour inutile, sérialisation en double).
- **Aucune règle dans le portail.** Sont de la présentation et non des règles : libellés français, formats de date, choix des boutons (via `TaskStatus::allowedTargets()`, la table des transitions existante, lue et non recopiée), regroupement de messages d'erreur, et le masquage des terminées (voir `hide_done`).
- **Une seule table code d'erreur -> statut HTTP**, partagée par REST et portail : extraire `DOMAIN_STATUS` et la règle « `InvalidTask` = 422 » de `RestAdapter` vers une petite classe `Http\DomainStatus` (comportement REST inchangé, tests REST inchangés). Même extraction pour l'analyse d'un identifiant de chemin (`[1-9][0-9]*` + `FILTER_VALIDATE_INT`, `RestAdapter::id()` aujourd'hui privée) vers `Http\PathId`. Alternative : dupliquer ces deux morceaux, refusée (risque de divergence des statuts entre interfaces).
- **Session minimale** : session PHP native (cookie `actions_session`, `HttpOnly`, `SameSite=Strict`, `Secure` si la requête est en HTTPS, durée = navigateur). Elle ne contient que : le jeton CSRF (aléatoire, 32 octets via `random_bytes`, valeur hexadécimale) et un message flash. Pas de compte, pas de lien avec l'authentification future. Alternative écartée : cookie double-submit (aucune session) : il ne porte pas le message flash de la redirection (PW7), qui obligerait à mettre l'information dans l'URL.
- **Jeton CSRF** : un jeton par session, inséré dans chaque formulaire `POST` (champ `_csrf`), comparé par `hash_equals`. Non à usage unique : l'usage unique casserait « retour arrière », deux onglets et le réaffichage d'un formulaire après erreur. « Par formulaire » (PW10) = chaque formulaire en porte un.
- **Tests de la session** : interface `Portal\Session` (`csrfToken()`, `flash(array)`, `pullFlash()`, `close()`) et deux implémentations : `NativeSession` (production) et `ArraySession` (tests, sans en-tête ni cookie). Deux implémentations justifient l'interface : sans elle les tests HTTP dépendraient de `session_start()` et des en-têtes.
- **Fichiers statiques** : une feuille `public/assets/portal.css`, sans JavaScript (PW3 : « JavaScript minimal facultatif » : aucun dans B1/B2; chaque besoin futur sera à justifier). Le serveur de développement `php -S ... public/index.php` ne sert pas les fichiers existants si le routeur ne le demande pas : `public/index.php` gagne, en tête, `if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/assets/') && is_file(__DIR__ . $path)) { return false; }` (`$path` = chemin décodé de l'URL, sans `..`). Sur AlwaysData (Apache) le fichier existant est servi directement si la règle de réécriture exclut les fichiers existants (`!-f`) : **non vérifié**.
- **URL et préfixe** : tous les liens et actions de formulaire sont produits par un seul assistant `$url('/tasks/12')`, pour pouvoir préfixer plus tard (`/index.php` si la réécriture est indisponible sur AlwaysData). Non conçu davantage (tranche de déploiement).

### Organisation du code (proposé)

```
src/Portal/
  PortalAdapter.php     Routage portail, garde (Host, Origin, CSRF), en-têtes de sécurité, conversion des erreurs en pages
  TaskPages.php         Un cas d'usage -> une méthode : list, show, newForm, create, editForm, update, deleteConfirm, delete (B1)
                        B2 : addRequiredAction, removeRequiredAction, changeStatus, reopenConfirm (dans TaskPages ou TaskActionPages)
  TaskForm.php          Formulaire <-> tableau pour TaskService (traduction de transport, aucune règle)
  Labels.php            Codes -> français (état, catégorie, unité), dates JJ/MM/AAAA, horodatages Europe/Paris, durée, périodicité
  Messages.php          Code d'erreur -> message de portail (repli : message de l'exception)
  Session.php, NativeSession.php   (et tests/Support/ArraySession.php)
  View.php              Rendu de gabarits PHP natifs, assistant d'échappement
  Views/                layout.php, task_list.php, task_detail.php, task_form.php, task_delete.php, error.php (B2 : partiels)
src/Http/DomainStatus.php, PathId.php   Extraits de RestAdapter (voir plus haut)
public/assets/portal.css
```

- `Kernel` : le portail reçoit `/`, `/tasks` et `/tasks/...` (aucun conflit avec `/api/v1` ni `/mcp`); les autres chemins inconnus restent en 404 JSON (existant). `Bootstrap::kernel(Config, ?Session = null)` : `NativeSession` par défaut; `PortalAdapter` reçoit `TaskService`, `Session` et les hôtes autorisés. Signature compatible avec les appels existants.
- `Request` gagne `public readonly array $form = []` (dernier argument, appels existants valides), rempli par `fromGlobals()` depuis `$_POST`. Seule modification d'une classe HTTP; `Response` inchangée (en-têtes libres; `X-Content-Type-Options: nosniff` déjà ajouté par `send()`).
- **Gabarits** : PHP natif, un seul assistant `$e(mixed): string` = `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, appliqué à **toute** valeur affichée, y compris dans les attributs; `$url()` pour les liens. Aucune sortie brute hors `$e`/`$url`; les textes de messages sont eux aussi échappés. Les sauts de ligne d'une description s'affichent par CSS `white-space: pre-wrap`, pas par `nl2br`. Test de garde : un test unitaire qui parcourt `Views/` et refuse tout `<?=` ou `echo` qui n'appelle pas `$e(`/`$url(` (filet bon marché, non exhaustif).
- Dépendance dirigée comme les autres adaptateurs : `Portal` -> `Application` (`TaskService`, `TaskStatus`, exceptions). Aucun SQL, aucun `PDO`. L'accès aux tâches liées d'une page passe par `list()`.

### Garde de chaque requête portail (proposé)

Ordre : Host -> méthode/route -> (POST) Origin -> (POST) CSRF -> cas d'usage.
1. **Host** : le nom d'hôte de l'en-tête `Host` doit figurer dans `PORTAL_ALLOWED_HOSTS` (défaut : `127.0.0.1`, `localhost`, `[::1]`), sinon 403. Raison : sans authentification (PW1), une page tierce qui fait pointer son nom DNS vers `127.0.0.1` (« DNS rebinding ») pourrait lire les données; le contrôle d'`Origin` seul ne protège pas les `GET`. **Ajout de l'Architecte, à valider** (une variable de configuration).
2. **Origin** (requêtes `POST`) : si l'en-tête est présent, son hôte et son port doivent être ceux de `Host`, sinon 403. Absent : accepté (le jeton CSRF reste exigé). Même esprit que le contrôle MCP, sans liste à configurer.
3. **CSRF** : champ `_csrf` absent ou différent de la session : 403, page « Requête refusée. Rechargez la page et recommencez. », rien n'est exécuté. Aucune modification par `GET`.
4. **En-têtes sur toute réponse HTML** : `Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'` (pas de script; `form-action 'self'` est compatible avec les redirections vers la même origine), `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` (pages contenant des données et le jeton).
5. **Erreurs** : aucune trace, aucun message PDO, aucun SQL, aucune valeur saisie répétée dans un message; détail au journal (`error_log`). `display_errors` reste réglé par `public/index.php` (actif seulement en `local`).

### Routes et contrat des pages — B1 (proposé)

Pages en `text/html; charset=utf-8`, `<html lang="fr">`, titre de page distinct (`<Page> — Actions`). Formulaires `application/x-www-form-urlencoded`. Succès d'une écriture : **303 See Other** vers la page de résultat (Post/Redirect/Get), jamais de page rendue en réponse à un `POST` réussi. Identifiant de chemin invalide (hors `[1-9][0-9]*`) ou tâche inconnue : 404 (page « Tâche introuvable. »). Méthode non prévue sur une route connue : 405 avec `Allow` (page texte courte).

| Méthode, chemin | Cas d'usage | Succès | Erreurs |
|---|---|---|---|
| `GET /` | — | 302 vers `/tasks` | — |
| `GET /tasks` (paramètres `status`, `category`, `hide_done`) | `list` | 200 liste | 422 (filtre hors liste : page d'erreur, sans liste), 503 |
| `GET /tasks/new` | — | 200 formulaire vide | — |
| `POST /tasks` | `create` | 303 vers `/tasks/{id}`, flash « Tâche créée. » | 422 formulaire réaffiché avec saisie et erreurs; 403 CSRF |
| `GET /tasks/{id}` | `get` (+ `list` pour l'état des actions requises) | 200 détail | 404 |
| `GET /tasks/{id}/edit` | `get` | 200 formulaire prérempli | 404 |
| `POST /tasks/{id}` | `update` | 303 vers `/tasks/{id}`, flash « Tâche enregistrée. » | 404; 422 formulaire réaffiché; 403 |
| `GET /tasks/{id}/delete` | `get` | 200 page de confirmation nommant la tâche (PW5) | 404 |
| `POST /tasks/{id}/delete` | `get` (titre pour le flash) puis `delete` | 303 vers `/tasks`, flash « Tâche « titre » supprimée. » | 404; **409** page de confirmation réaffichée avec la liste des tâches dépendantes (liens); 403 |

Statuts d'erreur de domaine : ceux de REST via `Http\DomainStatus` (`InvalidTask` 422, `TaskNotFound` 404, conflits 409), `DatabaseUnavailable` 503 (page « Service indisponible. »), toute autre exception 500 (page « Erreur interne. »), toutes sans détail technique. Réponse d'erreur de formulaire : la page porte le statut de l'erreur (422/409) et non 200.

**`GET /tasks`** : valeurs de `status` : `todo`, `in_progress`, `done`; de `category` : `Pro`, `Perso`; la valeur vide (option « Tous ») équivaut à l'absence de filtre (le portail retire les chaînes vides avant d'appeler `list`; REST, lui, refuse une valeur vide). Filtres combinés en ET par `TaskService::list`; l'ordre est celui du cas d'usage (date butée croissante, sans date en dernier, puis `id`). **`hide_done=1`** (PW6) : le portail retire de la liste renvoyée les tâches `done` (filtre de présentation, aucune règle métier, aucun changement de `TaskService`); `status=done` avec `hide_done=1` donne une liste vide, signalée. Le formulaire de filtre est un `GET` (adresse partageable, pas de CSRF) qui conserve les valeurs choisies; liste vide : message « Aucune tâche. » (ou « Aucune tâche ne correspond aux filtres. »). Colonnes : titre (lien), catégorie, état (libellé en français en texte, pas seulement une couleur), date butée `JJ/MM/AAAA`, périodicité en français.

**Formulaire de tâche** (`create` et `update`) : champs, tous reliés à un `<label>` :

| Champ (nom) | Contrôle | Transport vers `TaskService` |
|---|---|---|
| `title` | texte, `maxlength="255"` et indication « 255 caractères au plus » | transmis tel quel (pas de `trim`; PW11) |
| `category` | liste avec option vide « Choisir… » sélectionnée par défaut, `Pro`, `Perso` | tel quel; vide = `category_invalid` fourni par le cas d'usage |
| `due_date` | `<input type="date">` (valeur `AAAA-MM-JJ`; affichage selon le navigateur, ce qui satisfait `JJ/MM/AAAA` sans analyser de date côté PHP) | vide -> `null` |
| `recurrence_interval`, `recurrence_unit` | nombre `min="1"` + liste « jour(s), semaine(s), mois, année(s) » (valeurs `day`, `week`, `month`, `year`), présentés « Répéter tous les [N] [unité] » | les deux vides -> `recurrence: null`; sinon `{interval, unit}` avec un élément vide -> `null` (le validateur tranche : `recurrence_incomplete`, etc.) |
| `estimated_minutes` | nombre `min="1"`, libellé « Durée estimée (minutes) » | vide -> `null` |
| `description` | zone de texte | vide -> `null` |
| `_csrf` | caché | contrôlé par la garde |

`TaskForm` convertit seulement le **type de transport** : une chaîne composée de chiffres et tenant dans un entier PHP devient `int`; toute autre chaîne reste telle quelle, le cas d'usage la refuse (`field_type_invalid` ou `*_invalid`). Il ne retransmet que les six champs de la liste (aucun `status` ni `required_actions` ne peut passer par ce formulaire). `update` reçoit **toujours** les six champs (un champ vidé efface la valeur); `TaskService::update` compare et n'écrit pas si rien n'a changé.

**Erreurs de formulaire (accessibilité)** : en cas de refus, le formulaire est réaffiché avec la saisie, un résumé d'erreurs en tête (`role="alert"`, liens vers les champs), et le message sous chaque champ concerné (`aria-describedby`, `aria-invalid="true"`). Correspondance `details.field` -> champ : `title`, `category`, `due_date`, `estimated_minutes`, `description` identiques; `recurrence`, `recurrence.interval`, `recurrence.unit` -> groupe « Périodicité » (`recurrence_requires_due_date` affiché sous la date butée **et** sous la périodicité, au résumé une fois). Tout champ inconnu retombe dans le résumé seul.

**Messages** (`Messages`) : table `code -> texte` pour les cas où le texte du portail diffère de celui de l'exception; **repli** : le message français de l'exception (`DomainError::getMessage()`, déjà sans valeur saisie). Parce que certains messages de l'exception citent des codes techniques (`L'état doit être todo, in_progress ou done.`, `Unité attendue : day, week...`, `Date attendue au format AAAA-MM-JJ.`), le portail fournit son texte pour au moins : `due_date_invalid` (« Date invalide. »), `recurrence_unit_invalid`, `status_invalid`, `field_unknown`, `field_type_invalid`. Messages des erreurs de B2 au tableau B2. Le formulaire ne peut normalement pas produire ces codes (contrôles natifs); ils servent aux saisies falsifiées ou aux navigateurs sans type `date`.

**Détail** : titre (h1), catégorie, état (libellé), date butée, périodicité (« Tous les 2 mois »), durée (« 90 minutes »), description, date de clôture si `done` (Europe/Paris, `JJ/MM/AAAA HH:MM`), création et dernière modification (idem), liste des actions requises (titre en lien vers `/tasks/{id}`, état de chaque action). **État des actions requises** : `Task.required_actions` ne contient que `{id, title}`; en B1 le portail en lit l'état dans l'index d'un `list()` (une requête, volume d'un outil personnel). Alternative, **ajout compatible au contrat partagé** à valider si B2 le justifie : ajouter `status` aux éléments de `required_actions` (REST et MCP en bénéficient; les tests REST qui comparent la forme exacte seraient à mettre à jour). Liens « Modifier » et « Supprimer ». B1 n'offre ni changement d'état ni gestion des liens : l'état est affiché seulement.

**Confirmation de suppression** : page nommant la tâche (titre échappé), bouton « Supprimer définitivement » (formulaire `POST` avec `_csrf`) et lien « Annuler » vers le détail. Le refus métier (`task_is_required`) réaffiche cette page en 409 avec « Suppression impossible : cette tâche est requise par : » suivi de la liste (titre en lien), la tâche restant intacte.

**Libellés et formats** (`Labels`, présentation uniquement) : états `todo`/`in_progress`/`done` -> « À faire »/« En cours »/« Terminée »; unités -> jour(s), semaine(s), mois, année(s) (affichage « Tous les N <unité> », N = 1 : « Chaque <unité> » facultatif, au Developer); date `AAAA-MM-JJ` -> `JJ/MM/AAAA` par découpage de chaîne (aucun fuseau, aucune conversion); horodatage ISO UTC -> `DateTimeImmutable` -> fuseau `Europe/Paris` -> `JJ/MM/AAAA HH:MM`; durée : minutes entières telles que stockées. Valeur de base jamais modifiée.

**Flash** : un message (`{type: success|error, text, link?: {label, path}}`) écrit avant la redirection, lu et supprimé au premier affichage, rendu dans un élément `role="status"` (ou `role="alert"` pour une erreur), échappé. Le titre de la tâche inséré dans un message est échappé comme toute donnée.

### Conventions de test B1 (proposé)

- `tests/Integration/PortalTasksTest` : PostgreSQL réel (comme `RestTasksCrudTest`), `Bootstrap::kernel($config, new ArraySession())`, appels `Kernel::handle(new Request(...))` sans serveur. Les `POST` utilisent `Request::$form` et le jeton lu dans la page de formulaire précédente.
- Cas : parcours (liste vide -> créer -> détail -> modifier -> liste -> supprimer); création minimale et complète (valeurs conservées, formats `JJ/MM/AAAA`, Europe/Paris, durée); un test par code de validation affiché près du champ avec saisie conservée et statut 422; effacement de `due_date` d'une périodique refusé (message, tâche inchangée); suppression refusée 409 avec titres des dépendants (tâche et lien intacts) puis réussie après retrait du lien (créé par `TaskService`); confirmation de suppression sans suppression au `GET`; filtres (`status`, `category`, ET, `hide_done`, valeur vide, valeur invalide 422); tâche inconnue et identifiant invalide : 404 HTML; **échappement** : titre, description et message flash `<script>alert(1)</script>` rendus en texte dans liste, détail, formulaire (valeur d'attribut `"` comprise), confirmation; **parité avec REST** (critère 10) : une même entrée refusée par REST (`title` blanc, catégorie `pro`, périodicité sans date) l'est par le portail avec le même `code`; **sécurité** : `POST` sans jeton ou avec jeton faux : 403 et aucune écriture; `Origin` étranger : 403; `Host` hors liste : 403; en-têtes CSP/`nosniff`/`no-store` présents; `GET` n'écrit jamais; 500 sans trace ni message PDO (dépôt qui lève une `PDOException`) et 503 sans détail.
- Unitaires : `Labels` (dates, fuseau d'été et d'hiver, périodicité), `TaskForm` (vide -> `null`, chiffres -> `int`, périodicité), garde d'échappement des gabarits, `PathId`.
- Non vérifiable par ces tests : rendu navigateur, clavier, contraste, focus (liste de contrôle manuelle, critère 12), `php -S` avec `public/assets/portal.css`, cookies de session réels.

### B2 — conception (proposé, à valider; B1 elle-même n'est pas encore validée)

Statuts : PW1 à PW12 **validés**; tout ce qui suit est **proposé**. B2 **dépend de B1** (garde Host/Origin/CSRF, session et flash, `View`, `Labels`, `Messages`, `DomainStatus`, `PathId`, page de détail) : elle n'est démarrable qu'après validation et livraison de B1. Pas de migration, pas de nouveau cas d'usage ni de nouvelle dépendance : `addRequiredAction`, `removeRequiredAction`, `patch`/`changeStatus`, `list`, `get` existent. Les formulaires de B2 sont des `POST` `application/x-www-form-urlencoded` avec `_csrf`, contrôlés par la garde de B1 (403 si jeton, `Origin` ou `Host` invalide, rien n'est exécuté).

**Routes** (identifiant de chemin invalide ou tâche inconnue : 404 HTML comme en B1; méthode non prévue : 405 + `Allow`) :

| Méthode, chemin | Champs | Cas d'usage | Succès (303, PRG) | Refus (page réaffichée avec le statut indiqué) |
|---|---|---|---|---|
| `POST /tasks/{id}/required-actions` | `required_id` | `get` (avant, pour le doublon) puis `addRequiredAction` | vers `/tasks/{id}`, flash « Action requise ajoutée. » (ou « Cette action requise était déjà présente. », voir plus bas) | 422 `required_task_not_found`, `dependency_self`, ou choix absent; 409 `dependency_cycle`; 404 tâche |
| `POST /tasks/{id}/required-actions/{requiredId}/delete` | — | `removeRequiredAction` | vers `/tasks/{id}`, flash « Action requise retirée. » (idempotent : même message si le lien n'existait plus) | 404 tâche |
| `POST /tasks/{id}/status` | `status` | `changeStatus` | vers `/tasks/{id}`, flash selon la transition (voir plus bas) | 409 `status_transition_invalid`, `required_actions_not_done`, `next_due_date_out_of_range`; 422 `field_type_invalid`/`status_invalid` (valeur falsifiée); 404 |
| `GET /tasks/{id}/reopen` | — | `get` | 200 page d'avertissement (PW9) si la tâche est périodique **et** `done`; sinon 303 vers `/tasks/{id}` | 404 |

Le portail n'appelle `changeStatus` que pour `POST /tasks/{id}/status` (jamais `patch` : la règle « `status` seul » ne le concerne pas). `status` absent est transmis comme `null` (le cas d'usage répond `field_type_invalid`).

**Réaffichage après refus** : pas de redirection; la page de détail est rendue avec le statut HTTP de l'erreur (même table `Http\DomainStatus` que REST), un bloc d'erreur `role="alert"` en tête de la section concernée (« Actions requises » ou « État »), la saisie du formulaire de lien conservée. Aucune écriture n'a eu lieu (tâche et `updated_at` inchangés).

**Actions requises** (section de la page de détail) :
- Liste : chaque action requise = titre en lien vers `/tasks/{id}`, état en libellé, bouton « Retirer » (formulaire `POST`, texte accessible « Retirer <titre> ») sans page de confirmation (opération réversible et idempotente).
- Formulaire d'ajout : liste déroulante `required_id` alimentée par **toutes** les tâches (`list()`, PW8 option A) sous la forme « titre — état », première option vide « Choisir une tâche… ». La tâche elle-même est listée (suffixe « (cette tâche) » : présentation), car exclure d'avance des candidats est l'option B écartée; les refus restent ceux du cas d'usage.
- Choix absent, vide ou non entier (`PathId`) : le portail ne peut pas appeler le cas d'usage; il répond 422 avec un message propre au portail, `Choisissez une tâche.` (erreur de forme du formulaire, pas une règle métier). Un identifiant entier inexistant donne `required_task_not_found` du service.
- **Doublon = succès, pas refus** (idempotence **validée** pour la couche commune, REST l'applique) : l'ajout répété réussit sans lien en double. Pour informer, le portail lit la tâche (`get`) avant l'appel; si `required_id` figure déjà dans `required_actions`, le flash dit « Cette action requise était déjà présente. ». Information de présentation seulement (aucune décision); l'écart avec la demande « refus doublon » est volontaire et à signaler à l'utilisateur.
- Aucun avertissement quand on ajoute une action requise non terminée à une tâche `done` (PW8, Q11).

**Changement d'état** (section « État » de la page de détail) :
- Boutons : un formulaire `POST /tasks/{id}/status` par cible de `TaskStatus::from($task['status'])->allowedTargets()` (table unique du domaine, non recopiée), champ caché `status` = code. Libellés selon (source, cible) : `todo -> in_progress` « Démarrer »; `in_progress -> done` « Terminer »; `in_progress -> todo` « Remettre à faire »; `done -> todo` « Rouvrir ». Aucun bouton pour une transition refusée. L'état courant est affiché en texte.
- Le portail ne prédit pas les refus (pas de contrôle des actions requises avant d'afficher « Terminer ») : c'est le cas d'usage qui arbitre, y compris en cas de page périmée (deux onglets).
- Succès, flash (`type` = success) : démarrage « Tâche démarrée. »; remise à faire « Tâche remise à faire. »; terminaison sans occurrence « Tâche terminée. »; **terminaison d'une périodique** (`next_occurrence` non `null`, PW7) « Tâche terminée. Prochaine occurrence : « <titre> », échéance <JJ/MM/AAAA>. » avec lien « Voir la prochaine occurrence » vers `/tasks/{id de l'occurrence}`; titre et date échappés/formatés par `Labels`; réouverture « Tâche rouverte. ».
- Refus, messages français (`Messages`) :
  - `status_transition_invalid` (409) : « Ce changement d'état n'est plus possible : la tâche est « <libellé from> » » (libellés tirés de `details.from`/`details.to`), tâche inchangée.
  - `required_actions_not_done` (409) : « Terminaison impossible : ces actions requises ne sont pas terminées : » suivi de la liste (`details.required_actions`, titre en lien et état), tâche inchangée, aucune occurrence créée.
  - `next_due_date_out_of_range` (409) : « La prochaine échéance dépasserait le 31/12/9999 : la tâche ne peut pas être terminée. », tâche inchangée.
  - `dependency_self` : « Une tâche ne peut pas se requérir elle-même. »; `dependency_cycle` : « Ajouter cette action requise créerait un cycle. »; `required_task_not_found` : « Cette action requise n'existe plus. »; `task_not_found` : page 404.

**Réouverture et avertissement (PW9) — proposé, à valider.**
- Le bouton « Rouvrir » d'une tâche `done` **non périodique** est un formulaire `POST /tasks/{id}/status` direct. Pour une tâche `done` **périodique**, c'est un lien vers `GET /tasks/{id}/reopen`, page qui nomme la tâche et affiche : « Cette tâche est périodique. Une occurrence suivante a pu être créée quand vous l'avez terminée; la rouvrir peut créer un doublon. » avec un bouton « Rouvrir quand même » (formulaire `POST /tasks/{id}/status`, `status=todo`) et un lien « Annuler » vers le détail. Aucun blocage; l'étape n'est pas une règle du serveur (un `POST` direct fonctionne), c'est une protection de l'interface.
- **Écart avec PW9 tel que formulé** (« déjà renouvelée ») : aucune filiation entre occurrence et source n'existe (Q3/Q4), donc l'avertissement vaut pour toute périodique terminée, qu'une occurrence existe ou non. Détecter par similarité (titre, catégorie, échéance attendue) est écarté : heuristique fragile qui recopierait la règle de calcul du service.
- **Alternative : filiation** (décision produit, à ne pas lancer sans l'utilisateur). Migration `0003` additive : colonne `tasks.previous_occurrence_id bigint NULL REFERENCES tasks(id) ON DELETE SET NULL` (+ index), renseignée par `changeStatus` lors de la création de l'occurrence; `Task` gagnerait `next_occurrence_id` (ajout compatible REST/MCP); requête et tests en plus dans `TaskRepository`/`TaskService`. Coûts : une migration (et son test de schéma, rejouabilité), une modification de la couche commune, **remise en cause de la décision Q4 « pas de lien de filiation »** (validée par délégation) et impossibilité de renseigner les occurrences déjà créées (aucune donnée de production aujourd'hui, donc coût réel faible). Gain : l'avertissement ne s'affiche que si une occurrence existe réellement, et le détail peut la lier. Sans effet sur la conception des routes ci-dessus (seule la condition d'affichage de `/reopen` change).

**`status` dans `required_actions` : non nécessaire pour B2.** La page de détail et le formulaire d'ajout s'appuient sur le même `list()` indexé par `id` (une requête, déjà prévue en B1) pour l'état des actions requises et la liste des candidats. L'ajout de `status` aux éléments `{id,title}` de `required_actions` serait une modification **d'un contrat partagé** (REST, futur MCP; ajout compatible, tests à la forme exacte de `Task` à mettre à jour) : signalée, **non demandée**, à revoir seulement si le MCP ou REST en a besoin.

**Impact sur B1** (pages existantes, sans changement de route) :
- Détail : la section « Actions requises » (lecture seule en B1) gagne les boutons « Retirer » et le formulaire d'ajout; une section « État » apparaît (état en texte + boutons). Pour que B2 n'ajoute que des ajouts, B1 découpe dès maintenant le gabarit de détail en partiels (`task_required_actions.php`, `task_status.php`, B1 n'affichant que la lecture).
- Liste : inchangée (aucune action d'état dans la liste; les terminaisons se font depuis le détail). `hide_done` et le filtre `status` de B1 prennent leur sens avec les changements d'état.
- Flash : la structure de B1 (`{type, text, link?}`) suffit; B2 n'y ajoute rien. Suppression : inchangée (`task_is_required` s'applique aussi aux tâches `done`; après une terminaison périodique les liens restent sur l'ancienne occurrence, Q3).
- `Messages` et `Labels` : ajout des textes ci-dessus et des libellés de boutons (présentation).

**Tests attendus (B2, avant le code)** — `tests/Integration/PortalTasksActionsTest` (PostgreSQL réel, `ArraySession`, comme B1; données créées par `TaskService`) :
- Liens : ajout puis détail listant la tâche requise (titre, état, lien); cycles A→B→A et A→B→C→A : 409, message « créerait un cycle », aucun lien créé; auto-lien : 422, message; `required_id` inexistant : 422; choix vide ou `abc` : 422 « Choisissez une tâche. » sans appel au service; doublon : 303, flash « déjà présente », un seul lien, `updated_at` inchangé; retrait existant et absent : 303, flash identique; tâche inconnue : 404; ajout d'une action non terminée à une `done` accepté sans avertissement.
- États : les boutons affichés pour chacun des trois états correspondent exactement à `allowedTargets()`; chaque transition permise (4) : 303 et flash, état et `completed_at` visibles au détail; transitions refusées (`todo -> done`, `done -> in_progress`, même état, par `POST` direct) : 409, message avec libellés, tâche et `updated_at` inchangés; terminaison avec actions requises non terminées : 409, liste exacte (titres en liens, états), aucune occurrence; avec toutes `done` ou sans action : réussit; **périodique** : flash avec titre, échéance `JJ/MM/AAAA` (31/01 + 1 mois = 28/02) et lien vers l'occurrence, laquelle existe, `todo`, sans lien; non périodique : « Tâche terminée. » seul, une seule tâche; dépassement `9999-12-31` : 409, message, tâche inchangée; deuxième terminaison : 409 `status_transition_invalid`, une seule occurrence; `status` falsifié (`foo`, absent) : 422.
- Réouverture : `GET /reopen` d'une périodique `done` : 200 avec avertissement et bouton; non périodique ou non `done` : 303; `POST` direct `status=todo` fonctionne sans passer par la page (l'avertissement n'est pas une règle); `completed_at` effacé.
- Transverses : 403 sans jeton, `Origin` étranger ou `Host` hors liste, aucune écriture; aucun `GET` n'écrit; échappement d'un titre `<script>` dans les messages de refus, le flash de terminaison et la liste du `<select>`; parité REST : mêmes `code` refusés pour les mêmes entrées (cycle, transition, prérequis); 500/503 sans détail technique.
- Non vérifiable : rendu, clavier, focus, lecture d'écran des blocs `role="alert"` (liste de contrôle manuelle).

**Ordre d'implémentation et dépendances.** (0) B1 livrée et validée (préalable : garde, session/flash, `View`, `Labels`, `Messages`, `DomainStatus`, `PathId`, détail en partiels). (1) Section « État » : `POST /tasks/{id}/status`, boutons, flash de terminaison, refus 409 (les tests de transitions et de terminaison d'abord). (2) Actions requises : ajout, retrait, refus et doublon. (3) Réouverture avec avertissement (`/reopen`). (4) Transverses, README (parcours du portail), liste de contrôle d'accessibilité. Chaque étape est indépendante de la suivante et peut être commitée seule; (1) et (2) ne partagent que le gabarit de détail.

### Impact (analyse)

- **Schéma, migrations, dépendances Composer : aucun.** PHP natif (`session`, `random_bytes`, `hash_equals`, `DateTimeImmutable`, `mbstring` déjà en contrainte).
- **Consommateurs touchés** : `PortalTest` (page statique `GET /` : 200 + « L'application fonctionne. ») devient faux, car `GET /` redirige vers `/tasks` (le Developer le remplace par un test de redirection). Conséquence sur le squelette : le portail n'appelle plus `Diagnostic::ping()` (critère 7 du squelette ne vaut plus que pour REST et MCP); `/api/v1/health` reste le diagnostic. `Bootstrap::kernel` et `Request` : ajouts à paramètre facultatif (tests `HttpTestCase`, `RestTasksCrudTest` et autres appels existants valides). `RestAdapter` : extraction de `DomainStatus` et `PathId` sans changement de comportement (suite REST rejouée telle quelle). `Config` : une variable facultative de plus (`PORTAL_ALLOWED_HOSTS`), donc `ConfigTest` et `.env.example` à étendre.
- **Rupture** : aucune pour REST/MCP; le contenu de `GET /` change (page de squelette sans consommateur réel).
- **Sécurité** : voir « Garde de chaque requête portail ». Rappel : sans authentification, toute exposition (réseau local, déploiement) donne lecture et suppression de toutes les données; `bin/start` lie déjà `127.0.0.1`.
- **Hébergement** : sessions, racine `public/`, réécriture d'URL, service des fichiers statiques et variables d'environnement restent **non vérifiés** (tableau AlwaysData); aucun choix de B1 n'en dépend pour le local. Le déploiement, qui exige de toute façon l'authentification, les revérifiera. `README.md` et `bin/start` : ajouter l'adresse du portail (`http://127.0.0.1:8080/`).

### À faire valider avant implémentation

1. **B1 sous la forme décrite** : routes et statuts, formulaire, garde Host/Origin/CSRF, en-têtes, session native avec interface `Session`.
2. **`GET /` redirige vers `/tasks`** et le test du squelette est remplacé (le portail n'appelle plus `Diagnostic`).
3. **Contrôle de `Host`** et variable `PORTAL_ALLOWED_HOSTS` (ajout non demandé par PW10; défaut sans configuration).
4. **`hide_done` en filtre de présentation** dans le portail (PW6) plutôt qu'un filtre d'exclusion ajouté à `TaskService::list` (contrat partagé REST/MCP).
5. **État des actions requises** lu par `list()` en B1; ajout de `status` à `required_actions` seulement si B2 ou MCP le demande.
6. **Extractions `Http\DomainStatus` et `Http\PathId`** dans `RestAdapter` (comportement inchangé).
7. **PW9, avertissement de réouverture** : valable pour toute périodique terminée, et non « déjà renouvelée » (voir B2); ou filiation par migration (décision produit).
8. **B2** (après B1) : routes et flash décrits, doublon de lien traité en succès avec information (idempotence validée), pas de `status` ajouté à `required_actions`, réouverture par page de confirmation.

## Dépendances Composer (proposé)

| Paquet | Type | Justification |
|---|---|---|
| `php` `>=8.3` | contrainte | Versions supportées par PHPUnit récent, et disponibles chez AlwaysData jusqu’à 8.5 (8.3 à confirmer dans le panneau). |
| `ext-pdo`, `ext-pdo_pgsql`, `ext-json`, `ext-mbstring` | contraintes | Accès PostgreSQL et JSON; présentes dans le `php.ini` d’exemple AlwaysData (PHP 8.2). À confirmer localement. |
| `phpunit/phpunit` (dernière majeure compatible PHP 8.3) | dev uniquement | Standard de fait pour les tests; évite d’écrire un moteur de test. Non installé en production (`composer install --no-dev`). |

Aucune dépendance d’exécution : routeur, JSON-RPC, chargeur de configuration et exécuteur de migrations sont écrits à la main, car minimes. Composer ne sert qu’à l’autoload PSR-4 et aux scripts (`composer test`, `composer migrate`). Pas de PSR-7/PSR-15, pas de conteneur d’injection, pas de bibliothèque dotenv.

## Tests (proposé)

- PHPUnit; `composer test` exécute tout, `composer test:unit` exécute sans base.
- `tests/Unit` (chargeur de configuration, parsing JSON-RPC), `tests/Http` (appel du front controller avec un objet `Request`, sans serveur web : REST 200/503/404/405, MCP initialize/tools/list/ping/erreurs, portail), `tests/Integration` (PostgreSQL réel : `Diagnostic::health`, migrations sur base vide, rejouabilité, détection de checksum modifié).
- Les tests d’intégration utilisent une base dédiée `actions_test` (`APP_ENV=test`), jamais la base de développement; ils échouent explicitement si la base est absente (pas de skip silencieux, afin de couvrir les critères 1 et 4).
- Répartition constatée : le test 200 de `health` est en `tests/Integration` (base requise); la suite `tests/Http` couvre 503/404/405 (acceptée, sans changement de contrat).
- Le cas 503 se teste avec une configuration pointant un port fermé.

## Démarrage local avec PostgreSQL (proposé)

Disponibilité de Docker/Podman ou de PHP sur le poste : **non vérifiée**.

1. Prérequis : PHP >= 8.3 avec `pdo_pgsql`, Composer, Docker (ou Podman). Alternative sans conteneur : PostgreSQL 16 à 18 installé via le gestionnaire de paquets de WSL.
2. `compose.yaml` : service `postgres` image `postgres:17` (versions 16 à 18 gérées par AlwaysData), port publié `127.0.0.1:${DB_PORT:-5432}:5432` (toujours lié à `127.0.0.1`; `DB_PORT` permet d'éviter un 5432 local déjà pris), volume nommé, `POSTGRES_USER`, `POSTGRES_PASSWORD` et `POSTGRES_DB=actions` lus depuis `.env`.
3. `cp .env.example .env`, renseigner un mot de passe local; `docker compose up -d`.
4. Créer la base de test : `docker compose exec postgres createdb -U <user> actions_test`.
5. `composer install`, puis `php bin/migrate` (crée seulement `schema_migrations`).
6. Lancer : `php -S 127.0.0.1:8080 -t public public/index.php`; vérifier `curl http://127.0.0.1:8080/api/v1/health`.
7. Tests : `composer test`.

Le serveur n’est lié qu’à `127.0.0.1` (aucune authentification dans cette tranche).

## Sécurité, erreurs

- Validation côté serveur de toute entrée (JSON-RPC, paramètres); requêtes PDO préparées avec `ATTR_EMULATE_PREPARES=false` et `ERRMODE_EXCEPTION`.
- Les erreurs exposent un code et un message générique; détails dans le journal serveur uniquement; `display_errors` désactivé hors `local`.
- Authentification : aucune dans le squelette; requise avant toute exposition (élément de backlog séparé, mode non choisi).

## Points à faire valider

Tranche A : tout ce qui est « proposé » dans « Couche commune des tâches » (en particulier les points marqués « à valider/confirmer ») est à faire valider avant implémentation. Voir aussi le rapport de l’Architecte : contrats REST/MCP ci-dessus, dépendances, mécanisme de migrations (table de suivi), questions ouvertes. Pour l’état des décisions produit, voir [BACKLOG.md](../BACKLOG.md).
