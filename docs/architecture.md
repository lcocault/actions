# Architecture

Légende des statuts : **existant** (dans le dépôt, vérifiable), **proposé** (soumis à validation, non opposable au Developer), **validé** (accepté par l’utilisateur). Sans mention, un élément est **proposé**.

## État du dépôt

**Existant** (relu le 2026-10-11) : documentation; squelette (`src/`, `bin/migrate`, `compose.yaml`, `.env.example`, `tests/`, `README.md`); migration `database/migrations/0001_tasks_and_dependencies.sql` (tables `tasks`, `task_dependencies`, trigger anti-cycle) et `tests/Integration/TaskSchemaTest.php`, appliqués en local seulement (rien sur AlwaysData). La couche commune des tâches (tranche A) existe : `TaskService`, `TaskValidator`, `TaskRepository`, six erreurs de domaine, `Bootstrap::taskService()`, `TaskValidatorTest`, `TaskServiceTest`; aucun adaptateur (REST, MCP, portail) ne l'appelle encore, `RestAdapter` ne connaît que `GET /api/v1/health`. La tranche REST « dépendances » (`GET`/`DELETE /tasks/{id}`, liens `PUT`/`DELETE`) existe en local (`TaskController`, `RestAdapter`, `RestTasksTest`); le contrat de `POST`/`GET /tasks` et `PATCH /tasks/{id}` est écrit (section « Tranche CRUD REST »), non implémenté. Relecture de l'Architecte au 2026-10-11 : SQL de 0001, `TaskRepository`, `TaskService`, `RestAdapter`, `Kernel`, `Bootstrap`, `Request`/`Response`, tests `TaskSchemaTest`, `TaskServiceTest` (noms et passages cités) et `RestTest`. Aucun test n'a été rejoué par l'Architecte (pas d'exécution possible dans son rôle) : la vérification reste celle du Developer/PO.

**Relecture MCP de l'Architecte (2026-10-12)** : lus `src/Mcp/McpAdapter.php`, `TaskService`, `TaskController`, `RestAdapter`, `DomainStatus`, `PathId`, `Request`, `Response`, `Config`, `Bootstrap`, `PortalAdapter` (garde `Host`/`Origin`), `TaskStatus`, passages de `TaskValidator`, `tests/Http/McpTest.php` (début) et `HttpTestCase`. **Existant** : le serveur MCP du squelette expose seulement `ping`; `Bootstrap::kernel` ne lui passe pas `TaskService`; REST, portail B1/B2 et états sont livrés en local (voir BACKLOG, non commités). Les sections plus anciennes ci-dessous qui disent « non livré » pour le cycle de vie et le portail décrivent la conception d'origine; le code les a rattrapées. La conception MCP des tâches est dans « Contrat MCP des tâches (tranche MCP M1) ».

**Tranche « Projet et Conclusions » (2026-10-13)** : besoin validé par l'utilisateur, conception **validée** et livrée en local (migration `0003`, contrats, portail, tests) dans « Champs "Projet" et "Conclusions" (tranche P) » plus bas. Conception validée et livrée en local (voir la section); lus par l'Architecte pour l'analyse d'impact : `0002`, `TaskRepository`, `TaskValidator`, `TaskService`, `TaskController`, `TaskTools`, `TaskPages`, `TaskForm`, `DomainStatus`, `Messages`, vues `task_list`/`task_form`/`task_detail`, `TaskSchemaTest` (passage `0002`), assertions de `McpToolsTest`. Aucun test rejoué.

**Tranche « Projets configurables » (2026-10-15)** : décisions PC1 à PC11 validées par l'utilisateur (via le backlog); conception **validée** (2026-10-15), non implémentée, dans « Projets configurables et catégorie portée par le projet ». Lus par l'Architecte : `TaskRepository`, `TaskValidator`, `TaskService`, `TaskController`, `RestAdapter`, `DomainStatus`, `Bootstrap`, `Migrator`, `InvalidTask`, `TaskIsRequired`, `ErrorBody`, migrations `0002` et `0003`, `DbTestCase`; `TaskTools`, `TaskPages`, `TaskForm` et les vues par recherche ciblée seulement.

**Tranche « Importance d'une action » (2026-10-17)** : besoin et décisions I1 à I8 **validés** par l'utilisateur (transmis par le coordinateur, voir BACKLOG); conception **validée** par l'utilisateur et **livrée** par le Developer (migration `0005` appliquée en local seulement; 745 tests OK sur PostgreSQL 17 local, rapporté par le Developer, non rejoué par l'Architecte), dans « Importance d'une action ». Lus par l'Architecte à la conception : `TaskRepository`, `TaskValidator`, `TaskService`, `TaskTools` (schémas), `TaskPages` (filtres), migrations `0002`, `0004` (en tête), `database/migrations/README.md`, assertions de tests citées par recherche ciblée. Relus à la livraison (recherche ciblée) : `0005_task_importance.sql`, `TaskValidator`, `TaskRepository`, conformes à la section.

**Tranche 2 des projets, vue projet, synthèse et matrice (2026-10-18)** : conception **validée** par l'utilisateur et **livrée en local** (rapporté par le Developer : phpunit 815 tests OK, smoke HTTP local; **non vérifié** : navigateur, AlwaysData, non rejoué par l'Architecte), dans « Gestion des projets dans le portail, vue projet, synthèse et matrice d'Eisenhower (tranche 2) ». Relu par l'Architecte à la livraison : `src/Http/Kernel.php` seulement (routage `/projects`). Lus à la conception : `ProjectService`, `ProjectRepository`, `PdoRepository`, `TaskService`, `TaskRepository` (colonnes, `findAll`), `PortalAdapter`, `TaskPages`, `Messages`, `Labels`, `DomainStatus`, `NextDueDate`, `Bootstrap`, `TaskTools`, `ToolArguments`, `McpAdapter` (recherches ciblées), `ProjectInUse`, `ProjectNameTaken`, `layout.php` (navigation). Aucun test rejoué.

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

- **Validé** (utilisateur, 2026-10-12, décisions M1 à M4 du backlog) : MCP des tâches en local sans authentification (l'authentification reste un élément P1 avant toute exposition); huit outils (CRUD, actions requises, états) avec un outil d'état séparé `change_task_status`, `update_task` refusant `status`; suppression exigeant `confirm: true`; mêmes champs et mêmes codes que REST, résultat en texte JSON, erreurs en `isError` avec le même JSON que REST. La **conception** qui en découle (noms d'arguments, schémas, codes propres à MCP, organisation) est **proposée** : voir « Contrat MCP des tâches (tranche MCP M1) ». M5 à M7 : instruites, recommandations **proposées**; M8 : à trancher par l'utilisateur.

- **Validé** (utilisateur, 2026-10-12) : servir l'application sous le préfixe `/actions` (`http://cocault.alwaysdata.net/actions/`) en gérant le préfixe dans le code (et non par un sous-domaine). La conception a été soumise puis approuvée par l'utilisateur (« Tu peux soumettre au développeur », 2026-10-12), et **implémentée** en local (**existant**) : voir « Préfixe d'URL (`APP_BASE_PATH`) ». Rien n'est vérifié sur AlwaysData.

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
| Délais maximaux d’exécution/requête | **Non vérifié** | Sans objet pour le squelette (réponses JSON courtes). Le `php.ini` d'exemple (PHP 8.2) fixe `max_execution_time = 120`, `memory_limit = 256M`, `post_max_size = 256M` (page de configuration PHP, relue le 2026-10-12); ce sont des valeurs d'exemple, pas une garantie pour le compte cible. |
| Extensions `fileinfo`, `ctype` (en plus de celles ci-dessus) | Vérifié pour l'exemple PHP 8.2 (2026-10-12) | même page. Autres versions de PHP non vérifiées. Pertinent seulement pour le SDK MCP (voir M5). |
| Site sous un préfixe d'URL (`cocault.alwaysdata.net/actions`) : correspondance préfixe/racine `public/`, `RewriteBase`, `Host` et HTTPS vus par PHP | **Partiellement vérifié** (2026-10-12) | La documentation Marketplace (`/en/docs/development/marketplace/build-application-script/`) montre qu'une adresse peut porter un chemin (`foo.example.net/test`, « site root (base URL) ») mais ne dit pas comment le chemin correspond au dossier racine. Le reste : **non vérifié**; voir « Préfixe d'URL ». |
| Outils MCP (`POST /mcp`) servis par PHP/Apache, `Host` et `Origin` reçus tels quels | **Non vérifié** | Aucun essai sur un compte; hors périmètre de la tranche MCP M1 (local). À tester au déploiement, avec l'authentification. |

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

Alternative écartée : SDK PHP MCP tiers. Réévaluée le 2026-10-12 pour l'arrivée des outils des tâches : voir « M5 » dans « Contrat MCP des tâches ». Le contrat ci-dessus reste **existant** et sert de base; les ajouts (outils, contrôle `Host`, en-tête de version) sont **proposés** dans la section suivante.

## Contrat du portail

**Existant** (squelette) : `GET /` : 200 `text/html; charset=utf-8`, page HTML statique indiquant que l’application fonctionne, via `Diagnostic::ping()`; `PortalTest` (2 tests). **Proposé** : cette page est remplacée par le portail des tâches, voir « Portail web (tranches B1 et B2) » plus bas.

## Configuration (proposé)

- Lue depuis des variables d’environnement ; en local, un chargeur minimal (une trentaine de lignes, sans dépendance) lit un fichier `KEY=VALUE` dont le chemin vient de `ACTIONS_ENV_FILE` (défaut : `.env` à la racine, ignoré par Git). Les variables déjà définies priment.
- Variables : `APP_ENV` (`local`, `test`, `production`; **défaut `production` si absente**, choix le plus restrictif pour le garde-fou des migrations), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `MCP_ALLOWED_ORIGINS` (liste séparée par des virgules, vide par défaut), `TEST_DB_NAME` (défaut `actions_test`), `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` (utilisées par `compose.yaml` seulement).
- État au 2026-10-10 : implémenté par le Developer (existant, relu dans `src/Infrastructure/Config.php`, `compose.yaml`, `.env.example`); le comportement reste à valider par l'utilisateur.
- `.env.example` fourni, sans secret; `.env` et tout fichier de secrets dans `.gitignore`.
- **Existant** (portail, relu dans `Config`) : `PORTAL_ALLOWED_HOSTS` (noms d'hôte sans port, séparés par des virgules; défaut `127.0.0.1,localhost,[::1]`).
- **Proposé** (MCP, M7) : `MCP_ALLOWED_HOSTS`, même format et même défaut; variable absente ou vide = défaut. Séparée de la variable du portail pour que l'exposition de chaque interface se décide à part; argument ajouté en dernier à `Config::__construct` (appels existants valides).
- **Existant** (préfixe d'URL, relu dans `Config` et `.env.example`) : `APP_BASE_PATH`, vide par défaut = aucun préfixe; format et validation dans « Préfixe d'URL ».
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
`{id:int, title:string, category:"Pro"|"Perso", due_date:"YYYY-MM-DD"|null, recurrence:{interval:int, unit:"day"|"week"|"month"|"year"}|null, estimated_minutes:int|null, description:string|null, required_actions:[{id:int,title:string}], created_at:string, updated_at:string}`. Horodatages ISO 8601 UTC (`2026-10-11T08:30:00Z`). Évolution **validée et livrée** (2026-10-13) : clés `project` et `conclusions` ajoutées, voir « Champs "Projet" et "Conclusions" ». Les unités sont les codes anglais de la base; les libellés français relèvent des interfaces. `required_actions` trié par `id`.

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
- **URL et préfixe** : tous les liens et actions de formulaire sont produits par un seul assistant `$url('/tasks/12')`, pour pouvoir préfixer plus tard (`/index.php` si la réécriture est indisponible sur AlwaysData). Non conçu davantage (tranche de déploiement). **Mise à jour 2026-10-12** : le préfixe est désormais conçu (`APP_BASE_PATH`, voir « Préfixe d'URL ») et `View` en porte déjà le paramètre `$prefix` (existant).

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

- `Kernel` : le portail reçoit `/`, `/tasks` et `/tasks/...` (depuis la tranche 2 : aussi `/projects` et `/projects/...`) (aucun conflit avec `/api/v1` ni `/mcp`); les autres chemins inconnus restent en 404 JSON (existant). `Bootstrap::kernel(Config, ?Session = null)` : `NativeSession` par défaut; `PortalAdapter` reçoit `TaskService`, `Session` et les hôtes autorisés. Signature compatible avec les appels existants.
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

## Contrat MCP des tâches (tranche MCP M1) — conception du 2026-10-12, **non livrée**

Statuts. **Validé** (utilisateur) : M1 à M4 (voir « Décisions validées »). **Existant** : serveur du squelette (`McpAdapter`, transport, `ping`), `TaskService` et ses erreurs, table `DomainStatus`. **Proposé** : tout le reste (noms d'arguments, schémas, résultats non couverts par REST, deux codes propres à MCP, organisation du code, gardes `Host` et version de protocole, M5 à M7), non opposable au Developer avant validation. Aucune migration, aucune dépendance, aucune règle métier nouvelle.

### Principes

- MCP est un quatrième consommateur de `TaskService` (avec REST et portail) : **chaque outil = un appel de cas d'usage + une traduction**. Aucune règle de validation, de transition, de cycle ni de suppression dans `Mcp\`.
- Transport inchangé (voir « Contrat MCP du squelette ») : `POST /mcp`, sans session, JSON seul, pas de SSE, pas d'authentification (local, `127.0.0.1`).
- **Succès** : `result` = `{"content":[{"type":"text","text":"<JSON>"}],"isError":false}`; `text` est le JSON compact (mêmes options que `Response::json` : UTF-8 non échappé, `/` non échappé) de la valeur renvoyée par REST pour la même opération. Pas de `structuredContent` ni d'`outputSchema` (M4 option (a); option (b) possible plus tard sans rupture).
- **Erreur de domaine ou de validation** : `result` = `{"content":[{"type":"text","text":"{\"error\":{\"code\":...,\"message\":...,\"details\":...}}"}],"isError":true}`; le JSON du texte est **octet pour octet celui d'un corps d'erreur REST** (`details` absent s'il est vide). Pas de statut HTTP côté MCP : la réponse HTTP est toujours 200.
- **Erreur de protocole** (JSON-RPC, inchangé) : outil inconnu, `name` absent, `arguments` présent mais qui n'est pas un objet JSON : `-32602`. Erreur imprévue (dont toute `PDOException`, tout `DomainError` dont le `code` n'est pas dans `DomainStatus`) : `-32603` « Erreur interne. », détail au journal seulement (classe, SQLSTATE d'une `PDOException`, message), comme `RestAdapter`. `DatabaseUnavailable` : résultat `isError` de code `service_unavailable` (message « Service indisponible. »), comme REST 503.
- Les clés absentes et les `null` gardent le sens de REST : à la création, absent ou `null` = non renseigné; à la modification, absent = inchangé, `null` = effacé pour les champs facultatifs.

### Les neuf outils (huit outils des tâches + `ping`, existant)

Ordre de `tools/list` : déterministe, celui du tableau. Tous les `inputSchema` sont `{"type":"object","properties":{...},"required":[...],"additionalProperties":false}`. Les descriptions sont en français. Les schémas **décrivent** les règles sans les appliquer : le serveur valide toujours lui-même (le client peut ignorer le schéma); un test vérifie que les énumérations des schémas sont celles du domaine (`TaskStatus::codes()`, catégories, unités), pour éviter toute dérive.

Définitions communes : `id` = `{"type":"integer","minimum":1}`. Champs de tâche : `title` string (`maxLength` 255), `category` `enum ["Pro","Perso"]`, `due_date` `["string","null"]` (`format: date`, `AAAA-MM-JJ`), `recurrence` `["object","null"]` (`interval` integer `minimum` 1, `unit` `enum ["day","week","month","year"]`, les deux obligatoires, `additionalProperties: false`), `estimated_minutes` `["integer","null"]` (`minimum` 1, en minutes), `description` `["string","null"]`.

| Outil | Arguments (obligatoires en gras) | Cas d'usage appelé | Valeur du texte JSON | Annotations |
|---|---|---|---|---|
| `create_task` | **`title`**, **`category`**, `due_date`, `recurrence`, `estimated_minutes`, `description` | `create(args)` | `Task` créée (comme le corps du 201) | — |
| `get_task` | **`id`** | `get(id)` | `Task` | `readOnlyHint` |
| `list_tasks` | `status` (`todo`/`in_progress`/`done`), `category` | `list(args)` | tableau de `Task` (`[]` si aucune), ordre du cas d'usage | `readOnlyHint` |
| `update_task` | **`id`**, puis tout champ de `create_task` (sauf obligation) | `update(id, args sans id)` | `Task` à jour | — |
| `delete_task` | **`id`**, **`confirm`** (`{"type":"boolean","const":true}`) | `delete(id)` | `{"deleted":true,"id":N}` | `destructiveHint` |
| `add_required_action` | **`id`**, **`required_task_id`** | `addRequiredAction(id, required_task_id)` | `Task` de `id` | `idempotentHint` |
| `remove_required_action` | **`id`**, **`required_task_id`** | `removeRequiredAction(id, required_task_id)` | `Task` de `id` | `idempotentHint` |
| `change_task_status` | **`id`**, **`status`** (énumération des trois codes) | `changeStatus(id, status)` | `Task` à plat plus `next_occurrence` (`Task` ou `null`, **toujours présente** ici car l'outil change toujours l'état) | — |

Précisions de contrat :
- **`update_task` appelle `TaskService::update`, jamais `patch`** : `patch` aiguille `status` vers `changeStatus`, ce qui ferait d'`update_task` un second outil d'état. Avec `update`, `status` (comme `id` dans `create_task`, `required_actions`, `created_at`, `updated_at`) est `field_unknown` avec `details.field` : **confirmé** (clé hors de la liste fermée du validateur, vérifié par lecture de `TaskValidator::FIELDS`). La description d'`update_task` dit d'utiliser `change_task_status` pour l'état. `update_task` avec le seul `id` est un no-op valide (la tâche est renvoyée, `updated_at` inchangé).
- **`list_tasks`** : les arguments sont transmis tels quels à `list`, qui refuse une clé inconnue (`field_unknown`), un non-chaîne (`field_type_invalid`) et une valeur hors liste (`status_invalid`/`category_invalid`), toujours avec `details.field`. `status: null` est donc refusé (REST refuse aussi la valeur vide). Pas de pagination (M6, plus bas).
- **`change_task_status`** : `status` absent est transmis comme `null` (réponse `field_type_invalid`, comme le portail). Même état ou transition interdite : `status_transition_invalid`; prérequis : `required_actions_not_done` (avec la liste); dépassement de date : `next_due_date_out_of_range`. Aucune règle de transition dans l'adaptateur : le schéma ne liste que les trois codes.
- **`delete_task`** : sans `confirm` strictement `true` (absent, `false`, chaîne, nombre) : erreur `confirmation_required`, **avant tout accès à la base** (tâche intacte, même si l'`id` est inconnu). Puis `delete` : `task_not_found` (404 REST), `task_is_required` avec `dependents`. La description du schéma dit : destructif et sans retour; demander l'accord de l'utilisateur avant d'appeler. **Limite à connaître (à valider)** : `confirm: true` est un booléen produit par le modèle; il protège d'un appel accidentel, pas d'un appel délibéré ou induit par un texte hostile présent dans une tâche (un titre ou une description sont renvoyés au modèle comme données). La vraie confirmation humaine dépend de l'invite d'approbation d'outil du client; l'annotation `destructiveHint` l'y aide (les annotations ne sont pas fiables par nature selon la spécification, consultée le 2026-10-12).
- **Résultat de suppression** `{"deleted":true,"id":N}` : seule forme sans équivalent REST (REST répond 204 sans corps); proposée.
- **Identifiants** : `id` et `required_task_id` doivent être des entiers JSON positifs (`is_int`, donc `1.0`, `"5"`, `0`, `-1` et les nombres hors `int` PHP, décodés en `float`, sont refusés). Pas de `PathId` (il prend une chaîne).

### Codes d'erreur propres à MCP (proposé)

Erreurs de **forme de l'appel**, que `TaskService` ne peut pas voir. Mêmes principes que `invalid_json` pour REST (erreur de transport, pas une règle métier) :

| `code` | Quand | `details` | Message (français) |
|---|---|---|---|
| `argument_invalid` | `id` ou `required_task_id` absent ou qui n'est pas un entier positif | `{field}` | « L'identifiant doit être un entier positif. » |
| `confirmation_required` | `delete_task` sans `confirm: true` | `{field: "confirm"}` | « Suppression non confirmée : renvoyez confirm: true après l'accord de l'utilisateur. » |
| `field_unknown` (**existant**, réutilisé) | clé inconnue dans les outils dont les arguments ne vont pas jusqu'au service (`get_task`, `delete_task`, `add_required_action`, `remove_required_action`, `change_task_status`) | `{field}` | « Champ inconnu. » |

Pour `create_task`, `update_task` et `list_tasks`, les clés inconnues sont refusées par le service (même `code`, même `details`) : l'adaptateur ne contrôle les clés que là où il garde des arguments fixes. Ordre des contrôles d'un appel : outil connu (`-32602`) -> `arguments` objet (`-32602`) -> clés inconnues propres à l'adaptateur (`field_unknown`) -> forme des identifiants (`argument_invalid`) -> confirmation (`confirmation_required`) -> cas d'usage (erreurs de domaine). Tous les autres codes sont ceux du tableau « Erreurs de domaine » et de « Codes de validation » (REST) : `title_blank`, `title_too_long`, `text_invalid`, `category_invalid`, `due_date_invalid`, `recurrence_*`, `estimated_minutes_invalid`, `field_type_invalid`, `field_unknown`, `status_invalid`, `task_not_found`, `required_task_not_found`, `dependency_self`, `dependency_cycle`, `task_is_required`, `status_transition_invalid`, `required_actions_not_done`, `next_due_date_out_of_range`, `service_unavailable`. Pas de `status_not_alone` possible par MCP (aucun outil n'appelle `patch`).

### Organisation du code (proposé)

```
src/Mcp/
  McpAdapter.php     Protocole : gardes HTTP, JSON-RPC, initialize, tools/list, aiguillage tools/call, conversion des exceptions
  TaskTools.php      Les huit définitions (nom, description, inputSchema, annotations) et leur appel de TaskService
  ToolArguments.php  Forme des arguments seulement : arguments -> tableau PHP, clés inconnues, identifiants, confirm
src/Http/
  ErrorBody.php      NOUVEAU (extrait de RestAdapter) : DomainError -> {"error":{code,message,details?}} et assembleur par code/message/details
  JsonObject.php     NOUVEAU (extrait de TaskController::toArray) : stdClass -> tableaux associatifs (listes conservées)
  HostGuard.php      NOUVEAU (extrait de PortalAdapter::splitHost) : analyse de l'en-tête Host et contrôle contre une liste de noms
```

- `McpAdapter` garde le contrôle du protocole et reçoit `TaskService` et `list<string> $allowedHosts` (constructeur : `Diagnostic`, `TaskService`, `allowedOrigins`, `allowedHosts`). Il ne contient aucun nom de champ de tâche. `TaskTools` expose `definitions(): list<array>` et `call(string $name, array $arguments): array` (valeur à encoder); les exceptions de `TaskService` remontent à `McpAdapter`, **seul endroit** qui les transforme en `isError` ou `-32603` (comme `RestAdapter` pour HTTP).
- **Extractions sans changement de comportement** (suites REST et portail rejouées telles quelles) : `ErrorBody` pour que REST et MCP produisent le même JSON d'erreur au même endroit (sinon la règle « même JSON » serait tenue par deux copies); `JsonObject` pour ne pas recopier la conversion `stdClass` -> tableau (`TaskController::toArray`); `HostGuard` pour partager l'analyse de `Host` avec le portail (qui garde la comparaison de port d'`Origin`). Alternative écartée : décoder le message en `assoc=true` (perd la distinction objet/liste, dont dépendent `arguments` et `recurrence`).
- `TaskValidator` : rendre publiques les constantes `UNITS` et ajouter `CATEGORIES` (la valeur `Pro`/`Perso` y est aujourd'hui codée en dur) pour que les schémas en dérivent. Changement sans effet de comportement.
- `Bootstrap::kernel` : passe `taskService` et `$config->mcpAllowedHosts` à `McpAdapter`. `Kernel`, `Request`, `Response`, `TaskService`, dépôt, schéma : **inchangés**.
- `McpAdapter::VERSION` : passer à `0.2.0` (`serverInfo`), cosmétique.

### Gardes HTTP du transport (M7 et version de protocole)

Ordre proposé : méthode (405, existant) -> **`Host` (403, nouveau)** -> `Origin` (403, existant) -> `Content-Type` (415) -> taille -> JSON -> forme JSON-RPC -> **version de protocole (400, nouveau)**. Garder la méthode avant `Host` laisse le contrôle `GET`/`DELETE` -> 405 inchangé.

**M7 — contrôle de `Host` [recommandation : oui].** Contexte : le contrôle d'`Origin` existant refuse tout `Origin` absent de `MCP_ALLOWED_ORIGINS` (vide par défaut, donc tout navigateur est refusé) et accepte l'absence d'`Origin` (clients non navigateur, dont Claude Code). Un « rebond DNS » fait atteindre `127.0.0.1` par une page tierce sous un nom de domaine contrôlé par l'attaquant; la spécification MCP impose de valider `Origin` (403 si présent et invalide; textes des révisions 2025-06-18 et 2026-07-28 consultés le 2026-10-12) et conseille de lier le serveur à `127.0.0.1` et d'authentifier. `Host` est le second verrou : un navigateur ne peut pas lui faire porter `127.0.0.1` pendant que la page vient de `attaquant.example`. Coût : environ 10 lignes (extraction `HostGuard`), une variable `MCP_ALLOWED_HOSTS`; le portail applique déjà la même garde. Effet de bord : un client qui joint le serveur par un autre nom (alias, machine distante) reçoit 403 tant que le nom n'est pas déclaré; `Host` absent : 403 (comme le portail). Protection non absolue (sans authentification, tout processus local ou tout client qui forge `Host` reste admis). Impact tests : `HttpTestCase::mcp()` doit envoyer `Host: 127.0.0.1:8080`; test de 403 pour `Host` étranger, absent, mal formé; `GET /mcp` reste 405 sans `Host`. Alternative écartée : ne rien ajouter (le contrôle d'`Origin` suffit contre les navigateurs conformes); non retenue car la garde existe déjà côté portail et coûte peu.

**En-tête `MCP-Protocol-Version` (constat de l'Architecte, à valider).** La spécification 2025-06-18 impose `400` si la valeur est invalide ou non supportée; l'adaptateur actuel ignore l'en-tête. Révision courante au 2026-10-12 : **2026-07-28** (sans `initialize` ni sessions; chaque requête porte `MCP-Protocol-Version` et `_meta`), distincte des deux versions que le serveur accepte (`2025-06-18`, `2025-03-26`). Un client moderne peut tenter d'abord une requête « moderne » et, selon la spécification, se replier sur `initialize` si la réponse est un `400` dont le corps n'est pas une erreur moderne reconnue. Proposé : si l'en-tête est présent et hors des versions supportées, répondre `400` avec une erreur JSON-RPC `-32600` « Version de protocole non supportée. » ; **exception** : la méthode `initialize` n'est jamais refusée pour cet en-tête (la négociation passe par `protocolVersion` du corps; un client peut y mettre sa version la plus récente). En-tête absent : accepté (traité comme `2025-03-26`). Sans cette garde, un client moderne recevrait un résultat sans `resultType` et pourrait mal l'interpréter. **Non vérifié** : le comportement réel de Claude Code (dont son repli) face à ce `400`; à observer lors de la vérification M8. Support de la révision 2026-07-28 : hors tranche (voir « À valider »).

### M5 — étendre le serveur existant ou adopter un SDK PHP [recommandation : (a) étendre]

Faits (consultés le 2026-10-12) : SDK officiel `mcp/sdk` (dépôt `modelcontextprotocol/php-sdk`, Apache-2.0 pour les nouvelles contributions, MIT pour l'existant; maintenu avec la PHP Foundation et le projet Symfony); le README le dit **expérimental « jusqu'à la première version majeure »**; il annonce les révisions `initialize` et `2026-07-28`; transports STDIO et HTTP (détail de l'HTTP non lu; la page de releases ne montrait aucune version : date de dernière version inconnue). Son `composer.json` demande PHP `^8.1`, `ext-fileinfo` et **16 paquets directs** (`opis/json-schema`, `php-http/discovery`, `phpdocumentor/reflection-docblock`, `symfony/uid`, `symfony/deprecation-contracts`, et `psr/clock`, `container`, `event-dispatcher`, `http-client`, `http-factory`, `http-message`, `http-server-handler`, `http-server-middleware`, `log`), sans compter les dépendances transitives (non comptées). Une implémentation PSR-7 est probablement nécessaire à l'exécution pour l'HTTP (son `require-dev` en contient une; **non vérifié** en production).

| | (a) étendre `McpAdapter` | (b) SDK `mcp/sdk` |
|---|---|---|
| Dépendances d'exécution | 0 (décision validée du squelette) | 16 directes + transitives + éventuelle PSR-7 |
| Code à écrire | environ 250 lignes (`TaskTools`, `ToolArguments`, aiguillage), le protocole existe déjà (137 lignes) | déclaration des outils, pont `Request`/`Response` <-> PSR-7, remplacement des erreurs du SDK par le format M4 |
| Format d'erreur M4 (même JSON que REST, `isError`, `-32602` limité) | tenu directement | à surcharger : le SDK valide lui-même les arguments contre le schéma et produit ses propres erreurs (comportement **non lu**, risque de conflit avec M4) |
| Suivi des révisions de protocole | à notre charge (2026-07-28 non supportée) | pris en charge par le SDK (selon son README) |
| Stabilité de l'API | maîtrisée | expérimentale : ruptures possibles avant la 1.0 |
| AlwaysData | rien de nouveau | Composer disponible (vérifié le 2026-10-10); `fileinfo` activé dans l'exemple PHP 8.2 (2026-10-12); autres versions de PHP et chaîne PSR : non vérifiés |

Recommandation : **(a)**. Le besoin tient en huit outils JSON sans état; le SDK ajoute une surface de dépendances et d'API instable pour des fonctions que nous n'utilisons pas (SSE, sessions, ressources, prompts), et menace la règle « même JSON que REST ». **À réévaluer** quand on voudra la révision 2026-07-28 ou l'authentification OAuth (décision qui revient alors à l'utilisateur, avec les vérifications AlwaysData). Décision de dépendance : à valider par l'utilisateur.

### M6 — `list_tasks` sans pagination [recommandation : (a) aucun plafond]

Cohérent avec REST validé. Ordre de grandeur (**estimation non mesurée** de l'Architecte : une `Task` sérialisée fait environ 400 à 600 caractères, soit de l'ordre de 100 à 150 jetons) : Claude Code avertit au-delà de 10 000 jetons de sortie d'outil MCP et plafonne par défaut à 25 000 (variable `MAX_MCP_OUTPUT_TOKENS`); les textes de plus de 50 000 caractères sont écrits dans un fichier (documentation `code.claude.com/docs/en/mcp`, consultée le 2026-10-12; autres clients non vérifiés). Soit environ 70 à 100 tâches pour l'avertissement. Les tâches terminées s'accumulent (aucune purge, une occurrence est créée à chaque terminaison périodique) : le seuil peut être atteint en quelques mois d'usage intensif. Mitigations sans changer le contrat : filtres `status`/`category` existants, et description de l'outil qui recommande `status` (par exemple `todo`). Évolution compatible prévue si le seuil approche : arguments facultatifs `limit` / `due_before` (ajout, valeur par défaut inchangée). À valider par l'utilisateur (risque accepté : une réponse trop grande est tronquée ou refusée par le client, sans perte de données côté serveur).

### Impact

- **Interfaces** : MCP gagne huit outils et deux gardes (`Host`, version de protocole); `ping` et le transport sont inchangés. REST et portail : aucun changement de contrat; refactorisations internes (`ErrorBody`, `JsonObject`, `HostGuard`) sans effet visible. **Données** : aucune migration, aucune modification de schéma. **Dépendances** : aucune (sous réserve de M5).
- **Consommateurs et tests touchés** : `McpTest::testToolsListExposesOnlyPing` (devient neuf outils); `HttpTestCase::mcp()` (en-tête `Host`); `ConfigTest` et `.env.example` (variable `MCP_ALLOWED_HOSTS`); `README.md` (déclaration du serveur dans un client); tests REST et portail rejoués sans modification (extractions). Les clients MCP existants : aucun hors tests.
- **Sécurité** : validation côté serveur par `TaskService` (requêtes préparées); aucune authentification (local; à ne pas exposer, élément P1); `Host` + `Origin`; corps limité à 1 MiB (existant); erreurs sans SQL, SQLSTATE, trace ni valeur saisie (`details` ne porte que `field`, identifiants, listes de tâches); pas de limitation de débit (non demandée, local). Les données de tâches sont du texte libre renvoyé au modèle : risque d'injection de consigne à signaler à l'utilisateur (voir `delete_task`). Configuration : `MCP_ALLOWED_ORIGINS`, `MCP_ALLOWED_HOSTS`, hors dépôt pour toute valeur sensible (aucun secret ici).
- **Hébergement** : sans objet en local; rien n'est supposé sur AlwaysData (voir tableau).

### Tests à écrire avant le code (proposé)

Suite `tests/Integration/McpTasksTest` (PostgreSQL réel, `Bootstrap::kernel` et `Kernel::handle`, comme `RestTasksCrudTest`), plus des tests sans base pour `ToolArguments` et les schémas :
1. `tools/list` : neuf outils dans l'ordre, noms, `required`, `additionalProperties: false`, énumérations égales à `TaskStatus::codes()`, catégories, unités; annotations.
2. Un test de succès par outil (forme du texte JSON = corps REST de la même opération, comparé après décodage), dont `create_task` minimal et complet, `list_tasks` vide (`[]`), tri, filtres seuls et combinés, `update_task` (champ modifié, `null` efface, absent inchangé, `id` seul no-op), `add`/`remove` idempotents (`updated_at` inchangé au second appel), `change_task_status` (transitions permises, terminaison d'une périodique : `next_occurrence` 31/01 -> 28/02, non périodique : `null`).
3. **Parité** : pour chaque règle refusée par REST (un cas par `code` du tableau ci-dessus), même `code`, même `details`, texte JSON identique au corps REST; tâche et `updated_at` inchangés; `isError: true`, réponse HTTP 200.
4. `update_task` avec `status` : `field_unknown`, `details.field = status`, état inchangé; `create_task` avec `id`, `required_actions` : `field_unknown`.
5. `delete_task` : sans `confirm`, `false`, `"true"`, `1` : `confirmation_required`, tâche intacte, y compris pour un `id` inconnu; `confirm: true` : tâche requise -> `task_is_required` avec `dependents`, tâche intacte; non requise -> `{"deleted":true,"id":N}` puis `get_task` -> `task_not_found`; deuxième suppression -> `task_not_found`.
6. Cycles A->B->A et A->B->C->A, auto-lien, action requise inconnue, tâche inconnue : codes REST, aucun lien créé.
7. Forme des appels : `id` absent, `0`, `-1`, `"5"`, `1.0`, `99999999999999999999` : `argument_invalid`; clé inconnue sur `get_task` : `field_unknown`; `arguments` en liste, chaîne, `null` : `-32602`; outil inconnu : `-32602`; `arguments` absent pour un outil sans champ obligatoire : accepté.
8. Gardes : `Host` hors liste, absent, mal formé : 403 et aucun cas d'usage appelé; `GET /mcp` sans `Host` : 405; `MCP-Protocol-Version` `2026-07-28` ou `1999-01-01` sur `tools/list` : 400; sur `initialize` : accepté; absent ou `2025-06-18` : accepté.
9. 500/503 : dépôt qui lève une `PDOException` `XX000` : `-32603` « Erreur interne. » sans SQLSTATE ni message; base injoignable : `isError` `service_unavailable`.
10. Parcours HTTP réel (`php -S 127.0.0.1:8080 -t public public/index.php` + `curl`) : `initialize`, `tools/list`, créer, lire, modifier, terminer, supprimer avec `confirm`. Non vérifiable par les tests : comportement d'un vrai client (M8).

### Vérification avec un client réel (M8, à trancher par l'utilisateur)

Source : documentation Claude Code (consultée le 2026-10-12) : déclaration d'un serveur HTTP par `claude mcp add --transport http <nom> <url>` ou par un fichier `.mcp.json` `{"mcpServers":{"actions":{"type":"http","url":"http://127.0.0.1:8080/mcp"}}}` (une entrée avec `url` sans `type` est traitée comme stdio et échoue). Que Claude Code accepte une URL `http://127.0.0.1` pour un serveur MCP n'est **pas dit explicitement** par la page : à tester. L'application Claude et claude.ai : hors de portée tant que le serveur n'est pas public (hypothèse non vérifiée : URL HTTPS publique requise).

### À faire valider avant implémentation (MCP M1)

1. Les neuf outils, leurs noms et arguments (dont `required_task_id` plutôt que `required_id`/`requiredId`), le résultat de `delete_task` (`{"deleted":true,"id":N}`), les annotations.
2. Deux codes propres à MCP : `argument_invalid` et `confirmation_required`; réutilisation de `field_unknown`.
3. **M5** : étendre `McpAdapter` sans dépendance (recommandé) plutôt que `mcp/sdk`.
4. **M6** : aucun plafond ni pagination, risque de taille accepté; filtres et description comme mitigation.
5. **M7** : contrôle de `Host` et variable `MCP_ALLOWED_HOSTS`.
6. Garde `MCP-Protocol-Version` (400 hors versions supportées, `initialize` exempté) : à valider et à confirmer avec un vrai client; support de la révision 2026-07-28 : décision produit/technique distincte, non engagée.
7. Limite de `confirm: true` (voir `delete_task`) acceptée, ou confirmation humaine renforcée plus tard.
8. Extractions `ErrorBody`, `JsonObject`, `HostGuard` et constantes publiques de `TaskValidator` (refactorisations sans changement de comportement).
9. **M8** : quel client valide « utilisable depuis Claude » (Claude Code local suggéré).

## Préfixe d'URL (`APP_BASE_PATH`) — conception du 2026-10-12, **livrée en local**

Statuts. **Validé** (utilisateur, 2026-10-12) : servir l'application sous un préfixe (`http://cocault.alwaysdata.net/actions/`) en le gérant dans le code, plutôt que par un sous-domaine; conception approuvée (« Tu peux soumettre au développeur », 2026-10-12). **Existant** : implémentation relue par l'Architecte le 2026-10-12 (`Config`, `Kernel`, `Request::withPath`, `Bootstrap`, `NativeSession`, `TaskController`, `.env.example`, `README.md`, `BasePathTest`, `BasePathTasksTest`). Le Developer rapporte 595 tests OK dans Docker et un appel réel `php -S` sous `/actions` OK (non rejoués par l'Architecte). **Non vérifié** : tout ce qui concerne AlwaysData. Aucune migration, aucune dépendance, aucun changement de schéma. Les sous-sections ci-dessous gardent la rédaction de la conception; chacune est désormais **existante**, sauf mention contraire.

Écarts et ajouts du Developer, **existants** : (1) `NativeSession::options()` est **publique** (elle était à extraire, au choix du Developer) pour tester `cookie_path` sans processus séparé; (2) `tests/Integration/BasePathTasksTest` (PostgreSQL réel) vérifie les `Location` REST et portail et les liens des pages de données sous préfixe; (3) `public/index.php` n'est **pas modifié** (voir limite acceptée ci-dessous).

**Limite acceptée** : avec `php -S` et un préfixe, `/actions/assets/portal.css` n'est pas servie (le test `/assets/` de `public/index.php` ne s'applique que sans préfixe); pages et API restent utilisables, sans style. Documentée dans le README. Équivaut à l'option « sans fichiers statiques » du point 6.

### Existant avant l'implémentation (relu le 2026-10-12)

- `Kernel::handle` compare `$request->path` à des chemins en dur (`/`, `/tasks`, `/mcp`, `/api/v1`) : un chemin `/actions/...` tombe sur le 404 JSON final. `Request::fromGlobals` prend le chemin de `REQUEST_URI` tel quel.
- Le portail est déjà prêt côté gabarits : toutes les URL des vues passent par `$url()` et toutes les redirections par `Renderer::redirect` -> `View::path()`; `View` accepte déjà un argument `$prefix` (défaut `''`), mais `PortalAdapter` construit `new View()` sans le renseigner. Aucune URL en dur n'a été trouvée dans les gabarits ni dans `TaskPages` en dehors de ces deux assistants.
- Hors des assistants : `TaskController::create` écrit `Location: /api/v1/tasks/{id}` en dur; `NativeSession` ne fixe pas `cookie_path` (défaut PHP : `/`); `public/index.php` teste `str_starts_with($urlPath, '/assets/')` pour le serveur de développement; `public/.htaccess` : `RewriteRule ^ index.php [L]` (sans `RewriteBase`).
- `HostGuard`, la garde `Host`/`Origin` du portail et celle de MCP ne regardent que l'hôte et le port : aucun chemin n'y intervient.
- Constat rapporté par l'utilisateur (non rejoué par l'Architecte) : sur AlwaysData la réécriture atteint `index.php` et le routeur reçoit `/actions/...`.

### Configuration (existant)

- `APP_BASE_PATH` : chemin de l'application dans l'URL. Absent ou vide = **aucun préfixe, comportement actuel strictement inchangé**.
- Format : `''` ou une suite de segments `/seg` avec `seg` dans `[A-Za-z0-9._~-]+`, sans barre finale, sans `//`, sans segment `.` ou `..`, sans `?`, `#`, espace ni `%`. Une barre finale unique est tolérée et retirée; `/` seul vaut `''`. Valeur invalide : `InvalidArgumentException('APP_BASE_PATH invalide.')` au chargement (échec franc plutôt qu'un routage faux). Ces caractères sont ceux qui ne demandent aucun encodage d'URL, donc le préfixe configuré est comparable tel quel à `REQUEST_URI` et sûr à insérer dans un en-tête `Location` ou un cookie. Sensible à la casse.
- `Config` : propriété `appBasePath` (chaîne normalisée), **dernier** argument du constructeur avec défaut `''` (appels existants valides), lue par `fromArray` (`$env['APP_BASE_PATH'] ?? ''`). `.env.example` : ligne `APP_BASE_PATH=` commentée (vide = aucun préfixe; AlwaysData : `/actions`).
- Pourquoi une variable explicite et non une déduction (`SCRIPT_NAME`, `dirname`) : la valeur de `SCRIPT_NAME` après réécriture dépend de la configuration Apache d'AlwaysData (non vérifiée); une valeur explicite est testable et identique en local, en test et en production. Le mécanisme de variables d'environnement d'AlwaysData reste à trancher (voir « Configuration »).

### Où retirer le préfixe et comment le fournir (existant)

- **Dans `Kernel`**, pas dans `Request::fromGlobals` : `fromGlobals` n'est pas exercé par les tests (qui fabriquent des `Request`), et la décision « hors préfixe = 404 » doit être testée par `Kernel::handle`. `Kernel::__construct(..., string $basePath = '')` (dernier argument, défaut inchangé).
- `Kernel::handle` : si `$basePath !== ''` : chemin égal à `$basePath` -> chemin relatif `/`; chemin commençant par `$basePath . '/'` -> reste du chemin (qui commence par `/`); **sinon** 404 JSON existant (`RestAdapter::error(404, 'not_found', ...)`), sans appeler d'adaptateur (donc sans garde `Host`: rien n'est lu ni écrit). Le chemin relatif est transmis par une nouvelle méthode `Request::withPath(string): self` (copie, `Request` reste immuable).
- Conséquence : `PortalAdapter`, `RestAdapter` et `McpAdapter` **ne voient jamais le préfixe** dans `$request->path`; leurs routes (`/tasks`, `/api/v1/...`, `/mcp`) et leurs tests actuels sont inchangés. Le préfixe ne sert qu'à **produire** des URL sortantes; il est fourni par `Bootstrap::kernel` (`$config->appBasePath`) à ceux qui en produisent : `Kernel` (retrait), `PortalAdapter` (`new View(prefix: ...)` et `NativeSession`), `TaskController` (en-tête `Location`). Injection par constructeur avec défaut `''`, comme les listes d'hôtes. Alternative écartée : un attribut `basePath` sur `Request` (les vues sont construites une fois, hors requête; deux sources de vérité).
- Cas limites : `/actions` et `/actions/` donnent le portail (`/` -> redirection 302 vers `/actions/tasks`); `/actionsfoo/tasks` et `/tasks` -> 404 (comparaison sur `$basePath . '/'`); un chemin avec segments `..` n'est pas normalisé par l'application (hors préfixe ou inconnu -> 404). Un 404 hors préfixe est du JSON même pour un navigateur, comme tout chemin inconnu aujourd'hui.

### Portail : URL sortantes, cookie, assets (existant; fichiers statiques sous AlwaysData non vérifiés)

- **Liens, formulaires, assets, `Location`** : `PortalAdapter` reçoit `string $basePath = ''` et construit `new View(prefix: $basePath)` (existant côté `View`). `$url('/tasks/12')` donne `/actions/tasks/12`; `Renderer::redirect` donne `Location: /actions/tasks/12` (chemin absolu sur le même hôte, valide en HTTP); `/assets/portal.css` donne `/actions/assets/portal.css`. Aucun gabarit à modifier. Filet : un test parcourt les pages rendues avec préfixe et refuse tout attribut `href`, `action` ou `src` commençant par `/` sans le préfixe.
- **Cookie de session et CSRF** : `NativeSession` reçoit `string $cookiePath = '/'` (Bootstrap : `appBasePath` ou `'/'` si vide) et le passe en `cookie_path` à `session_start`. Raison : sans cela le cookie `actions_session` (donc le jeton CSRF) part vers tous les chemins de `cocault.alwaysdata.net`, y compris d'autres applications du même hôte. `cookie_path = /actions` couvre `/actions` et `/actions/...` mais pas `/actionsfoo`. Le jeton CSRF n'est que dans la session et un champ de formulaire : pas d'autre attribut `path`. `SameSite=Strict` et `HttpOnly` inchangés.
- **Content-Security-Policy** : `'self'` (`style-src`, `img-src`, `form-action`) est indépendant du chemin; aucun changement.
- **Fichiers statiques** : sur Apache, le fichier `public/assets/portal.css` n'est servi sous `/actions/assets/portal.css` que si le serveur fait correspondre le préfixe d'URL au dossier `public/`; sinon la réécriture le renvoie à `index.php`, le routeur le voit en `/assets/portal.css` et répond 404. **Non vérifié**. Serveur de développement : `php -S -t public` fait correspondre l'URI au disque (`/actions/assets/...` -> `public/actions/assets/...`, inexistant); le test `/assets/` de `public/index.php` ne s'applique donc qu'**sans** préfixe. Proposé : le développement avec préfixe est vérifié par les tests en mémoire et par `curl` (pages sans feuille de style), sans route statique supplémentaire; si une feuille de style locale sous préfixe est voulue, ajouter dans `public/index.php` une lecture explicite du seul fichier `assets/portal.css` (quelques lignes).

### REST, MCP (existant)

- Chemins publics sous préfixe : `/actions/api/v1/...`, `/actions/mcp`. Le contrat REST (`/api/v1`, versionnement) et le contrat MCP (`POST /mcp`) sont **inchangés** : le préfixe est un détail de déploiement hors contrat, retiré avant les adaptateurs.
- Seule URL produite par REST : l'en-tête `Location` de `201` (`/api/v1/tasks/{id}`) -> `{basePath}/api/v1/tasks/{id}`. Aucun corps JSON REST ou MCP ne contient d'URL (relu : `TaskController`, `RestAdapter`).
- Déclaration d'un client MCP sous préfixe : `http://cocault.alwaysdata.net/actions/mcp` (README à mettre à jour à l'implémentation).

### Gardes `Host`, `Origin` et en-têtes (existant : gardes inchangées, vérifié par `testForeignHostIsStillRefusedUnderPrefix`; points AlwaysData non vérifiés)

- `HostGuard` : **aucun changement**. L'en-tête `Host` ne porte pas de chemin; en production il faudra ajouter `cocault.alwaysdata.net` à `PORTAL_ALLOWED_HOSTS` et `MCP_ALLOWED_HOSTS` (sans port si 80/443). `Origin` n'a pas de chemin non plus : le contrôle d'`Origin` du portail (même hôte et même port que `Host`) et `MCP_ALLOWED_ORIGINS` (ex. `http://cocault.alwaysdata.net`) sont inchangés.
- **Non vérifié** : que le `Host` reçu par PHP derrière AlwaysData soit celui du client (pas de réécriture par un mandataire), et la détection de HTTPS (`$_SERVER['HTTPS']`, utilisée par `Bootstrap` pour `Secure`; un en-tête `X-Forwarded-Proto` ne serait pas lu). Si le site est servi en HTTPS derrière un mandataire, le cookie perdrait `Secure` sans que rien ne le signale : à vérifier au déploiement.
- Un point de sécurité, distinct du préfixe : voir « À faire valider », point 1.

### `.htaccess` (existant : aucun `RewriteBase` ajouté; comportement sous AlwaysData non vérifié)

- **Ne pas ajouter de `RewriteBase` a priori** : il coderait le préfixe dans un second endroit (désynchronisable de `APP_BASE_PATH`) et casserait le déploiement sans préfixe. La règle actuelle atteint déjà `index.php` selon le constat de l'utilisateur. Si un essai montre une boucle ou un mauvais chemin, ajouter `RewriteBase /actions/` à la main sur le déploiement, et le noter ici. Indice (source secondaire, résumé d'une recherche, 2026-10-12) : les scripts d'installation d'AlwaysData réécrivent la ligne `RewriteBase` d'un `.htaccess` quand l'URL de base n'est pas `/` (`help.alwaysdata.com/en/docs/development/marketplace/build-application-script/` définit `INSTALL_URL_PATH`, « site root (base URL) »; la page ne parle ni de `RewriteBase` ni de la correspondance préfixe/dossier). **Non vérifié** par un essai.

### Tests (existant; rejeu non fait par l'Architecte)

Livré : `ConfigTest`, `tests/Http/BasePathTest` (dont `testSessionCookiePathFollowsPrefix` via `NativeSession::options()` publique), `tests/Integration/BasePathTasksTest`, paramètre de préfixe de `HttpTestCase`. Le parcours manuel `php -S` a été fait par le Developer en local; le parcours navigateur sur AlwaysData reste à faire (**non vérifié**).

- Sans préfixe : toute la suite actuelle, **sans modification**, est le test de non-régression (défaut `''`).
- `tests/Unit/ConfigTest` : `APP_BASE_PATH` absent, vide, `/` -> `''`; `/actions`, `/actions/` -> `/actions`; `/a/b`; invalides : `actions`, `//x`, `/a//b`, `/a b`, `/a/../b`, `/a?x`, `/a#b`, `/%2e`.
- `tests/Http/BasePathTest` (`HttpTestCase` gagne un paramètre facultatif de préfixe, défaut `''`; `Kernel` construit via `Bootstrap::kernel` avec la configuration) : avec `/actions` : `GET /actions/api/v1/health` 200; `POST /actions/mcp` `ping` 200; `GET /actions` et `/actions/` 302 vers `/actions/tasks`; `GET /actions/tasks` : HTML sans lien non préfixé; `POST /actions/tasks` -> `Location: /actions/tasks/{id}`; `POST /actions/api/v1/tasks` -> `Location: /actions/api/v1/tasks/{id}`; hors préfixe : `/tasks`, `/api/v1/health`, `/mcp`, `/actionsfoo/tasks` -> 404 JSON `not_found`, aucun adaptateur appelé; `Host` étranger sous préfixe -> 403 comme sans préfixe.
- Cookie : test de `NativeSession` avec `cookie_path` (dans un processus séparé, `session_get_cookie_params()` après `csrfToken()`), sinon test unitaire d'une méthode `options()` extraite; au choix du Developer, à condition que le chemin soit vérifié.
- Parcours manuel (non automatisable) : `APP_BASE_PATH=/actions php -S 127.0.0.1:8080 -t public public/index.php` puis `curl`; et, au déploiement, créer/modifier/supprimer une tâche depuis le navigateur sur `http://cocault.alwaysdata.net/actions/`.

### Impact (analyse; constat à l'implémentation : aucune rupture, défaut `''`)

- **Consommateurs** : REST et MCP (contrats inchangés; clients : aucun hors tests); portail (aucun gabarit modifié); tests existants (inchangés : valeur par défaut `''`); données (aucune); `ConfigTest` et `.env.example` (ajouts); `public/index.php` et README. Rien ne casse; l'ajout est compatible parce que le défaut est le comportement actuel.
- **Fichiers touchés (existant)** : `Config`, `Kernel`, `Request` (`withPath`), `Bootstrap`, `PortalAdapter`, `NativeSession`, `TaskController`, `.env.example`, `README.md`, `CHANGELOG.md`, tests. `public/index.php` : prévu à titre indicatif, finalement non touché.

### Vérifications AlwaysData restantes (non vérifiées)

Correspondance entre le préfixe d'URL du site et la racine `~/actions/public` (donc service direct des fichiers de `assets/`); effet de `RewriteBase`; valeur du `Host` et détection de HTTPS reçues par PHP; sessions par fichiers (déjà « partiellement vérifié »); mécanisme de variables d'environnement pour `APP_BASE_PATH`; POST/JSON de MCP sous le préfixe.

### Validation et points restants

Points 2 à 6 : **validés** par l'approbation de l'utilisateur (2026-10-12) et implémentés; le point 6 retient l'option « sans fichiers statiques sous préfixe en développement ». Le point 1 reste **ouvert** (question à l'utilisateur et au Product Owner, avant tout déploiement).

1. **Exposition publique (question pour l'utilisateur et le Product Owner, pas une décision d'architecture)** : `http://cocault.alwaysdata.net/actions/` est joignable de l'extérieur et aucune authentification n'existe. Les décisions validées disent que portail, REST et MCP ne sont ni déployés ni exposés avant l'authentification (élément P1); les gardes `Host`/`Origin` ne protègent que du rebond DNS, pas d'un visiteur direct, et ajouter le nom d'hôte public aux listes d'hôtes autorisés les ouvre. Faut-il livrer l'authentification avant ce déploiement, protéger le site autrement (restriction d'accès au niveau de l'hébergeur, **non vérifiée**), ou accepter explicitement l'exposition?
2. Nom `APP_BASE_PATH` et format décrit ci-dessus; échec franc sur valeur invalide.
3. Retrait dans `Kernel`, 404 JSON hors préfixe, `Request::withPath`.
4. `cookie_path` égal au préfixe.
5. Pas de `RewriteBase` a priori.
6. Développement local avec préfixe sans fichiers statiques (ou lecture explicite de la feuille de style).

## Champs « Projet » et « Conclusions » (tranche P) — conception du 2026-10-13, **validée par l'utilisateur et livrée en local**

**Mise à jour 2026-10-15 :** pour `project` (liste fermée, `CHECK`) et la catégorie saisie, cette section est **remplacée (validé le 2026-10-15)** par « Projets configurables et catégorie portée par le projet »; `conclusions` reste valable. Texte d'origine :

Besoin **validé** par l'utilisateur (2026-10-13, F1, F2, F3, F5, F6, F7 du backlog) : `project` facultatif, liste fermée `MS-HR`, `Jardin/Maison`, `OPF`, `Erendil`, `CHECK` en base, sensible à la casse, hors liste = 422; `conclusions` texte facultatif traité comme `description`, saisissable à tout moment; filtre exact `project` en ET avec état et catégorie sur REST, MCP et portail; la terminaison d'une périodique recopie `project`, pas `conclusions`. **Validé** (utilisateur, après la livraison) : la conception ci-dessous telle quelle (schéma `0003`, forme `Task`, contrats REST/MCP/portail, code `project_invalid`, recopie à la terminaison), ainsi que F4 (liste unique, aucune règle croisée avec la catégorie) et F8 (comparaison sensible à la casse, aucun `trim`). **Existant** (rapporté par le Developer, **sans écart** avec la conception; non rejoué par l'Architecte) : `database/migrations/0003_task_project_conclusions.sql` présent dans le dépôt; suite complète 630 tests OK sur PostgreSQL 17 local; smoke HTTP réel OK. **Non vérifié** : `0003` sur PostgreSQL 16 et 18, et sur AlwaysData (droit `ALTER TABLE`, pgBouncer), où aucune migration n'a été appliquée; rendu navigateur du portail. Dans cette section, les mentions « (proposé) » des titres et du tableau des règles valent désormais « **validé, existant** »; les points « non vérifié » restent valables.

### Migration `0003_task_project_conclusions.sql` (proposé; le fichier fera foi)

```sql
-- Projet (liste fermée) et conclusions (texte libre), tous deux facultatifs.
-- Additive : les tâches existantes reçoivent NULL, aucune donnée existante modifiée.
ALTER TABLE tasks
  ADD COLUMN project     text,
  ADD COLUMN conclusions text,
  ADD CONSTRAINT tasks_project_valid
    CHECK (project IS NULL OR project IN ('MS-HR', 'Jardin/Maison', 'OPF', 'Erendil'));
```

- **Types** : `text` + `CHECK`, pas de type `ENUM` (même raison que la catégorie : une valeur de plus = une migration sur le `CHECK`, sans `ALTER TYPE`). `IS NULL OR` explicite pour ne pas dépendre de la logique à trois valeurs (leçon de `tasks_recurrence_valid`). Pas de `CHECK` sur `conclusions` (comme `description`; la validité UTF-8/NUL est portée par le validateur et par PostgreSQL).
- **Pas d'index** : un seul utilisateur, tables de quelques centaines de lignes, tri existant sans index sur le filtre; un `project` à 4 valeurs est très peu sélectif. Un index partiel (`WHERE project IS NOT NULL`) reste ajoutable plus tard par migration, sans effet sur les contrats.
- **Nom de contrainte** `tasks_project_valid` (famille `tasks_*_valid`). Une `23514` sur ce nom qui atteint le dépôt est un bogue du validateur : erreur interne (500 / `-32603`), non traduite (règle existante de « Validation »).
- **Rejouabilité** : celle de `bin/migrate` (table `schema_migrations` + checksum; deuxième exécution = « Rien à appliquer »), comme 0001 et 0002. Le fichier SQL n'est volontairement pas écrit en `IF NOT EXISTS` : `ADD CONSTRAINT` ne le permet pas et une demi-garde donnerait une fausse impression de rejeu manuel. Atomicité : un seul `exec` dans une transaction, échec sans état partiel.
- **Coût et verrou** : `ADD COLUMN` sans `DEFAULT` n'entraîne aucune réécriture de table, et l'ajout d'un `CHECK` parcourt la table sans la réécrire (PostgreSQL 17, `sql-altertable`, page consultée le 2026-10-13; les versions 16 et 18 d'AlwaysData n'ont pas été relues, comportement identique à ma connaissance : **non revérifié**). Verrou `ACCESS EXCLUSIVE` bref, sans objet à cette échelle. Le fait qu'un `CHECK` évalué à `NULL` est accepté est la sémantique standard de PostgreSQL (non citée par la page consultée) et est verrouillé par un test.
- **Compatibilité AlwaysData** : DDL standard sur la table de l'application, aucune extension, fonction ni trigger (donc aucune dépendance aux droits `CREATE FUNCTION` non vérifiés). PostgreSQL 16 à 18 vérifié le 2026-10-10 (tableau AlwaysData). **Aucune migration n'est lancée sur AlwaysData** sans autorisation explicite; local et test seulement.
- **Retour arrière** : pas de « down ». Avant toute application hors local : sauvegarde (`pg_dump`). Annulation = restauration, ou migration `0004` (`DROP COLUMN`, **destructive** : perte des projets et conclusions saisis, donc seulement sur décision de l'utilisateur).
- **Ordre de déploiement du code** : appliquer `0003` **avant** le code qui sélectionne les nouvelles colonnes (le dépôt nomme ses colonnes : l'ancien code continue de fonctionner sur le nouveau schéma, l'inverse non).

### Modèle partagé et couche commune (proposé)

- **`Task`** gagne deux clés toujours présentes : `project: "MS-HR"|"Jardin/Maison"|"OPF"|"Erendil"|null` et `conclusions: string|null`. Ajout de clés dans l'objet : compatible en `/api/v1` (aucune clé retirée ni renommée, aucun code existant modifié). Ordre des clés non contractuel. Consommateurs : voir « Impact ».
- **Source unique de la liste** : constante `TaskValidator::PROJECTS` (comme `CATEGORIES` et `UNITS`), réutilisée par le validateur, l'`inputSchema` MCP et les listes déroulantes du portail. La base répète la liste dans le `CHECK` (duplication acceptée, comme la catégorie); un test d'intégration parcourt `PROJECTS` et vérifie que la base accepte chaque valeur et refuse les variantes de casse, garantissant que les deux ne divergent pas.
- **`TaskValidator`** : `FIELDS` gagne `project` et `conclusions`; `create` et `update` les acceptent (création : absents = `null`); `update` valide l'état fusionné comme aujourd'hui, `null` efface, clé absente inchangée.

| Champ | Règle | `code` |
|---|---|---|
| `project` | `null` ou chaîne exactement égale (comparaison stricte, sensible à la casse, sans `trim`) à l'une de `PROJECTS`; la chaîne vide et toute autre chaîne sont refusées, sans normalisation | `project_invalid` (proposé) |
| `project` (type) | valeur non chaîne et non `null` (nombre, booléen, tableau, objet) | `field_type_invalid` |
| `conclusions` | `null` ou chaîne UTF-8 valide sans octet NUL, sans borne de longueur, chaîne vide conservée telle quelle (F5, identique à `description`) | `text_invalid`, `field_type_invalid` |

  Message de `project_invalid` : français court, construit à partir de `PROJECTS` (« Le projet doit être MS-HR, Jardin/Maison, OPF ou Erendil. »), `details.field = "project"`. Statut 422 par la classe `InvalidTask` : `DomainStatus` inchangé.
- **Filtre** : `TaskService::list(array $filters)` accepte la clé `project` (une valeur, ET avec `status` et `category`); `validateFilters` l'autorise et applique la même règle `project_invalid` (valeur vide ou hors liste = 422, non-chaîne = `field_type_invalid`), exactement comme pour `category`. Le dépôt ajoute `project = :project` à la clause `WHERE` (valeur liée, clause fixe).
- **`TaskRepository`** : `TASK_COLUMNS`, `toTask`, `insert` (colonnes nommées, deux paramètres de plus) et la liste fermée des colonnes modifiables de `update` gagnent `project` et `conclusions`. Toujours `SELECT` avec colonnes nommées, jamais `SELECT *`.
- **Recopie à la terminaison (F7)** : dans `changeStatus`, le tableau passé à `insert` pour la nouvelle occurrence reçoit `'project' => $current['project']` et `'conclusions' => null`, **explicites** (l'`insert` lit les deux clés : un oubli lèverait une erreur plutôt que de copier silencieusement). La ligne source est déjà verrouillée (`FOR UPDATE`) : la valeur copiée est cohérente. L'occurrence terminée garde `project` et `conclusions`. `setStatus` ne touche ni l'un ni l'autre : la réouverture conserve les conclusions (F6). Le contrat d'état (`PATCH {"status": …}` seul, `change_task_status`) est **inchangé**.
- **Aucune règle croisée** (F4, hypothèse) : ni avec la catégorie, ni avec l'état. `conclusions` et `project` restent modifiables sur une tâche `done` (règle existante « autres champs d'une Terminée modifiables », qui met `updated_at` à jour).

### Contrat REST (proposé; versionnement `/api/v1` conservé, ajouts compatibles)

| Opération | Changement |
|---|---|
| `POST /api/v1/tasks` | corps : `project` et `conclusions` facultatifs (absents = `null`); 201 avec la tâche complète (deux clés de plus) |
| `GET /api/v1/tasks/{id}` | réponse : deux clés de plus |
| `GET /api/v1/tasks` | réponse : deux clés de plus par tâche; paramètre `?project=<valeur exacte>` (valeur encodée en URL : `Jardin%2FMaison`), seul filtre ajouté; combiné en ET avec `status` et `category`; absent = pas de filtre; `?project=` vide ou hors liste (dont `jardin/maison`) : 422 `project_invalid`, `details.field = "project"`; `?project[]=x` : 422 `field_type_invalid`. `TaskController::list` ajoute `project` à sa liste blanche de paramètres (les autres restent ignorés) |
| `PATCH /api/v1/tasks/{id}` | `project` et `conclusions` modifiables, `null` efface, clé absente inchangée; avec `status` : `status_not_alone` (inchangé); 200 avec la tâche; si le corps portait `status`, `next_occurrence` (qui porte `project` recopié et `conclusions: null`) |
| `PUT`/`DELETE` des liens, `DELETE /tasks/{id}` | aucun changement de contrat (réponses `Task` : deux clés de plus) |

Erreurs ajoutées : `project_invalid` (422, nouveau code, famille `InvalidTask`); `field_type_invalid` et `text_invalid` s'appliquent aux nouveaux champs. Aucun code existant n'est modifié; `field_unknown` n'est plus levé pour `project` et `conclusions` (ils étaient refusés comme clés inconnues avant la tranche : changement de comportement voulu, ajout de fonctionnalité).

### Contrat MCP (proposé)

Aucun nouvel outil; aucun changement de `TaskTools::call` (les arguments sont transmis à `TaskService`, qui valide). `inputSchema` (strict, `additionalProperties: false`) :

| Outil | Ajout |
|---|---|
| `create_task`, `update_task` | `project` : `{"type":["string","null"],"enum":["MS-HR","Jardin/Maison","OPF","Erendil",null],"description":"Projet (liste fermée), ou null pour l'effacer."}`; `conclusions` : `{"type":["string","null"],"description":"Conclusions (ce qui a été appris ou décidé), ou null pour l'effacer."}`; ni l'un ni l'autre n'est obligatoire. L'énumération est produite à partir de `TaskValidator::PROJECTS` (+ `null`). |
| `list_tasks` | `project` : `{"type":"string","enum":[…PROJECTS…],"description":"Filtre exact sur le projet."}` (sans `null` : un filtre n'a pas de valeur « aucun »); description de l'outil complétée (« ou par projet »). |
| `get_task`, `add_required_action`, `remove_required_action`, `change_task_status`, `delete_task` | schéma inchangé; la tâche renvoyée porte les deux clés; `change_task_status` n'accepte toujours que `id` et `status`; `next_occurrence` porte `project` recopié et `conclusions: null` |

Erreurs : mêmes codes et même JSON que REST (`isError: true`), donc `project_invalid` côté MCP identique à REST, octet pour octet. `update_task` continue de refuser `status` en `field_unknown`. Les clients qui ont mis `tools/list` en cache voient un schéma enrichi (ajout facultatif, compatible).

### Portail (proposé)

Cas d'usage appelés : `TaskService::create`, `update`, `get`, `list` (aucun nouveau). Aucune règle dans l'adaptateur.
- **`TaskForm`** : `FIELDS` gagne `project` et `conclusions`; `toInput` envoie toujours les deux clés (`project` : chaîne vide -> `null`; `conclusions` : chaîne vide -> `null`, **comme `description` aujourd'hui** : une saisie vidée efface); `valuesFromForm`, `valuesFromTask`, `emptyValues` suivent.
- **Formulaires de création et de modification** : une liste déroulante « Projet » (option vide « Aucun », puis les valeurs de `TaskValidator::PROJECTS`) et une zone de texte « Conclusions » (`textarea`, libellés `for`/`id`, `aria-describedby`/`aria-invalid` en cas d'erreur, même schéma que `description`). La saisie est conservée en cas d'erreur.
- **`TaskPages`** : `FORM_FIELD` et `ANCHOR` gagnent `project` (`f-project`) et `conclusions` (`f-conclusions`) pour rattacher l'erreur au champ; `Messages` : `project_invalid` utilise le message de l'exception (liste générée, pas de texte dupliqué), `text_invalid` déjà couvert.
- **Détail** : lignes « Projet » (« Aucun » si `null`) et « Conclusions » (bloc multiligne, « Aucune » si `null`, échappé comme `description`).
- **Liste** : colonne « Projet » ajoutée au tableau (présentation; les conclusions n'y figurent pas). **Filtre** : liste déroulante « Projet » (« Tous » = filtre absent) ajoutée au formulaire GET, `name="project"`; `TaskPages::list` ajoute `project` à la boucle des filtres `status`/`category` (valeur vide = « Tous », hors liste forgée = 422 via la page « Filtre invalide » existante); « Filtres actifs » mentionne le projet; `isFiltered` en tient compte. Combiné en ET avec état, catégorie et `hide_done`.
- **Page `/reopen` et message de terminaison** : inchangés. La prochaine occurrence affichée est celle du cas d'usage (projet recopié).
- Regroupement visuel par projet : hors tranche (F3).

### Impact (analyse)

- **Données** : tâches existantes inchangées (`project` et `conclusions` à `NULL`), aucune perte; production inexistante (aucune migration AlwaysData).
- **REST, MCP, portail** : réponses enrichies de deux clés (compatible); requêtes inchangées valides. Seule rupture de comportement : `project`/`conclusions` cessent d'être refusés en `field_unknown`, sans consommateur existant concerné (aucun client externe documenté).
- **Tests existants à adapter (conséquences attendues, pas des régressions)** : `TaskSchemaTest` (liste exacte des migrations appliquées, dont `['0001…','0002…']` aux lignes citées `setUp` et `status()`, et `testMigration0002BackfillsExistingTasksAsTodoWithoutLosingData` qui attendra maintenant `0002` et `0003` dans la liste migrée); les tests qui comparent la forme complète d'une tâche ou la liste de ses champs nuls (par exemple `McpTasksTest`, boucle sur `['due_date', …, 'completed_at']`); `McpToolsTest` (schémas) pour les assertions sur les propriétés. À relire par le Developer test par test.
- **Aucun** changement : `DomainStatus`, `PathId`, `HostGuard`, gardes du portail, mécanisme de migrations, dépendances Composer (aucune ajoutée).
- **Sécurité** : requêtes paramétrées (filtre `project` lié, colonnes nommées en liste fermée); validation côté serveur par liste fermée; texte des conclusions échappé à l'affichage portail (garde d'échappement existante des gabarits); aucune donnée sensible nouvelle; les erreurs n'exposent que le code, le message et `details.field`.

### Stratégie de tests (à écrire avant le code, contre PostgreSQL réel)

1. **Schéma** (`TaskSchemaTest`) : migration `0003` sur base vide; liste appliquée à jour; rejeu sans effet; tâches créées avant `0003` conservées avec `project`/`conclusions` à `NULL` (même technique que le test de rattrapage de `0002`); la base accepte `NULL` et chacune des valeurs de `PROJECTS`, refuse `''`, `'mshr'`, `'ms-hr'`, `'JARDIN/MAISON'`, `'Autre'` (SQLSTATE `23514`, nom `tasks_project_valid` dans le message); `conclusions` accepte tout texte, y compris vide.
2. **Validateur** (`TaskValidatorTest`, unitaire) : chaque valeur de `PROJECTS` acceptée; `null` accepté et efface; `''`, variantes de casse, valeur inconnue, `'MS-HR '` (espace) : `project_invalid`; nombre, booléen, tableau : `field_type_invalid`; `conclusions` : `null`, chaîne vide conservée, texte long, NUL et UTF-8 invalide en `text_invalid`, non-chaîne en `field_type_invalid`; filtre `project` valide, vide, hors liste, non chaîne.
3. **Service** (`TaskServiceTest`, `TaskStatusServiceTest`) : création sans les champs (`null`) et avec; `update` pose, change, efface (`null`), laisse inchangé si absent; `update` identique n'écrit pas (`updated_at` stable); modification sur une tâche `done` autorisée; filtre `project` seul, en ET avec `status` et `category`, résultat vide si aucun; terminaison d'une périodique : l'occurrence a le même `project` et `conclusions` `null`, l'occurrence terminée garde les deux, `next_occurrence` expose les deux clés; terminaison d'une non périodique : rien créé; réouverture : conclusions et projet conservés; `status` avec `project` : `status_not_alone`.
4. **REST** (`RestTasksCrudTest`, `RestTaskStatusTest`) : `POST`/`PATCH`/`GET` avec et sans les champs; 422 `project_invalid` avec `details.field`, tâche inchangée; 422 `field_type_invalid` et `text_invalid`; `GET ?project=Jardin%2FMaison` exact, ET avec `status` et `category`, `?project=` vide, `?project=ms-hr`, `?project[]=x` : 422; paramètre inconnu toujours ignoré; terminaison par `PATCH` avec `project` recopié.
5. **MCP** (`McpToolsTest`, `McpTasksTest`) : `tools/list` : `create_task` et `update_task` déclarent `project` (énumération égale à `TaskValidator::PROJECTS` + `null`) et `conclusions`, non obligatoires; `list_tasks` déclare `project` (énumération sans `null`); `change_task_status` inchangé; tests de parité : mêmes entrées invalides que REST donnent le même JSON d'erreur; filtre exact via `list_tasks`; recopie à la terminaison.
6. **Portail** (`PortalTasksTest`, `PortalViewsTest`, `TaskFormTest`) : formulaires de création et de modification avec projet et conclusions, saisie conservée en cas d'erreur, valeur de projet forgée hors liste : 422 avec message près du champ, option vide = `null`, champ vidé = effacé; détail (« Aucun »/« Aucune ») et échappement d'un `<script>` dans les conclusions; filtre exact en ET avec état, catégorie et `hide_done`, valeur « Tous », valeur forgée 422; recopie à la terminaison visible sur la nouvelle occurrence; parité avec REST sur les cas de validation.
7. Suite complète rejouée par le Developer (`vendor/bin/phpunit`), plus un smoke HTTP réel (REST, MCP, portail) et la lecture du README; vérification en navigateur du formulaire (libellés, clavier, focus) laissée à l'utilisateur ou au Developer.

### Validation (tranche P) — historique des points soumis

Tous les points ci-dessous sont **validés** par l'utilisateur ; F4 et F8 sont confirmées. Reste **non vérifié** : PostgreSQL 16 et 18, AlwaysData, navigateur.

### Points soumis avant implémentation (tranche P)

**Structurant** : (1) schéma `0003` (deux colonnes `text` nullables, `CHECK` `tasks_project_valid` sur la liste fermée, pas d'index); (2) ajout de `project` et `conclusions` à la forme `Task` renvoyée par REST, MCP et portail; (3) contrats modifiés : paramètre `?project=` de `GET /tasks`, `project`/`conclusions` sur `POST`/`PATCH`, schémas de `create_task`, `update_task`, `list_tasks`; (4) nouveau code d'erreur `project_invalid` (422); (5) recopie de `project` (et non de `conclusions`) à la terminaison, par `changeStatus`.
**Hypothèses à confirmer** : F4 (liste unique, aucune règle croisée avec la catégorie); F8 (sensible à la casse, aucun `trim`); nom `project_invalid`; `?project=` vide = 422 en REST (cohérent avec `category`) alors que le portail le traduit en « Tous »; `conclusions` vide conservé tel quel en REST/MCP mais converti en `null` par le portail (comme `description`); colonne « Projet » dans la liste du portail (présentation, modifiable).
**Non vérifié** : application de `0003` sur PostgreSQL 16 et 18 et sur AlwaysData (droit `ALTER TABLE` du rôle, pgBouncer) : à tester sur base jetable avant déploiement, sans migration opérationnelle sans autorisation.

## Projets configurables et catégorie portée par le projet (tranches 1 et 2) — conception du 2026-10-15, **validée** (utilisateur, 2026-10-15)

**Statuts.** **Toute la conception ci-dessous est validée par l'utilisateur le 2026-10-15** (opposable au Developer), aux écarts et précisions près listés ici. Rien n'est implémenté; les points « non vérifié » restent à vérifier. Décisions de l'utilisateur : option A, table `projects` (index unique sur `lower(name)`, FK `RESTRICT`), migration unique `0004` avec rapport en lecture seule et garde, ruptures de `/api/v1` sans `/api/v2`, REST projets par identifiant et nouveaux codes, validation de tâche en deux temps; hypothèses H1 à H6, H8, H9; noms d'accueil **« Divers Pro »** (Pro) et **« Divers Perso »** (Perso); **PC12** : projets d'accueil ordinaires, sans règle (option a); **PC13** : `PATCH project:null` -> 422 `project_invalid`, clé absente = inchangé; **PC14** : nom référencé et filtré en **casse exacte**; **H7 tranchée** : le portail **ignore** un champ `category` forgé (pas de 422 côté portail), REST et MCP le refusent (`field_unknown`) — écart assumé par rapport à PC11 pour le portail. Rappel des décisions PC1 à PC11 (validées plus tôt, via le backlog) : projets = données (nom + catégorie `Pro`/`Perso`); catégorie de la tâche dérivée du projet et non saisissable (`category` en écriture : `field_unknown` sur REST, MCP, portail); projet obligatoire; deux projets d'accueil; `MS-HR` Pro, `Jardin/Maison`/`OPF`/`Erendil` Perso; rapport de conflits puis arrêt; suppression d'un projet utilisé refusée (409); renommage libre; changement de catégorie d'un projet propagé aux tâches (terminées comprises) avec nombre de tâches touchées; nom <= 255 caractères, stocké tel quel, unicité insensible à la casse; MCP en lecture seule; découpage en deux tranches. Plus aucun point de conception de cette section n'est en attente de décision; la migration n'est pas écrite (plus bloquée).

Cette section **remplace**, pour `project` et `category`, la tranche P ci-dessus (liste fermée, `tasks_project_valid`, `TaskValidator::PROJECTS`, catégorie saisie). `conclusions` n'est pas touché.

### Choix technique : catégorie dérivée (validé)

Options : (A) colonne `tasks.category` **supprimée**, catégorie lue par jointure `projects`; (B) colonne conservée, garantie par clé étrangère composite `(project_id, category) -> projects(id, category)` avec `ON UPDATE CASCADE`; (C) colonne conservée, maintenue par trigger.
**Retenu et validé : (A).** La cohérence tâche/projet est vraie par construction (une seule donnée, aucun trigger, aucune clé composite à nommer), quel que soit le chemin d'écriture; changer la catégorie d'un projet (PC6) est un seul `UPDATE projects` qui est instantanément vu par toutes les tâches, terminées comprises; le nombre de tâches touchées est un `count(*)`. Coût : une jointure sur `projects` (clé primaire, une poignée de lignes) dans les lectures, et `category` doit être qualifié (`p.category`) dans les requêtes. (B) double la donnée et réécrit en cascade toutes les lignes `tasks` à chaque changement; (C) ajoute de la logique PL/pgSQL sans nécessité. Conséquence assumée : changer la catégorie d'un projet **n'écrit aucune ligne `tasks`** : `updated_at` des tâches ne change pas (hypothèse H2, validée).

### Modèle de données et migration `0004_projects.sql` (validé; le fichier fera foi)

```sql
CREATE TABLE projects (
  id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name     text NOT NULL,
  category text NOT NULL,
  CONSTRAINT projects_name_not_blank CHECK (btrim(name) <> '' AND char_length(name) <= 255),
  CONSTRAINT projects_category_valid CHECK (category IN ('Pro', 'Perso'))
);
CREATE UNIQUE INDEX projects_name_lower_key ON projects (lower(name));

INSERT INTO projects (name, category) VALUES
  ('MS-HR', 'Pro'), ('Jardin/Maison', 'Perso'), ('OPF', 'Perso'), ('Erendil', 'Perso'),
  ('Divers Pro', 'Pro'), ('Divers Perso', 'Perso');      -- noms d'accueil validés

ALTER TABLE tasks ADD COLUMN project_id bigint;
-- 1. garde : DO $$ ... RAISE EXCEPTION si des tâches ont une catégorie différente de celle du projet
--    (tasks.project IS NOT NULL ET tasks.category <> projects.category pour le projet de même nom);
--    le message contient le nombre total et les 50 premières (id, titre, catégorie, projet).
-- 2. rattachement (n'écrit que project_id : updated_at et état conservés) :
UPDATE tasks t SET project_id = p.id FROM projects p
 WHERE p.name = COALESCE(t.project, CASE t.category WHEN 'Pro' THEN 'Divers Pro' ELSE 'Divers Perso' END);
-- 3. verrouillage du modèle :
ALTER TABLE tasks ALTER COLUMN project_id SET NOT NULL,
  ADD CONSTRAINT tasks_project_id_fkey FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
  DROP COLUMN category, DROP COLUMN project;           -- emporte tasks_category_valid et tasks_project_valid
CREATE INDEX tasks_project_id_idx ON tasks (project_id);
```

- **Nom de projet** : `text`, unicité insensible à la casse par **index unique sur expression `lower(name)`** (PostgreSQL 17, § 11.7 « Indexes on Expressions », consulté le 2026-10-15 : un index `UNIQUE` sur expression interdit les lignes qui ne diffèrent que par la casse). Alternative écartée : `citext` (extension à activer, disponibilité sur le compte non vérifiée; AlwaysData documente l'activation d'extensions « en un clic » pour une liste, page consultée le 2026-10-15, mais `citext` n'y est pas cité dans ce que j'ai lu). Limite : `lower()` dépend de la locale de la base; pour les caractères non ASCII, la détection de doublon dépend de `LC_CTYPE` (**non vérifié sur AlwaysData**; ASCII garanti). Le `CHECK` du nom n'est jamais plus strict que le validateur (celui-ci refuse aussi tabulations/retours à la ligne seuls).
- **Clé étrangère** `tasks_project_id_fkey` nommée explicitement (le dépôt reconnaît l'erreur par SQLSTATE + nom, règle existante) : `23503` à la suppression d'un projet utilisé -> `ProjectInUse`; `23503` à l'insertion/mise à jour d'une tâche (projet supprimé entre la résolution du nom et l'écriture) -> `project_invalid`. Aucun verrou supplémentaire : la base arbitre.
- **Pas de table de catégories** : `Pro`/`Perso` restent un `CHECK` de `projects.category` (même choix que 0001). Pas de colonne « projet d'accueil » (PC12 validé : aucune règle) : le code ne référence jamais les noms d'accueil, qui n'existent que dans la migration.
- **Pas de dates sur `projects`** (hors périmètre PC), pas d'ordre d'affichage.
- **Migration en deux temps (PC3).** *Temps 1, lecture seule* : commande `php bin/report-project-conflicts` (validée; même garde `APP_ENV` que `bin/migrate`, `--allow-env=` documenté), exécutée dans `BEGIN READ ONLY`, sur le schéma **avant** `0004`. Elle affiche, pour chaque tâche dont `project` n'est pas nul et dont `category` diffère de celle du projet de destination (`MS-HR` Pro; les trois autres Perso) : id, titre, état, catégorie actuelle, projet, catégorie attendue; plus le nombre de tâches sans projet par catégorie (qui rejoindront l'accueil de leur catégorie, sans conflit); code de sortie 0 sans conflit, 1 sinon; refuse de s'exécuter si `projects` existe déjà. *Temps 2, migration* : la garde du point 1 relit **la même règle** et lève une exception si au moins un conflit existe; la transaction de `Migrator` annule tout (aucun état partiel, `schema_migrations` sans `0004`) et le message d'échec, relayé par `Migrator`, contient le nombre et la liste tronquée (le rendu du message PDO avec `RAISE` est à confirmer par un test). L'arbitrage (changer la catégorie ou le projet de la tâche) est manuel, avant migration, avec l'ancien code (`PATCH`) ou en SQL. La règle existe deux fois (rapport, garde) : un test d'intégration vérifie que le rapport liste exactement les tâches que la migration refuse.
- **Rejouable** : celle de `bin/migrate` (rejeu = « Rien à appliquer »); après un arrêt pour conflits, relancer après arbitrage suffit. Fichier non idempotent par construction (`CREATE TABLE`, `DROP COLUMN`), comme `0003`.
- **Données** : aucune tâche perdue ni modifiée en dehors de `project_id`; `updated_at` conservé. `DROP COLUMN category`/`project` est destructif **mais sans perte d'information** une fois le contrôle de conflits passé (la catégorie se déduit du projet; les tâches sans projet portent la catégorie de leur accueil). Sauvegarde `pg_dump` obligatoire avant toute application hors local; retour arrière = restauration (pas de « down »).
- **Compatibilité PostgreSQL / AlwaysData.** Utilisé : `CREATE TABLE ... GENERATED AS IDENTITY`, index unique sur expression, `ALTER TABLE ... ADD/DROP COLUMN`, clé étrangère, `DO $$ ... $$` PL/pgSQL, `COALESCE`, `string_agg`. Tout est standard PostgreSQL 16 à 18 (versions AlwaysData : 16, 17, 18, page consultée le 2026-10-15). `DO` exige `USAGE` sur le langage; `plpgsql` est installé par défaut et approuvé (page `sql-do`, PostgreSQL 17, consultée le 2026-10-15); AlwaysData n'interdit que les langages non approuvés (même page AlwaysData). Aucune nouvelle dépendance aux droits : `0001` exige déjà PL/pgSQL (fonction + trigger). **Non vérifié** : exécution réelle sur PostgreSQL 16/18 et sur AlwaysData (droits du rôle sur `ALTER TABLE`/`DO`, pgBouncer), `LC_CTYPE`. Aucune migration AlwaysData sans autorisation explicite. Piège de test : `UPDATE` puis `ALTER TABLE` dans la même transaction n'échoue que s'il reste des événements de déclencheur différés (les clés étrangères de `task_dependencies` sont immédiates, donc aucun); à couvrir par un test avec données.
- **Ordre** : appliquer `0004` **avant** le code (l'ancien code, qui écrit `category`, ne fonctionne plus sur le nouveau schéma; l'inverse non plus) : rupture assumée, local seulement.

### Couche commune (validé)

- **Nouveaux composants** `Application` : `ProjectService` (cas d'usage), `ProjectValidator` (pur), `ProjectRepository` (SQL + traduction `PDOException`), erreurs `InvalidProject`, `ProjectNotFound`, `ProjectNameTaken`, `ProjectInUse`. Composition dans `Bootstrap` (`projectService()`), injecté dans `ProjectController` (REST), `McpAdapter`/`TaskTools` (enums, puis `list_projects`) et `PortalAdapter` (listes). `TaskService` reçoit le dépôt de projets pour résoudre un nom.
- **Forme `Project`** : `{id:int, name:string, category:"Pro"|"Perso", task_count:int}` (`task_count` : toutes les tâches, tous états, utile à la décision de suppression).
- **`ProjectService`** : `list()` (tri `lower(name)`, puis `id`), `get(int)`, `create(array)` (`name`, `category` obligatoires), `update(int, array)` (`name`, `category` facultatifs, `null` interdit, clé absente inchangée; renvoie `{project, tasks_recategorized:int}`), `delete(int)`, `names()`. `update` : transaction `READ COMMITTED`, ligne projet lue `FOR UPDATE`, validation de l'état fusionné, aucune écriture si rien ne change; `tasks_recategorized` = nombre de tâches du projet (tous états) si la catégorie change effectivement, sinon 0 (valeur de la transaction, indicative en cas de création concurrente de tâche).
- **`ProjectValidator`** (pur, fail-fast; valeurs stockées telles quelles, sans `trim`) :

| Champ | Règle | `code` |
|---|---|---|
| `name` | chaîne UTF-8 valide sans octet NUL; non vide ni blancs seuls (`\s`, `\p{Z}`); 255 caractères au plus | `project_name_blank`, `project_name_too_long`, `text_invalid` |
| `category` | exactement `Pro` ou `Perso` (sensible à la casse) | `category_invalid` |
| autre clé / type | clé inconnue (dont `id`, `task_count`); non-chaîne, `null` inclus | `field_unknown`, `field_type_invalid` |

  Absent à la création : `project_name_blank` / `category_invalid` (même convention que `title`/`category`).
- **Unicité** : aucune prévérification; `INSERT`/`UPDATE` refusés par `23505` + nom `projects_name_lower_key` -> `ProjectNameTaken` (409).
- **Suppression** : `DELETE` direct (instruction unique) ; `23503` + `tasks_project_id_fkey` -> `ProjectInUse` (nombre de tâches relu ensuite; tolérance identique à `TaskIsRequired`); 0 ligne -> `ProjectNotFound`. Aucun archivage, aucune règle sur les projets d'accueil.
- **Validation d'une tâche : le validateur n'est plus entièrement pur.** Découpage proposé, pour garder `TaskValidator` testable sans base : (1) `TaskValidator` (pur) retire `category` de `FIELDS` (donc `field_unknown` automatique, `details.field = "category"`) et ne connaît plus `PROJECTS`; il contrôle la **forme** de `project` : absent ou `null` à la création, `null` en modification (PC13 validé) : `project_invalid` (`details.field = "project"`); non-chaîne : `field_type_invalid`; clé absente en modification : inchangé. (2) `TaskService` résout ensuite le **nom en identifiant** dans la même transaction, par `ProjectRepository::idByName(string)`; nom inconnu (dont chaîne vide) : `project_invalid`. Ordre de `update` : 404 tâche, forme (pure), existence du projet. Message : « Projet inconnu. Projets disponibles : A, B, C. » (liste lue en base). Tous les refus sont des `InvalidTask` (422), codes inchangés. Le filtre `project` de `list()` résout de la même façon (inconnu : 422 `project_invalid`, vide compris); le filtre `category` reste validé à `Pro`/`Perso`. `TaskValidator::CATEGORIES` reste la source de la liste (utilisée par les filtres, les schémas MCP et `ProjectValidator`); `TaskValidator::PROJECTS` et `projectList()` disparaissent.
- **`TaskRepository`** : `TASK_COLUMNS` qualifié (`t.`), `p.name AS project`, `p.category AS category`, `JOIN projects p ON p.id = t.project_id`; `FOR UPDATE OF t` (jamais `FOR UPDATE` seul, qui verrouillerait aussi la ligne projet); `insert` et `update` écrivent `project_id` (jamais `category`); filtre `p.category = :category`, `t.project_id = :project_id`. `findAll` conserve l'ordre `due_date`, `id`.
- **Terminaison d'une périodique** : la nouvelle occurrence copie `project_id` **dans le dépôt**, sans exposer l'identifiant interne : méthode `copyAsOccurrence(int $sourceId, string $dueDate): int` en un `INSERT ... SELECT` depuis la ligne source (déjà verrouillée `FOR UPDATE`) qui recopie titre, `project_id`, durée, description, périodicité, met `conclusions` à `NULL`, aucune dépendance, état par défaut. Remplace le tableau construit par `changeStatus` (qui contenait `category` et `project`). La catégorie suit donc le projet; si le projet est renommé ou change de catégorie, l'occurrence suit. Comportement observable inchangé.
- **Identifiant de projet dans `Task`** : non exposé (la référence est le nom, en casse exacte, PC14 validé); `Task` garde `category` (dérivée, en lecture seule) et `project` (nom, désormais toujours non nul).

### Contrat REST (validé; `/api/v1`)

**Projets** (nouveau; chemins par identifiant car un nom peut contenir `/`) :

| Opération | Entrée | Succès | Erreurs |
|---|---|---|---|
| `GET /api/v1/projects` | aucune | 200, tableau de `Project` | — |
| `POST /api/v1/projects` | `{name, category}` | 201, `Project`, `Location: /api/v1/projects/{id}` | 400 `invalid_json`; 422 `project_name_blank`, `project_name_too_long`, `text_invalid`, `category_invalid`, `field_type_invalid`, `field_unknown` (`details.field`); 409 `project_name_taken` (`details.field = "name"`) |
| `GET /api/v1/projects/{id}` | — | 200, `Project` | 404 `project_not_found` |
| `PATCH /api/v1/projects/{id}` | `name` et/ou `category` | 200, `Project` + `tasks_recategorized` | 400 `invalid_json`; 404 `project_not_found` (avant 422); mêmes 422/409 qu'à la création |
| `DELETE /api/v1/projects/{id}` | — | 204 | 404 `project_not_found`; 409 `project_in_use` (`details`: `id`, `task_count`) |

Autre méthode : 405 + `Allow`. Identifiant non entier positif : 404 `not_found` (`PathId`). Format d'erreur commun inchangé.

**Tâches** (modifié) :

| Opération | Changement | Compatibilité |
|---|---|---|
| `POST /tasks` | `project` (nom) **obligatoire**; `category` refusée : 422 `field_unknown`, `details.field = "category"`; `project` absent/`null` : 422 `project_invalid`; inconnu : 422 `project_invalid` | **rupture** (voir « Impact ») |
| `PATCH /tasks/{id}` | `project` modifiable (permis à tout état), `null` : 422 `project_invalid` (PC13, validé), clé absente : inchangé; `category` : 422 `field_unknown`; avec `status` : `status_not_alone` inchangé | **rupture** |
| `GET /tasks`, `GET /tasks/{id}`, liens, `DELETE` | forme `Task` inchangée (`category` dérivée, `project` toujours renseigné) | compatible |
| `GET /tasks?category=` | inchangé, filtre la catégorie dérivée | compatible |
| `GET /tasks?project=` | valeur = nom de projet configuré (PC14 validé : casse exacte); vide ou inconnu : 422 `project_invalid`; `project[]=x` : `field_type_invalid`; combiné en ET | compatible (message changé) |

Statuts : `DomainStatus` : `InvalidProject` = 422 (par classe, comme `InvalidTask`); `project_not_found` 404, `project_name_taken` 409, `project_in_use` 409 ajoutés à `BY_CODE`.

### Contrat MCP (validé)

- **Tranche 1** : aucun nouvel outil. `create_task` : propriétés `title`, `project` (obligatoires `["title","project"]), `due_date`, … ; **`category` retiré** des propriétés (un client qui l'envoie reçoit `field_unknown` en `isError`, octet pour octet comme REST). `update_task` : `project` (`type: "string"`, pas `null`) sans `category`. `list_tasks` : `category` (`Pro`/`Perso`, inchangé), `project`. **Énumération dynamique** : `enum` de `project` (et des filtres) construite à chaque `tools/list` à partir de `ProjectService::names()`; si la base est injoignable, le schéma est servi **sans `enum`** (erreur journalisée) afin que `initialize`, `ping` et `tools/list` restent indépendants de la base comme dans le contrat du squelette (H6). Limite connue : un client qui garde `tools/list` en cache voit une énumération périmée après création, renommage ou suppression d'un projet; si cela gêne, retirer l'`enum` et lister les noms dans la description est une évolution compatible.
- **Tranche 2** (**corrigé le 2026-10-18, validé** : le texte initial disait `-32602` pour des arguments non vides; contrat de la section de la tranche 2) : `list_projects` : aucun argument (`additionalProperties: false`), renvoie le tableau `Project` (même JSON que `GET /projects`), appelle `ProjectService::list()`; erreurs : argument inconnu = `isError` avec `field_unknown` (comme les outils de tâches), `-32603` sur incident. Outil en lecture seule (10 outils au total avec `ping`). Pas d'outil d'écriture sur les projets (PC7).

### Portail (validé)

- **Tranche 1** : formulaires de création/modification : champ « Catégorie » **supprimé**; liste « Projet » obligatoire (« Choisir… » puis les projets, valeur = nom), alimentée par `ProjectService::list()`; la catégorie s'affiche en lecture seule dans le détail et la liste (« selon le projet »). Filtre « Projet » dynamique, filtre « Catégorie » inchangé. `TaskForm` : `category` sort de `FIELDS`; un champ `category` forgé dans un POST est ignoré par le portail comme tout champ de formulaire inconnu (le cas d'usage reste le garde : un appel direct à `TaskService` le refuse) — écart assumé par rapport à PC11 pour le portail (H7 tranchée par l'utilisateur : ignoré, pas de 422 de formulaire; REST et MCP refusent). `project_invalid` : message du cas d'usage près du champ `f-project`. `PortalAdapter` reçoit `ProjectService` (adaptation de ses constructions dans les tests).
- **Tranche 2 (esquisse, précisée et remplacée par « Gestion des projets dans le portail, vue projet, synthèse et matrice d'Eisenhower (tranche 2) », proposée le 2026-10-18)** : pages `/projects` (liste avec catégorie et `task_count`), `/projects/new`, `/projects/{id}/edit` (nom et catégorie; affiche le nombre de tâches concernées avant de changer la catégorie, puis `tasks_recategorized` en message), `/projects/{id}/delete` (page de confirmation comme PW5; refus 409 avec le nombre de tâches et lien vers la liste filtrée), mêmes gardes (CSRF, `Origin`, `Host`) que le portail existant, `Messages` étendu aux codes projet. Aucun cas d'usage nouveau.

### Impact (analyse, validée)

| Consommateur | Effet |
|---|---|
| **REST** | **Ruptures dans `/api/v1`** : `category` refusé en écriture; `project` obligatoire à la création; `PATCH project:null` refusé (effaçait avant). Réponses : mêmes clés. Aucune version `/api/v2` créée : décision PC11 de l'utilisateur, aucun client externe, rien déployé (H1). |
| **MCP** | `create_task` : `category` retiré, `project` requis; enums dynamiques; clients en cache : voir ci-dessus. |
| **Portail** | champ catégorie supprimé, projet requis, listes dynamiques. |
| **Données** | `0004` destructive pour deux colonnes sans perte d'information après contrôle de conflits; `tasks.project_id` ajouté; `updated_at` conservé. |
| **Code existant** | `TaskValidator` (`FIELDS`, `PROJECTS`, `validateCreate` obligation de catégorie), `TaskRepository` (jointure, `insert`, `update`, filtres, `copyAsOccurrence`), `TaskService` (résolution du projet, `changeStatus`), `DomainStatus`, `Bootstrap`, `TaskController` (aucun changement de logique), `TaskTools`, `McpAdapter`, `TaskForm`, `TaskPages`, vues `task_form`/`task_list`/`task_detail`, `Messages`. |
| **Tests** (305 occurrences de `category`/`project` dans 14 fichiers) | à adapter : `TaskValidatorTest` (52), `McpTasksTest` (54), `RestTasksCrudTest` (44), `PortalTasksTest` (42), `ProjectConclusionsTest` (33; tests du `CHECK tasks_project_valid` à retirer ou réécrire), `TaskSchemaTest` (21; listes de migrations, `CHECK` de catégorie, `INSERT` bruts), `TaskStatusServiceTest` (15), `RestTaskStatusTest` (13), `TaskServiceTest` (12), `TaskFormTest` (8), `McpToolsTest` (7), `RestTasksTest`, `BasePathTasksTest`, `PortalTasksActionsTest`. Tout `INSERT INTO tasks (..., category ...)` brut doit fournir `project_id` : prévoir un assistant de test (résolution d'un projet par nom). Isolation : un `TRUNCATE tasks` ne vide pas `projects` (les projets de la migration persistent) ; un assistant remet `projects` à l'état de la migration (`TRUNCATE tasks, projects RESTART IDENTITY CASCADE` puis réinsertion des six lignes). |
| **Dépendances** | aucune ajoutée. |

### Tests à écrire avant le code (validé; PostgreSQL réel)

1. **Schéma/migration** : `0004` sur base vide (six projets, `category`/`project` absents de `tasks`); sur base migrée à `0003` avec données (technique du test de rattrapage de `0002`) : projet `NULL` -> accueil de la catégorie, `updated_at`/état conservés, aucune tâche perdue; conflit -> `RuntimeException`, message avec nombre et liste, `projects` absente, `schema_migrations` sans `0004`; rejeu après arbitrage; rapport = ensemble refusé par la garde; rapport lecture seule (aucune écriture) et refus si déjà migré; `CHECK` nom vide/256, catégorie invalide; doublon `OPF`/`opf` : `23505` + `projects_name_lower_key`; FK : suppression d'un projet utilisé `23503`, nom `tasks_project_id_fkey`.
2. **Validateurs (unitaires)** : `ProjectValidator` (tableau ci-dessus); `TaskValidator` : `category` -> `field_unknown`, `project` absent/`null` -> `project_invalid`, non-chaîne -> `field_type_invalid`.
3. **Services** : projets (CRUD, renommage `opf`->`OPF` permis, doublon, suppression 409/OK, `tasks_recategorized` avec tâches terminées, catégorie inchangée = 0, `{}` sans écriture); tâches (création avec projet inconnu/vide, changement de projet y compris `done`, `null` refusé, catégorie lue suit le projet après changement de catégorie du projet, filtres `category`/`project` en ET, projet inconnu 422, terminaison : occurrence dans le même projet, `conclusions` `NULL`, aucune dépendance).
4. **REST** : tableau des opérations projets (codes, `Location`, 405, `details`); tâches : `category` -> 422 `field_unknown`, projet obligatoire, filtres.
5. **MCP** : `tools/list` : `enum` = noms en base et mise à jour après création d'un projet, `category` absent de `create_task`/`update_task`, dégradation sans `enum` si la base est coupée; parité des erreurs avec REST; (tranche 2) `list_projects`.
6. **Portail** : formulaires sans catégorie, liste de projets dynamique, saisie conservée, `project_invalid`, détail avec catégorie dérivée; (tranche 2) pages de gestion, confirmation, refus 409, parité.

### Découpage et ordre (validé)

Tranche 1 : migration + rapport + tests de schéma; `Project*`; adaptation `Task*`; REST projets; MCP et portail (tranche 1 seulement); adaptation des tests; README/CHANGELOG. Tranche 2 : pages portail de gestion, `list_projects`. L'ordre interne de la tranche 1 suit cette liste (la migration locale ne se lance qu'après le rapport de conflits et une sauvegarde de la base locale).

### Points soumis (projets configurables) — tous validés le 2026-10-15

**Structurant (validés par l'utilisateur)** : (1) catégorie dérivée par suppression de `tasks.category` et jointure (option A); (2) table `projects`, unicité par index sur `lower(name)`, clé étrangère `ON DELETE RESTRICT`; (3) migration unique `0004` avec rapport en lecture seule préalable et garde dans la migration; (4) ruptures de `/api/v1` (catégorie en écriture, projet obligatoire, `PATCH project:null`) sans `/api/v2`; (5) nouveaux contrats REST des projets et nouveaux codes (`project_name_blank`, `project_name_too_long`, `project_name_taken` 409, `project_not_found` 404, `project_in_use` 409; `project_invalid` pour manquant, `null` ou inconnu); (6) validation de tâche en deux temps (forme pure, existence en service).
**Hypothèses (H1 à H6, H8, H9 validées; H7 tranchée, voir « Portail »)** : H1 pas de `/api/v2`; H2 le changement de catégorie d'un projet ne modifie pas `updated_at` des tâches; H3 `task_count` dans `Project` et `tasks_recategorized` dans la réponse du `PATCH`; H4 `project_in_use` ne renvoie que le nombre de tâches (pas la liste); H5 chemins REST par identifiant; H6 `tools/list` sans `enum` si la base est injoignable; H7 le portail ignore un champ `category` forgé (pas de 422 de formulaire); H8 nom et sémantique de la commande `bin/report-project-conflicts`; H9 `projects` sans dates.
**Questions produit, toutes tranchées par l'utilisateur** : noms d'accueil « Divers Pro » / « Divers Perso »; PC12 option (a), aucune règle sur les accueils; PC13 `PATCH project:null` -> 422 `project_invalid`; PC14 casse exacte (la casse insensible, écartée, aurait imposé que `update` compare le nom canonique).
**Points ouverts restants** : aucun point de conception. À reporter au backlog par le Product Owner (non modifié ici) : noms d'accueil, PC12 à PC14 et H7 tranchés.
**Non vérifié** : exécution de `0004` sur PostgreSQL 16/18 et sur AlwaysData, `LC_CTYPE` de la base AlwaysData, rendu du message `RAISE` côté PDO, comportement des clients MCP avec énumération périmée. `CLAUDE.md` (« Modèle initial d'une tâche ») décrit encore la catégorie comme champ de la tâche : à mettre en cohérence par l'utilisateur.

## Importance d'une action — conception **validée (2026-10-17) et livrée en local** (non appliquée sur AlwaysData)

**Statuts.** Tout ce qui suit est **validé** par l'utilisateur et **existant** dans le dépôt, sans écart de conception relevé par le Developer ni à la relecture ciblée. Les mentions « (proposé) » des sous-titres et du texte ci-dessous sont historiques et valent **validé**. **Vérifié** : migration et suite de tests sur PostgreSQL 17 local. **Non vérifié** : PostgreSQL 16 et 18, AlwaysData (droit `ALTER TABLE`, pgBouncer); `0005` n'a jamais été appliquée hors local. Rappel des décisions : valeurs `Mineure` / `Majeure` / `Critique`, libellés français exacts (casse et accents) en base, REST et MCP; défaut `Mineure`; tâches existantes -> `Mineure` (I1); champ facultatif à la création, `importance: null` refusé en 422 à la modification (I2); valeur invalide -> 422 avec code dédié (I3); la nouvelle occurrence d'une périodique reprend l'importance (I4); filtre par importance en REST, MCP (`list_tasks`) et portail, tri inchangé : date butée puis `id` (I5); une seule tranche REST + MCP + portail (I6); aucune règle croisée avec dépendances ou états (I7); priorité P2 (I8). Numéro et contenu de la migration, nom `importance_invalid`, forme des contrats et organisation : validés avec la conception. Précédent suivi : `project` (tranche P), moins la liste dynamique.

### Migration `0005_task_importance.sql` (validé, existant; le fichier fait foi)

```sql
-- Importance d'une tâche : Mineure (défaut), Majeure ou Critique.
-- Additive : les tâches existantes reçoivent 'Mineure' (défaut constant, sans UPDATE).
ALTER TABLE tasks
  ADD COLUMN importance text NOT NULL DEFAULT 'Mineure',
  ADD CONSTRAINT tasks_importance_valid CHECK (importance IN ('Mineure', 'Majeure', 'Critique'));
```

- **Même patron que `0002`** (colonne `NOT NULL DEFAULT` constant + `CHECK` nommé `tasks_*_valid`) et même choix de type que `category` et `status` : `text` + `CHECK`, pas de `ENUM` (une valeur de plus = une migration sur le `CHECK`). `NOT NULL` : l'absence d'importance n'existe pas (I1, I2); aucun `IS NULL OR` nécessaire.
- **Le `DEFAULT 'Mineure'` reste dans le schéma** : il réalise I1 sans `UPDATE` et protège les `INSERT` bruts. Il est répété par `TaskValidator::DEFAULT_IMPORTANCE`; un test vérifie qu'ils concordent.
- **Coût et verrou** : un `ADD COLUMN` avec `DEFAULT` non volatile ne réécrit pas la table (la valeur est stockée dans les métadonnées); un `ADD CONSTRAINT ... CHECK` parcourt la table sans la réécrire, sous `ACCESS EXCLUSIVE` (déduit de la page : `CHECK` n'est pas cité parmi les exceptions) — PostgreSQL 17, `sql-altertable`, page consultée le 2026-10-17. Versions 16 et 18 d'AlwaysData : **non relues**. Verrou bref et sans objet à cette échelle.
- **Compatibilité AlwaysData** : DDL standard sur la table de l'application, aucune extension, fonction ni trigger; PostgreSQL 16 à 18 vérifié le 2026-10-10 (tableau AlwaysData). **Non vérifié** : droit `ALTER TABLE` du rôle et pgBouncer. **Aucune migration AlwaysData sans autorisation explicite**; local et test seulement.
- **Données** : aucune perte, `updated_at` inchangé; toutes les tâches existantes (terminées comprises) valent `Mineure`.
- **Rejouabilité, retour arrière, ordre** : celle de `bin/migrate` (rejeu = « Rien à appliquer »); pas de « down » : sauvegarde `pg_dump` avant tout usage hors local, annulation par restauration (un `DROP COLUMN` serait destructif des importances saisies, décision de l'utilisateur seulement). Appliquer `0005` **avant** le code (qui sélectionne `t.importance`); l'ancien code reste compatible avec le nouveau schéma (colonnes nommées, défaut constant).
- **Pas d'index** : trois valeurs, quelques centaines de lignes. **Tri par importance** (hors tranche, I5) : l'ordre alphabétique du texte (`Critique` < `Majeure` < `Mineure`) n'est pas l'ordre de gravité; un tri futur passera par une expression `CASE` ou un rang ajouté par migration, sans effet sur les contrats.
- **Alternative écartée** : rang `smallint` 1 à 3 avec correspondance de libellés dans chaque interface : tri naturel, mais contredit I3 (libellés exacts en base) et duplique la correspondance.

### Couche commune (validé, existant)

- **`Task`** gagne la clé `importance: "Mineure"|"Majeure"|"Critique"`, **toujours présente et jamais nulle**. Ajout de clé : compatible en `/api/v1`. Source unique de la liste : `TaskValidator::IMPORTANCES = ['Mineure','Majeure','Critique']` (ordre de gravité croissante, non contractuel), réutilisée par le validateur, les `inputSchema` MCP et la liste déroulante du portail; la base répète la liste dans le `CHECK` (duplication acceptée, comme `category`) et un test d'intégration vérifie qu'elle accepte chaque valeur et refuse les variantes.
- **`TaskValidator`** : `FIELDS` gagne `importance` (en dernier, pour ne pas changer l'ordre d'évaluation des erreurs existantes); création : absent = `Mineure`; modification : clé absente inchangée.

| Contexte | Règle | `code` |
|---|---|---|
| valeur | chaîne exactement égale (comparaison stricte, sensible à la casse et aux accents, sans `trim`) à l'une de `IMPORTANCES`; `''`, `mineure`, `MAJEURE`, `Critique ` refusés | `importance_invalid` (validé) |
| `null` | refusé à la modification (I2) **et à la création** (hypothèse J1 ci-dessous) | `importance_invalid` |
| type | valeur non chaîne et non `null` (nombre, booléen, tableau) | `field_type_invalid` (comme `project`) |

  Message : « L'importance doit être Mineure, Majeure ou Critique. » construit à partir de `IMPORTANCES`; `details.field = "importance"`; statut 422 par la classe `InvalidTask` (`DomainStatus` inchangé).
- **Filtre** : `TaskService::list` accepte `importance` (une valeur, ET avec `status`, `category`, `project`); `validateFilters` l'autorise avec la même règle (vide ou hors liste : `importance_invalid`; non-chaîne : `field_type_invalid`). Aucune résolution en base (valeur de liste fermée), donc pas de changement de `TaskService::list` hors passage du filtre. Dépôt : `t.importance = :importance` (clause fixe, valeur liée); `findAll` conserve l'ordre `due_date`, `id`.
- **`TaskRepository`** : `TASK_COLUMNS` gagne `t.importance`; `insert` (colonne nommée, valeur explicite fournie par le validateur) et la liste fermée des colonnes modifiables de `update` gagnent `importance`. Toujours des colonnes nommées. `update` valide l'état fusionné comme aujourd'hui; un changement identique n'écrit pas.
- **Recopie à la terminaison (I4)** : `copyAsOccurrence` ajoute `importance` à son `INSERT ... SELECT` depuis la ligne source déjà verrouillée (`importance` copiée telle quelle, comme `project_id`). L'occurrence terminée la garde; `setStatus` et la réouverture n'y touchent pas; `next_occurrence` expose la clé. Contrat d'état inchangé (`PATCH {"status": …}` seul). Changer l'importance de l'occurrence terminée après coup n'affecte pas l'occurrence suivante.
- **Aucune règle croisée (I7)** : ni avec les dépendances (une `Critique` peut requérir une `Mineure`), ni avec l'état (modifiable sur une tâche `done`, avec mise à jour de `updated_at`), ni avec la catégorie ou le projet.

### Contrat REST (validé, existant; `/api/v1`, ajouts compatibles)

| Opération | Changement |
|---|---|
| `POST /api/v1/tasks` | corps : `importance` facultatif (absent = `Mineure`); 201 avec la tâche complète (clé `importance` de plus) |
| `GET /api/v1/tasks/{id}`, liens, `DELETE` | réponse : clé de plus; aucun changement de requête |
| `GET /api/v1/tasks` | clé de plus par tâche; paramètre `?importance=<valeur exacte>` (`Critique`; les accents n'existent pas dans les trois libellés, aucun encodage particulier), en ET avec `status`, `category`, `project`; absent = pas de filtre; `?importance=` vide ou hors liste (dont `critique`) : 422 `importance_invalid`, `details.field = "importance"`; `?importance[]=x` : 422 `field_type_invalid`. `TaskController::list` ajoute `importance` à sa liste blanche (autres paramètres toujours ignorés). Tri inchangé. |
| `PATCH /api/v1/tasks/{id}` | `importance` modifiable; `null` : 422 `importance_invalid`; clé absente : inchangé; avec `status` : `status_not_alone` (inchangé); `next_occurrence` porte l'importance recopiée |

Erreur ajoutée : `importance_invalid` (422, famille `InvalidTask`). Aucun code existant modifié. Avant la tranche, `importance` était refusé en `field_unknown` : changement de comportement voulu (ajout de fonctionnalité).

### Contrat MCP (validé, existant)

Aucun nouvel outil; `TaskTools::call` inchangé (arguments transmis à `TaskService`). `inputSchema` strict (`additionalProperties: false`) :

| Outil | Ajout |
|---|---|
| `create_task`, `update_task` | `importance` : `{"type":"string","enum":["Mineure","Majeure","Critique"],"description":"Importance de la tâche (Mineure par défaut à la création); ne peut pas être effacée."}` — **sans `null`** (refusé, comme `project`); non obligatoire; énumération produite depuis `TaskValidator::IMPORTANCES` (statique, indépendante de la base) |
| `list_tasks` | `importance` : même énumération, « Filtre exact sur l'importance »; description de l'outil complétée (« ou par importance ») |
| `get_task`, liens, `change_task_status`, `delete_task` | schéma inchangé; la tâche renvoyée porte `importance`; `next_occurrence` aussi |

Erreurs : même JSON que REST (`isError: true`), octet pour octet. `update_task` continue de refuser `status` en `field_unknown`. Risque connu : un client qui garde `tools/list` en cache n'offre pas le nouveau champ jusqu'au rafraîchissement; un client qui l'envoie quand même à un serveur plus ancien reçoit `field_unknown`.

### Portail (validé, existant)

Cas d'usage appelés : `TaskService::create`, `update`, `get`, `list` (aucun nouveau); aucune règle dans l'adaptateur.
- **`TaskForm`** : `FIELDS` gagne `importance`; `toInput` l'envoie toujours, **sans** conversion « vide -> `null` » (une valeur vide forgée donne `importance_invalid`, pas un effacement); `valuesFromForm`/`valuesFromTask`/`emptyValues` suivent (valeur initiale de création : `Mineure`).
- **Formulaires de création et de modification** : liste déroulante « Importance » sans option vide (`Mineure`, `Majeure`, `Critique`, valeurs de `IMPORTANCES`), sélection initiale `Mineure` à la création, valeur courante à la modification; erreur rattachée au champ (`f-importance`, `aria-describedby`/`aria-invalid`, même schéma que `project`). `TaskPages::FORM_FIELD` et `ANCHOR` gagnent `importance`; `Messages` : le message de l'exception est utilisé.
- **Détail** : ligne « Importance ». **Liste** : colonne « Importance » en texte (l'information ne repose pas sur la couleur seule). **Filtre** : liste « Importance » (« Toutes » = filtre absent) dans le formulaire GET, `name="importance"`; `TaskPages::list` l'ajoute à la boucle des filtres, valeur forgée hors liste : page « Filtre invalide » 422 existante; « Filtres actifs » et `isFiltered` en tiennent compte; ET avec état, catégorie, projet et `hide_done`.
- Page `/reopen`, message de terminaison : inchangés (la prochaine occurrence affichée vient du cas d'usage).

### Impact (analyse)

| Consommateur | Effet |
|---|---|
| **Données** | `tasks.importance` ajoutée, toutes les lignes à `Mineure`; aucune perte; rien d'appliqué hors local. |
| **REST** | réponses enrichies d'une clé; requêtes existantes valides; nouveau paramètre et nouveau champ facultatifs; nouveau code 422. Aucune rupture. |
| **MCP** | schémas enrichis (facultatif); aucun outil ajouté; cache `tools/list` : voir ci-dessus. |
| **Portail** | nouveau champ avec défaut, colonne et filtre; aucun contrat de page rompu. |
| **Code touché** | `TaskValidator`, `TaskRepository` (`TASK_COLUMNS`, `insert`, `update`, `findAll`, `copyAsOccurrence`), `TaskService` (docblock de la forme `Task`, passage du filtre), `TaskController` (liste blanche), `TaskTools` (schémas), `TaskForm`, `TaskPages`, vues `task_form`/`task_list`/`task_detail`. Inchangés : `DomainStatus`, `PathId`, `Bootstrap`, `Migrator`, gardes du portail, dépendances Composer (aucune ajoutée). |
| **Tests existants à adapter** (conséquences attendues, pas des régressions) | `TaskSchemaTest` (`ALL` et la liste migrée à la ligne 384 : ajouter `0005`), `ProjectsMigrationTest` (listes de migrations appliquées aux lignes 78 et 154); `TaskFormTest` (clés et ordre exacts de `toInput`/`valuesFromForm`, lignes 15, 46, 52); `TaskStatusServiceTest` (comparaison de l'occurrence, ligne 283 : ajouter `importance`); `McpTasksTest` (boucle des champs de `get_task`, ligne 139) et `McpToolsTest` (schémas); tests REST comparant une tâche complète. À relire test par test par le Developer. |
| **Sécurité** | validation serveur par liste fermée; filtre et écriture par requêtes paramétrées, colonnes en liste fermée; la valeur n'est jamais interpolée en SQL; échappement des gabarits du portail inchangé; aucune donnée sensible; les erreurs n'exposent que code, message et `details.field`. |

### Tests (validé, livrés : 745 tests OK en local; PostgreSQL réel)

1. **Schéma** (`TaskSchemaTest`) : `0005` sur base vide; liste appliquée à jour; rejeu sans effet; tâches créées avant `0005` (technique du test de rattrapage de `0002`) conservées avec `importance = 'Mineure'` et `updated_at` inchangé; la base accepte les trois valeurs, refuse `NULL` (`23502`), `''`, `mineure`, `MAJEURE`, `Autre` (`23514`, nom `tasks_importance_valid` dans le message); `TaskValidator::IMPORTANCES` = valeurs acceptées par le `CHECK`; `DEFAULT_IMPORTANCE` = défaut de la colonne.
2. **Validateur** (unitaire) : chaque valeur acceptée; création sans la clé = `Mineure`; création avec `null`, `''`, variantes de casse, `'Majeure '` : `importance_invalid`; modification avec `null` : `importance_invalid`; nombre, booléen, tableau : `field_type_invalid`; clé absente à la modification : inchangé; filtre valide, vide, hors liste, non chaîne.
3. **Service** : création sans/avec importance; `update` pose, change, laisse inchangé si absent, `null` refusé et tâche inchangée; changement identique sans écriture (`updated_at` stable); modification sur une tâche `done`; filtre seul et en ET avec `status`/`category`/`project`; tri inchangé (date butée puis `id`, importance sans effet); terminaison d'une périodique : l'occurrence a la même importance, y compris `Critique` (et `Majeure`), l'occurrence terminée la garde, `next_occurrence` l'expose; terminaison d'une non périodique : rien créé; réouverture : importance conservée; `status` avec `importance` : `status_not_alone`; `Critique` requérant une `Mineure` et inversement acceptés (I7).
4. **REST** : `POST`/`PATCH`/`GET` avec et sans le champ; 422 `importance_invalid` avec `details.field` (valeur inconnue, `null` en `PATCH`, `null` en `POST`), `field_type_invalid`; `GET ?importance=Critique` exact, ET avec les autres filtres, `?importance=` vide, `?importance=critique`, `?importance[]=x` : 422; paramètre inconnu toujours ignoré; terminaison par `PATCH` avec importance recopiée.
5. **MCP** : `tools/list` : `create_task` et `update_task` déclarent `importance` (énumération = `IMPORTANCES`, sans `null`, non obligatoire), `list_tasks` aussi; `change_task_status` inchangé; parité d'erreurs avec REST (même JSON); filtre exact via `list_tasks`; recopie à la terminaison.
6. **Portail** : formulaires avec liste sans option vide et `Mineure` sélectionnée à la création; valeur courante à la modification; valeur forgée hors liste ou vide : 422 avec message près du champ et saisie conservée; détail et colonne de liste; filtre exact en ET (état, catégorie, projet, `hide_done`), « Toutes », valeur forgée 422; recopie visible sur la nouvelle occurrence; parité de validation avec REST.
7. Suite complète rejouée par le Developer (`vendor/bin/phpunit`), smoke HTTP réel (REST, MCP, portail), README et CHANGELOG; vérification en navigateur du formulaire laissée à l'utilisateur ou au Developer.

### Points validés (importance)

Statut : points ci-dessous **validés** avec la conception (J1 à J5 inclus, aucun écart signalé à la livraison).

**Structurant (contrat modifié, schéma)** : (1) migration `0005` (colonne `importance text NOT NULL DEFAULT 'Mineure'`, `CHECK tasks_importance_valid`, pas d'index) — local et test seulement; (2) clé `importance` ajoutée à la forme `Task` des trois interfaces; (3) contrats modifiés : champ `importance` de `POST`/`PATCH`, paramètre `?importance=` de `GET /tasks`, schémas MCP de `create_task`, `update_task`, `list_tasks`; (4) nouveau code d'erreur `importance_invalid` (422); (5) recopie par `copyAsOccurrence`.
**Hypothèses à confirmer** : J1 — `importance: null` explicite à la **création** : refusé (422), comme à la modification, et non traité comme absent (cohérent avec `title`/`project`; la décision I2 ne vise que la modification); J2 — `?importance=` vide refusé en REST (comme `category`/`project`) alors que le portail le traduit en « Toutes »; J3 — le portail ne propose pas d'option vide pour l'importance (la valeur par défaut est `Mineure`); J4 — nom `importance_invalid`; J5 — numéro `0005`.
**Question produit** : aucune ouverte. Le tri ou le regroupement par importance restent hors tranche (I5).
**Vérifié** : `0005` et tests sur PostgreSQL 17 local (rapporté par le Developer). **Non vérifié** : `0005` sur PostgreSQL 16 et 18 et sur AlwaysData (droit `ALTER TABLE`, pgBouncer; jamais migré hors local); rendu navigateur (sauf mention contraire du Developer); comportement des clients MCP avec `tools/list` en cache.

## Gestion des projets dans le portail, vue projet, synthèse et matrice d'Eisenhower (tranche 2) — conception du 2026-10-18, **validée** et **livrée en local**

**Statuts (mis à jour le 2026-10-18 après livraison).** **Validé** (utilisateur) : V1, V6 (« moins d'un mois » = même quantième du mois suivant, exclu, règle de fin de mois des périodicités), V7 (b), V8, V9, V11, la conception structurante (points 1 à 4 de « Points à faire valider »), les hypothèses H-A à H-E, `list_projects` avec `field_unknown` en `isError` pour tout argument. **Livré en local** : toute la tranche (le code fait foi; 815 tests OK et smoke HTTP local, rapportés par le Developer, non rejoués par l'Architecte). **Non vérifié** : rendu navigateur, clavier, lecteur d'écran, AlwaysData. Les intertitres ci-dessous disant « (proposé) » valent **validé**. Statuts antérieurs : validé (utilisateur, via le backlog) : PC4 (suppression d'un projet utilisé refusée, 409), PC5 (renommage libre), PC6 (changement de catégorie propagé, nombre affiché), PC7 (MCP en lecture seule), PC8 (nom), PC12 (accueils ordinaires); V2/V3 (un compteur par état + total, ni retard ni pourcentage), V4 (Terminées hors grille, comptées), V5 (sans date : hors grille, comptée à part; en retard : colonne « moins d'une semaine » avec la mention « en retard »), V10 (portail seul, calcul en couche commune, date du jour injectable). (V1, V6, V7 (b), V8, V9, V11 : étaient « sans objection », désormais validés ci-dessus.) **Existant** (relu le 2026-10-18) : `ProjectService` (list, get, create, update, delete, names), `ProjectValidator`, `ProjectRepository` (dont `idByName`, `countTasks`), erreurs `Project*`, `ProjectController` (REST), `DomainStatus` (déjà `project_not_found` 404, `project_name_taken` 409, `project_in_use` 409, `InvalidProject` 422), `PortalAdapter`/`TaskPages`, `TaskTools`, `NextDueDate`, `Labels`. Aucun test rejoué par l'Architecte.

### Décisions

1. **Gestion des projets (portail) : aucun cas d'usage nouveau.** Le portail appelle `ProjectService` tel quel, comme REST (`ProjectController`). Toutes les règles (nom, unicité insensible à la casse, 409 `project_in_use`, `tasks_recategorized`) restent dans `ProjectService`/`ProjectValidator`/la base. Le portail ne prévérifie rien (pas de « projet vide ? » avant suppression : la base arbitre).
2. **Vue projet, synthèse et matrice : un seul cas d'usage nouveau, `ProjectOverviewService::overview(int $id)`**, en couche commune, qui renvoie synthèse et matrice calculées sur **une seule lecture** des tâches du projet (donc cohérentes entre elles : cellules + hors grille = total, par construction). Alternative écartée : ajouter la méthode à `ProjectService` (qui n'a pas besoin de lire les tâches ; responsabilité de lecture/statistique distincte de celle d'écriture de projets). Alternative écartée : comptage par `GROUP BY` SQL + requête séparée pour la matrice (deux instantanés qui peuvent diverger, deux endroits à tester).
3. **Classement d'urgence : une classe pure `UrgencyClassifier`** (sans base ni horloge), testable aux bornes. L'horloge est injectée dans `ProjectOverviewService` sous forme de `\Closure():string` renvoyant le jour `AAAA-MM-JJ` (défaut : jour courant en `Europe/Paris`, `new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))`). Alternative écartée : interface `Clock` (un seul usage, une closure suffit; à reconsidérer si un second besoin d'horloge apparaît).
4. **Aucune migration, aucune dépendance, aucun changement REST.** Voir « Impact ».
5. **Lien tâche -> projet sans changer la forme `Task`.** `Task` n'expose que le nom du projet (décision validée : identifiant non exposé). Le portail retrouve l'identifiant (pour lier le projet affiché sur une tâche à `/projects/{id}`) par `ProjectService::list()` : table nom -> id construite dans `TaskPages` (casse exacte, comme la référence). Cela remplace l'appel actuel à `names()` dans la liste et les formulaires (une requête chacun, inchangé en nombre) et ajoute un `list()` au détail. Alternative écartée : ajouter `project_id` à `Task` (modifie le contrat des trois interfaces pour un simple lien de présentation).

### Règle de classement (proposée; bornes V6 à valider)

Entrées : `due_date` (`AAAA-MM-JJ`) et `today` (`AAAA-MM-JJ`, Europe/Paris). Écart en jours calendaires `d = due_date - today`, calculé sur des dates (`DateTimeImmutable` en `UTC` à minuit, jamais sur des heures locales : aucun effet d'heure d'été).

| Condition | Colonne (code) | `late` |
|---|---|---|
| `d < 0` | `week` (« moins d'une semaine ») | `true` (V5) |
| `0 <= d <= 6` | `week` | `false` |
| `d >= 7` et `due_date < limite` | `month` (« moins d'un mois ») | `false` |
| `due_date >= limite` | `later` (« au-delà ») | `false` |

`limite` = `NextDueDate::compute(today, 1, 'month')` : même jour du mois suivant, ramené au dernier jour du mois s'il n'existe pas (règle déjà validée pour les périodicités, réutilisée plutôt que réécrite). La comparaison se fait sur les chaînes `AAAA-MM-JJ` (zéro-complétées, donc ordre lexicographique = ordre chronologique). `limite` est toujours au moins 28 jours après `today`, donc la colonne `month` n'est jamais vide par recouvrement avec `week`. Exemples (today = 2026-10-18) : 2026-10-17 -> `week` en retard; 10-18 -> `week`; 10-24 (J+6) -> `week`; 10-25 (J+7) -> `month`; 10-26 (J+8) -> `month`; 11-17 -> `month`; 11-18 (un mois exact) -> `later`. Cas de fin de mois (today = 2027-01-31) : limite 2027-02-28; 02-27 -> `month`, 02-28 -> `later`.

Une tâche entre dans la grille si et seulement si : état `todo` ou `in_progress` **et** `due_date` non nulle (une `done` est « hors matrice » même sans date; une non terminée sans date est « sans date butée »). Ligne = `importance` (ordre d'affichage `Critique`, `Majeure`, `Mineure` = `array_reverse(TaskValidator::IMPORTANCES)`, source unique, V8). Une périodique compte avec sa `due_date` courante (V9) : rien de spécifique dans le calcul, son état et sa date sont ceux de la ligne.

### Contrat du cas d'usage (proposé)

`ProjectOverviewService::overview(int $id): array`, composé dans `Bootstrap::projectOverviewService(Config, ?\Closure $today = null)`. Lève `ProjectNotFound` (404). Forme de sortie (`ProjectOverview`) :

```
{
  project:  Project,                                  // {id, name, category, task_count}, forme existante
  today:    "AAAA-MM-JJ",                             // jour de référence utilisé
  summary:  {todo:int, in_progress:int, done:int, total:int},
  matrix:   {
    columns: ["week","month","later"],
    rows: [ {importance:"Critique", cells:{week:Cell, month:Cell, later:Cell}},
            {importance:"Majeure",  cells:{...}}, {importance:"Mineure", cells:{...}} ]
  },
  outside:  {done:{count:int}, no_due_date:Cell}      // hors grille, jamais perdus
}
Cell = {count:int, tasks:list<{id:int,title:string,status:string,due_date:?string,late:bool}>}
```

Invariants testables : `summary.todo + in_progress + done = summary.total` ; somme des `count` des 9 cellules + `outside.done.count` + `outside.no_due_date.count` = `summary.total` ; `summary.done = outside.done.count` ; `summary.total = project.task_count`. Dans une cellule, tri `due_date` croissante puis `id` (celui de la requête); dans `no_due_date`, par `id`. `late` n'est vrai que dans la colonne `week`. Un projet sans tâche renvoie des zéros partout (pas d'erreur). `outside.done` ne porte que le compte (une tâche terminée n'est pas actionnable); `outside.no_due_date` porte aussi les tâches (hypothèse H-A ci-dessous).

Composition : `ProjectRepository::find($id)` (404 sinon), puis `TaskRepository::findOverviewRows(int $projectId)`, nouvelle lecture minimale : `SELECT t.id, t.title, t.status, t.importance, to_char(t.due_date, 'YYYY-MM-DD') AS due_date FROM tasks t WHERE t.project_id = :project_id ORDER BY t.due_date ASC NULLS LAST, t.id ASC` (colonnes nommées, requête paramétrée, aucun chargement des actions requises; index `tasks_project_id_idx` existant). Alternative écartée : `TaskService::list(['project' => nom])` (charge les actions requises de toutes les tâches et exige le nom). Le projet et les tâches sont lus par deux instructions (pas de transaction : lecture seule, écart sans conséquence ; l'invariant `total = task_count` peut échouer au plus si une tâche est créée entre les deux lectures, à ne pas tester en concurrence).

### Portail : pages et contrat (proposé)

Même contrat que les pages de tâches : `text/html`, `lang="fr"`, titre distinct, PRG (303 + flash) après écriture, gardes `Host` -> méthode -> `Origin` -> CSRF inchangées, 405 + `Allow`, identifiant invalide ou projet inconnu : 404 « Projet introuvable » (`PortalAdapter::fromException` gagne `ProjectNotFound`; `/projects/new` est routé avant `{id}`). Classe `Portal\ProjectPages` (parallèle à `TaskPages`), vues `project_list`, `project_detail`, `project_form`, `project_delete`; `Labels` gagne les libellés d'urgence; lien « Projets » dans la navigation du `layout`.

| Méthode, chemin | Cas d'usage | Succès | Erreurs |
|---|---|---|---|
| `GET /projects` | `ProjectService::list` | 200 : nom (lien vers la vue), catégorie en texte, nombre de tâches, liens Modifier/Supprimer, lien « Nouveau projet » | 503 |
| `GET /projects/new` | — | 200 formulaire (nom; catégorie : option vide « Choisir… » puis Pro, Perso) | — |
| `POST /projects` | `create` | 303 vers `/projects`, flash « Projet « nom » créé. » | 422 formulaire réaffiché, saisie conservée, message près du champ (`project_name_blank`, `project_name_too_long`, `category_invalid`, `text_invalid`); **409** `project_name_taken` près du nom |
| `GET /projects/{id}` | `ProjectOverviewService::overview` | 200 vue projet (voir plus bas) | 404 |
| `GET /projects/{id}/edit` | `get` | 200 formulaire prérempli, avec « Ce projet contient N tâche(s) : changer sa catégorie change celle de ces tâches. » | 404 |
| `POST /projects/{id}` | `update` (envoie toujours `name` et `category`) | 303 vers `/projects`, flash « Projet enregistré. »; si `tasks_recategorized > 0` : « Catégorie changée : N tâche(s) concernée(s). » (PC6) | 404; 422 / 409 comme à la création |
| `GET /projects/{id}/delete` | `get` | 200 confirmation nommant le projet et indiquant son nombre de tâches (information, pas une règle) | 404 |
| `POST /projects/{id}/delete` | `get` (nom du flash) puis `delete` | 303 vers `/projects`, flash « Projet « nom » supprimé. » | 404; **409** `project_in_use` : page de confirmation réaffichée avec « Suppression impossible : ce projet contient N tâche(s). », lien vers `/tasks?project=<nom>` ; projet intact |

Transport : `name` transmis tel quel (pas de `trim`, valeurs absentes = chaîne vide qui donne `project_name_blank`/`category_invalid` côté domaine), `category` telle quelle; un champ forgé de type tableau donne `field_type_invalid` (message du portail existant). Le nom est échappé partout (`$e`), y compris dans le flash et dans l'URL du filtre (`rawurlencode`). Aucune écriture par `GET`. Un nom contenant `/` n'affecte pas les routes (chemins par identifiant, décision PC existante).

**Vue projet (`GET /projects/{id}`)** : titre = nom du projet; catégorie en texte; lien « Voir les tâches du projet » (`/tasks?project=<nom>`), « Modifier », « Supprimer ». Date de référence affichée (« Situation au JJ/MM/AAAA ») pour rendre la matrice lisible et vérifiable.
- **Synthèse** : tableau avec légende, une ligne par état (« À faire », « En cours », « Terminée ») et « Total », libellé + nombre (jamais la couleur seule). Projet sans tâche : zéros et message « Ce projet ne contient aucune tâche. ».
- **Matrice** : `<table>` avec `<caption>`, `<th scope="col">` pour le coin « Importance » (en-tête de la colonne des lignes, au lieu d'une cellule vide : accessibilité; **validé**, livré), « Moins d'une semaine », « Moins d'un mois », « Au-delà » et `<th scope="row">` pour `Critique`, `Majeure`, `Mineure`. Chaque cellule : « N action(s) » (ou « Aucune ») puis la liste des titres en liens vers `/tasks/{id}` ; mention textuelle « en retard » (V5) devant le titre quand `late`. Aucun classement ni comptage dans la vue : elle itère sur `ProjectOverview`. Sous la grille : « Terminées (hors matrice) : N » et « Sans date butée : N » avec la liste des titres en liens.
- Depuis une tâche : le nom du projet du détail et de la liste devient un lien vers `/projects/{id}` (décision 5).

### MCP : outil `list_projects` (proposé; lecture seule)

Transport, authentification, gardes : inchangés. Ajout à `TaskTools::NAMES` et `definitions()` (10 outils avec `ping`).

| Élément | Contrat |
|---|---|
| Nom | `list_projects` |
| Cas d'usage | `ProjectService::list()` |
| `inputSchema` | `{"type":"object","properties":{},"additionalProperties":false}`; annotations `{"readOnlyHint": true}`; description : « Liste les projets (nom, catégorie Pro/Perso, nombre de tâches), triés par nom. » |
| Résultat | texte JSON : tableau de `Project` `[{id, name, category, task_count}]`, **identique octet pour octet** au corps de `GET /api/v1/projects`; `[]` si aucun projet |
| Erreurs | argument inconnu : résultat `isError: true`, JSON `{"error":{"code":"field_unknown","message":...,"details":{"field":...}}}` (`ToolArguments::rejectUnknownKeys($arguments, [])`, comme les autres outils); `arguments` non objet / outil inconnu : `-32602` (existant); base injoignable : `-32603` générique (existant) |

Écart avec le contrat de la tranche « Projets configurables », qui écrivait « `-32602` si arguments non vides » : seul `ping` (squelette) répond ainsi; tous les outils de tâches répondent `field_unknown` en `isError`. **Validé par l'utilisateur** : parité avec les outils de tâches (texte de la tranche 1 corrigé). Pas d'outil d'écriture sur les projets (PC7), pas d'outil pour la synthèse ni la matrice (V10). Un client qui garde `tools/list` en cache ne verra le nouvel outil qu'après rafraîchissement (même limite que les champs précédents).

### Impact (analyse)

| Consommateur | Effet |
|---|---|
| **REST** | **Aucun changement** : `/api/v1/projects` (déjà complet) et les tâches inchangés; la synthèse et la matrice ne sont pas exposées (V10). `Task`, `Project`, codes d'erreur inchangés. |
| **MCP** | `list_projects` ajouté (compatible : les outils existants et leurs schémas ne changent pas; `tools/list` passe de 9 à 10 outils, ce que des tests comptent peut-être : `McpToolsTest`, `McpTasksTest` à adapter si le nombre ou la liste y sont figés). |
| **Kernel** (**existant**, écart de conception relevé à la livraison, jugé **acceptable**) | La conception ne mentionnait pas `Kernel`, alors que son routage n'acheminait vers le portail que `/`, `/tasks`, `/tasks/...` (les autres chemins : 404 JSON). Sans correction, toutes les pages `/projects...` répondaient 404. Le Developer a ajouté `/projects` et `/projects/...` à la condition du portail (`src/Http/Kernel.php`). Cohérent : même motif que `/tasks` (égalité exacte ou préfixe `/projects/`, donc `/projectsX` reste en 404), aucun conflit avec `/api/v1` (REST, y compris `/api/v1/projects`, inchangé) ni `/mcp`, aucune règle métier ajoutée. `APP_BASE_PATH` : le préfixe est retiré par `Kernel` avant ce test, donc `/<prefixe>/projects` est acheminé comme `/projects`, et un chemin hors préfixe reste 404; les URL sortantes du portail passent par le même mécanisme que `/tasks` (section « Préfixe d'URL »). Le Developer a à tenir à jour le commentaire de la classe. Lacune : aucun test de préfixe (`APP_BASE_PATH` non vide) ne cible `/projects` (recherche dans `tests/` sans résultat pour `Prefix`), à ajouter; fichiers statiques `/assets/...` inchangés (servis par le serveur web, non vérifiés sous AlwaysData). |
| **Portail** | Nouvelles routes `/projects...` (aucun conflit : `/tasks` et `/` inchangés); lien de navigation; liens projet -> vue. Constructeur `PortalAdapter` : un argument de plus (`ProjectOverviewService`), donc adaptation des appels directs (`tests/Integration/PortalTasksTest.php`, `PortalTasksActionsTest.php`, et `Bootstrap`); `Bootstrap::kernel` gagne en dernier argument facultatif `?\Closure $today = null` pour fixer la date dans les tests HTTP (appels existants valides). `TaskPages` : `names()` remplacé par `list()` pour la table nom -> id (les vues `task_list`/`task_form` consomment toujours des noms). |
| **Couche commune / dépôts** | Ajout : `ProjectOverviewService`, `UrgencyClassifier`, `TaskRepository::findOverviewRows`. Aucune méthode existante modifiée. |
| **Données / migrations** | **Aucune migration** : colonnes `status`, `importance`, `due_date`, `project_id` et index `tasks_project_id_idx` existent (`0002`, `0004`, `0005`). Aucun effet sur les données. |
| **Dépendances** | **Aucune** (classes natives `DateTimeImmutable`, `DateTimeZone`). |
| **Sécurité** | Écritures portail : mêmes gardes que les tâches (Host, Origin, CSRF, échappement, CSP sans script); requêtes paramétrées; les erreurs n'exposent que code et message français. Aucune donnée nouvelle. |
| **Hébergement (AlwaysData)** | Rien de nouveau côté base ou extension. Un point : le fuseau `Europe/Paris` s'appuie sur la base de fuseaux embarquée par PHP (même usage déjà en place dans `Labels::dateTime`) : **non vérifié** sur le compte AlwaysData cible, comme PHP 8.3 effectif; sans objet pour le développement local. Hérités et toujours non vérifiés : réécriture d'URL, racine `public/`, sessions, `LC_CTYPE` (unicité insensible à la casse des noms non ASCII). |

### Tests à écrire avant le code (proposé)

1. **Unitaires `UrgencyClassifier`** (date fixe) : veille (`week`, `late`), jour même, J+6, J+7, J+8, J+30, un mois exact (`later`), un jour avant le mois exact (`month`), fin de mois (2027-01-31 -> limite 2027-02-28; année bissextile 2028-01-31 -> limite 2028-02-29), changement d'année, passage heure d'été/hiver sans effet (dates seules).
2. **Service `ProjectOverviewService`** (PostgreSQL réel, horloge injectée) : projet avec tâches connues dans chaque état et chaque cellule (dont `done`, sans date, en retard, `Critique`/`Majeure`/`Mineure`) : compteurs, contenu des 9 cellules, `outside`, invariants (somme, `total = task_count`); projet sans tâche (zéros); autre projet non compté; périodique non terminée classée avec sa date courante, périodique terminée (occurrence suivante comptée séparément); changement d'état, de date et d'importance déplace la tâche au rappel; renommage du projet : même id, même résultat; id inconnu : `ProjectNotFound`; cas `done` sans date = `outside.done`; l'horloge par défaut renvoie un `AAAA-MM-JJ` valide (pas de test de valeur).
3. **Portail projets** (`Bootstrap::kernel($config, $session, $today)`) : liste (accueils présents, nombre de tâches); création valide (visible dans la liste et dans la liste déroulante du formulaire de tâche); nom vide/blancs, 256 caractères, catégorie vide ou `pro` : 422 avec message près du champ et saisie conservée; doublon `opf` face à `OPF` : 409, rien créé; renommage (les tâches suivent, nouveau nom partout; sans changement : pas d'erreur); changement de catégorie avec N tâches : message avec N, catégorie des tâches lue via le détail; suppression d'un projet vide (confirmation `GET`, puis `POST`); suppression d'un projet utilisé : 409 avec N, projet intact; `POST` sans jeton, `Origin` ou `Host` étrangers : 403 sans écriture; nom `<script>` échappé (liste, vue, flash, confirmation, lien de filtre encodé); parité REST sur chaque refus (nom vide, doublon, projet utilisé : même `code`); 404 pour `/projects/999`, `/projects/abc`, `/projects/0`; 405 + `Allow`.
4. **Portail vue projet** : synthèse (libellé + nombre), projet vide (message), matrice en tableau avec en-têtes de ligne/colonne, « en retard » en texte, liens vers `/tasks/{id}`, `outside` visibles, projet d'un autre n'apparaît pas, date de référence affichée; lien projet -> vue depuis le détail d'une tâche; garde d'échappement des gabarits (`Views/` neufs inclus).
5. **MCP** : `tools/list` contient `list_projects` (schéma strict, `readOnlyHint`); `tools/call` renvoie le même texte que `GET /api/v1/projects` (parité d'octets) ; argument inconnu : `isError` `field_unknown`; liste reflète une création/renommage faits par REST ; aucun outil `create_project`/`update_project`/`delete_project`.
6. Hors de ces tests (non vérifiable par eux) : rendu navigateur, clavier, lecteur d'écran sur le tableau de la matrice (liste de contrôle manuelle, comme B2).

### Ordre de réalisation (proposé)

(1) gestion des projets dans le portail; (2) `UrgencyClassifier` + `ProjectOverviewService` + `findOverviewRows`; (3) vue projet (synthèse); (4) matrice dans la vue; (5) `list_projects`. Chaque étape est livrable et testable seule; (2) ne dépend pas de (1).

### Points à faire valider (tranche 2) — **tous validés par l'utilisateur** (historique conservé ci-dessous)

**Structurant (nouveau contrat / composant, pas de schéma ni de dépendance)** : (1) nouveau cas d'usage `ProjectOverviewService` et forme `ProjectOverview` (contrat interne, base d'une exposition REST/MCP future éventuelle); (2) nouvelles routes du portail `/projects...`; (3) nouvel outil MCP `list_projects` et son traitement des arguments inconnus (`field_unknown` en `isError` plutôt que `-32602` du texte validé plus haut); (4) changement de constructeur de `PortalAdapter` et de signature de `Bootstrap::kernel` (ajout en dernier argument facultatif).
**Hypothèses à confirmer** : H-A `outside.no_due_date` liste les titres (les Terminées ne sont que comptées); H-B le formulaire de création de projet impose le choix de la catégorie (option vide « Choisir… », pas de défaut); H-C la page de confirmation de suppression est affichée même pour un projet contenant des tâches (le refus est celui de `POST`, la base arbitre; un message préalable ne fait qu'informer); H-D le redirect après création/modification/suppression va à `/projects`; H-E le portail trouve l'identifiant du projet d'une tâche par son nom exact (décision 5).
**Questions produit renvoyées au Product Owner / utilisateur** : confirmer explicitement V6 (la borne « moins d'un mois » est « même quantième du mois suivant, exclu », avec la règle de fin de mois des périodicités : 2027-01-31 -> limite 2027-02-28, soit 28 jours; autre lecture possible : J+30), ainsi que V7 (b), V8, V9, V11 et V1, aujourd'hui « proposés sans objection ».
**Vérifié (rapporté par le Developer, non rejoué par l'Architecte)** : phpunit, 815 tests OK en local; smoke HTTP local. **Non vérifié** : rendu navigateur, clavier, lecteur d'écran (matrice); AlwaysData (voir « Impact »).

## Matrice à nombres cliquables et version mobile du portail — conception du 2026-10-20, **proposée** (décisions produit validées)

**Statuts.** **Validé** (utilisateur, 2026-10-20, via `BACKLOG.md`) : E1 (filtre d'urgence au portail seul, REST et MCP inchangés), E2 (la liste d'une case = exactement la case), E3 (case à 0 : texte non cliquable), E4 (« Hors matrice » : deux nombres cliquables, filtre « sans date butée » au portail), pas de « dont N en retard »; MO1 (responsive, mêmes routes), MO2 (tout reste faisable, seuil 40 rem), MO3 (liste réduite au titre, filtres repliés derrière un bouton). **Proposé** (tout le reste ci-dessous, dont MO4 et MO5 confiés à l'Architecte) : à valider avant implémentation. **Existant** (relu le 2026-10-20) : `UrgencyClassifier::classify`, `ProjectOverviewService` (forme `ProjectOverview`), `TaskPages::list` (filtres `status`, `category`, `project`, `importance`, `hide_done`; `hide_done` est déjà un filtre propre au portail, appliqué après `TaskService::list`), vues `task_list` et `project_detail`, `portal.css` (un seul point de rupture `max-width: 40rem`; `min-width: 10rem` par cellule de matrice), CSP `style-src 'self'` sans script. Aucun test rejoué par l'Architecte.

### A. Matrice cliquable

**Aucun changement REST/MCP, aucune migration, aucune dépendance (vérifié par lecture du code : toutes les colonnes nécessaires existent; `TaskValidator::validateFilters` n'est pas modifié).**

**Décisions (proposées)**

1. **Une seule règle de placement, partagée.** `UrgencyClassifier` gagne `placement(string $status, ?string $dueDate, string $today): array{zone:'grid'|'done'|'no_due_date', column:?string, late:bool}` : `done` -> zone `done` (même sans date); sinon sans date -> `no_due_date`; sinon `grid` + `classify()`. `ProjectOverviewService` est refactoré pour l'appeler (comportement identique, couvert par les tests existants) au lieu de porter lui-même le tri état/date. C'est ce qui **garantit nombre = lignes** : la case et la liste qui s'ouvre appliquent le même code, sur les mêmes données et le même jour de référence. Alternative écartée : filtrer en SQL (`due_date BETWEEN ...`) : recopierait les bornes de V6 dans une seconde langue, avec deux endroits à tester et à faire diverger.
2. **Filtre dans un composant commun distinct, pas dans `TaskService::list`.** Nouvelle classe pure `Application\TaskUrgencyFilter::apply(array $tasks, ?string $urgency, bool $noDueDate, string $today): array` (tâches `Task` déjà lues, ordre conservé). Sémantique : `urgency = U` garde les tâches de zone `grid` dont la colonne est `U` (donc non terminées, avec date; les « en retard » sont dans `week`); `noDueDate` garde la zone `no_due_date`; les deux ensemble s'additionnent en ET (résultat vide, pas une erreur); `null` = filtre absent. `urgency` hors `week|month|later` (y compris chaîne vide, tableau) : `InvalidTask('urgency_invalid', 'urgency', ...)`. Alternative écartée : ajouter les clés à `TaskService::list`/`validateFilters` : elles deviendraient acceptées par REST et par `list_tasks` (MCP rejette aujourd'hui toute clé inconnue en `field_unknown`), contre E1. Le portail lit déjà toutes les tâches puis filtre `hide_done` en mémoire : même modèle, volume actuel faible.
3. **Jour de référence unique et injectable.** `TaskPages` reçoit la même closure `today` que `ProjectOverviewService` : `PortalAdapter` gagne un dernier argument facultatif `?\Closure $today = null` (appels existants valides), `Bootstrap::kernel` résout la closure une fois (défaut : `ProjectOverviewService::parisToday()`, nouvelle méthode statique qui porte l'expression `Europe/Paris` aujourd'hui dans le constructeur) et la passe aux deux. Limite assumée : le compte et la liste sont deux requêtes HTTP; autour de minuit (Paris) ils peuvent différer d'un jour. Non traité.
4. **Contrat portail (`GET /tasks`)**, paramètres ajoutés, tous facultatifs et combinés en ET avec l'existant :

| Paramètre | Valeurs | Comportement |
|---|---|---|
| `urgency` | `week`, `month`, `later` | non terminées avec date butée dans la fenêtre (`week` inclut le retard); absent ou vide : filtre inactif (« Tous », comme les autres); autre valeur ou tableau : **422**, page d'erreur « Filtre invalide » existante (`Renderer::error`), message `Messages` : « Échéance inconnue. Choisissez moins d'une semaine, moins d'un mois ou au-delà. », aucune liste |
| `no_due_date` | `1` | non terminées sans date butée; toute autre valeur : ignorée (comme `hide_done`) |

   Liens générés par `project_detail` (via `$url('/tasks', [...])`, nom de projet encodé, échappé en HTML) : case -> `project`, `importance`, `urgency`; « Terminées » -> `project` + `status=done`; « Sans date butée » -> `project` + `no_due_date=1` (sans importance : E4). Un nombre à 0 est du texte, jamais un lien (E3).
5. **Vue projet.** Chaque case n'affiche que son nombre; `ProjectOverview` n'est **pas** modifié (`Cell.tasks`, `late`, `outside.no_due_date.tasks` restent produits mais inutilisés par la vue; ne pas les retirer dans cette tranche, nettoyage possible plus tard sans impact externe). Nom accessible sans `aria-label` : `<a>3<span class="visually-hidden"> actions, importance Critique, échéance moins d'une semaine</span></a>` (le texte visible est contenu dans le nom); le 0 reçoit le même texte masqué (« 0 action ... ») sans lien. `Labels` gagne la tournure en minuscules (« moins d'une semaine »). « Hors matrice » : deux éléments « Terminées : N » et « Sans date butée : N », nombres cliquables si > 0. La mention « en retard » par action disparaît (E).
6. **Liste ouverte.** `task_list` : nouveau champ « Échéance » (liste « Tous / Moins d'une semaine / Moins d'un mois / Au-delà ») et case « Sans date butée seulement », libellés associés, avec indication « ne concerne que les tâches non terminées ». Le résumé « Filtres actifs » indique échéance et sans-date, plus « situation au JJ/MM/AAAA » quand `urgency` est actif, et offre « Retour à la vue du projet » quand le projet filtré est connu (`projectIds`). `isFiltered` en tient compte. `urgency=week` + `status=done` donne une liste vide (ET), message existant « Aucune tâche ne correspond aux filtres. ».

**Analyse d'impact (matrice)**

| Consommateur | Effet |
|---|---|
| REST, MCP | Aucun. `GET /api/v1/tasks?urgency=week` reste ignoré (200, toutes les tâches); `list_tasks` avec `urgency` reste `field_unknown`. Écart de parité assumé (E1), à tester pour que personne ne l'élargisse par inadvertance. |
| Couche commune | Ajouts : `UrgencyClassifier::placement`, `TaskUrgencyFilter`, `ProjectOverviewService::parisToday`; `ProjectOverviewService::overview` refactoré à comportement égal. `TaskService`, `TaskValidator`, `TaskRepository` inchangés. |
| Portail | `TaskPages` (+ closure), `PortalAdapter`/`Bootstrap::kernel` (argument facultatif), `Messages`, `Labels`, vues `project_detail` et `task_list`, CSS. Les tests qui cherchent les titres dans la vue projet ou « en retard » (`PortalProjectsTest` / tests de la matrice) sont à adapter : changement de comportement voulu. |
| Données, migrations, dépendances | Aucune. Aucun index à ajouter. |
| Sécurité | Entrées lues en `GET`, validées côté serveur (liste fermée), jamais concaténées en SQL (aucune requête nouvelle); valeurs échappées à l'affichage; aucune écriture. |
| Hébergement | Rien de nouveau; fuseau `Europe/Paris` toujours non vérifié sur AlwaysData. |

**Tests (à écrire avant le code; PostgreSQL réel quand indiqué, date injectée)**

1. Unitaires `UrgencyClassifier::placement` : `done` avec et sans date (zone `done`); `todo`/`in_progress` sans date (`no_due_date`); avec date : veille (`week`, `late`), J, J+6, J+7, J+8, un jour avant et au mois exact, fin de mois (2027-01-31), année bissextile.
2. Unitaires `TaskUrgencyFilter` (liste de tâches construites à la main) : chaque valeur sur les bornes; `done` jamais retenue; ordre conservé; `noDueDate`; les deux = vide; `urgency` invalide, vide, hors casse : `urgency_invalid`; `null` : liste inchangée.
3. Intégration portail (`Bootstrap::kernel($config, $session, $today)`) : **invariant** sur un projet dont les 9 cases sont connues (dont une en retard, une vide) : pour chaque case, lire dans le HTML de la vue le nombre et l'URL du lien, ouvrir l'URL, compter les lignes de `tbody` : égalité; une case à 0 n'a pas de lien; « Terminées » et « Sans date butée » : même égalité; un autre projet n'est pas compté; projet renommé : liens à jour et égalité conservée; nom `<script>` : échappé dans la vue, le lien encodé et le résumé des filtres; plus aucun titre ni « en retard » dans la grille; `urgency=bogus`, `urgency=`, `urgency[]=x` : 422 sans table; ET avec `importance` et `hide_done`; `no_due_date=0` ignoré.
4. Non-régression E1 : REST `?urgency=week` ignoré; MCP `list_tasks` `urgency` : `field_unknown`.
5. Accessibilité (sur le HTML seulement) : texte masqué présent dans les liens et les zéros, en-têtes `scope` conservés. Rendu, clavier, lecteur d'écran : recette manuelle (voir B).

### B. Version mobile

**Décision de principe (validée MO1 à MO3) : CSS seul, mêmes routes et mêmes pages; aucune détection d'appareil côté serveur, aucun JavaScript, aucune dépendance.** Seuil unique : le `@media (max-width: 40rem)` existant (les règles « desktop » sont le complément `not all and (max-width: 40rem)`). Les gabarits ne changent que par des classes et par le conteneur des filtres.

**MO4 — colonnes de la liste (proposé).** Les colonnes Catégorie, Projet, Importance, État, Date butée, Périodicité reçoivent la classe `col-extra` sur leur `<th>` et leur `<td>`; au seuil, `.col-extra { display: none }`. Les données restent dans le HTML (consultables au-dessus du seuil, testables). Conséquence : `display: none` retire aussi ces cellules des lecteurs d'écran à l'écran étroit, ce qui est cohérent avec ce que voit un utilisateur voyant (MO3); alternative écartée : masquage « visuel seulement » (`visually-hidden`), qui ferait lire sept colonnes à un lecteur d'écran sur un téléphone et ne correspondrait pas à la page. Alternative écartée : ne pas envoyer les colonnes selon l'appareil (contredit MO1 (a)). Le titre reste un lien, `a` en `display: block` avec `min-height: 2.75rem` (≥ 44 px pour une racine de 16 px).

**Filtres repliés (proposé).** Mécanisme : `<details class="filters-panel"><summary>Filtres</summary><form ...>...</form></details>`, **sans JavaScript**. `summary` est natif : atteignable et actionnable au clavier (Entrée/Espace) et au toucher, l'état ouvert/fermé est exposé par le navigateur aux technologies d'assistance (à confirmer à la recette avec VoiceOver iOS et TalkBack). Replié par défaut ; le résumé « Filtres actifs » reste hors du panneau, donc visible replié. `summary` : `min-height: 2.75rem`, marqueur natif conservé, focus visible par la règle existante étendue à `summary`.
Au-dessus du seuil les filtres doivent rester visibles sans clic : `@supports selector(::details-content)` + `@media (not all and (max-width: 40rem))` : `.filters-panel > summary { display: none }` et `.filters-panel::details-content { content-visibility: visible }`. Sans `@supports`, le navigateur garde le résumé visible à toutes les largeurs (dégradation correcte : un clic de plus au bureau). Source : MDN, `::details-content`, « Baseline 2025, newly available, depuis septembre 2025 » (consulté le 2026-10-20); **non vérifié** : que l'écrasement de `content-visibility` rouvre bien le contenu d'un `details` fermé dans chaque navigateur ciblé (la page MDN ne le dit pas; test de recette obligatoire sur Chrome, Firefox, Safari de bureau). **Repli prévu si la recette échoue :** supprimer le bloc `@supports` et garder le panneau replié à toutes les largeurs (robuste, effet visible sur le bureau : à faire accepter). Alternatives écartées : bascule par case à cocher CSS (rôle `checkbox`, état moins fidèle), `open` rendu côté serveur (indifférent à la largeur), second formulaire dupliqué.

**MO5 — matrice (proposé : tableau 3×3 conservé).** Au seuil : `table.matrix td { min-width: 0 }`, retrait du `white-space: nowrap` de l'en-tête de ligne, `th, td` à `padding: .5rem .25rem`, `overflow-wrap: anywhere`, nombres centrés, `table-layout` automatique. À 360 px, le contenu utile mesure 328 px (marges de 1 rem) : en-tête de ligne ≈ 5,5 rem, trois colonnes d'environ 5 rem dont les en-têtes (« Moins d'une semaine ») passent sur plusieurs lignes. Les nombres sont des cibles ≥ 44 × 44 px (`display: inline-flex; min-width: 2.75rem; min-height: 2.75rem`). Alternative écartée : empiler en liste de trois groupes (perd la lecture croisée, ajoute du balisage) ; à reconsidérer seulement si la recette montre un débordement.

**Reste de l'interface (proposé, CSS au seuil uniquement)** :
- Navigation : liens `header.site nav a` et `.brand` en `inline-flex`, `min-height: 2.75rem`, `flex-wrap` conservé (les trois entrées restent visibles en un toucher, aucun menu hamburger).
- Détail : `dl` déjà en une colonne; `.button`, `button` : `min-height: 2.75rem`; `.inline-form` (retrait d'une action requise) passe en `display: block; margin: .5rem 0 0`.
- Formulaires, filtres : `.filters .field { flex: 1 1 100% }`, `input`, `select`, `textarea` en `width: 100%; max-width: 100%`, `font-size: max(1rem, 16px)` (le CSS actuel hérite déjà `1rem` : la règle sert de garde contre le zoom automatique d'iOS), cases à cocher de `1.5rem` avec libellé de hauteur ≥ 44 px, `td, th { overflow-wrap: anywhere }`, `select` à `max-width: 100%` (options longues des actions requises).
- Pages non détaillées (`project_list`, `task_status`, `task_delete`, formulaires de projet) : mêmes règles génériques; absence de défilement horizontal à 360 px **à constater en recette**, pas supposée. Si `project_list` déborde (colonne « Actions »), à traiter comme MO4 (classe `col-extra` ou liens empilés) sans changer le balisage sémantique.
- Rien dans `public/assets/portal.css` n'exige une nouvelle ressource ni un changement de CSP (`style-src 'self'`).

**Impact (mobile).** REST, MCP, données, migrations, dépendances, sécurité : aucun. Portail : gabarits `task_list` (classes, `details`), `project_detail`, éventuellement `project_list`, et CSS. Hébergement : fichier statique existant, même réserve qu'avant (assets sous AlwaysData non vérifiés).

**Tests**
1. PHPUnit (HTML) : la liste contient toujours les sept colonnes avec leurs valeurs et la classe `col-extra` sur les six colonnes secondaires; `<details>` et `<summary>` présents, champs de filtre dans le `form` à l'intérieur du `details`, `form` toujours soumis en `GET` ; les assertions existantes sur les filtres passent.
2. **Recette manuelle navigateur** (non remplaçable par PHPUnit), à noter dans le rapport du Developer avec navigateur et appareil : émulation 360 × 640 puis, si possible, téléphone réel; pages : liste (vide, remplie, filtrée), détail (tâche avec actions requises), formulaires de tâche et de projet (avec erreur), vue projet, liste des projets, confirmation de suppression, changement d'état. Cases : (a) aucun défilement horizontal de la page à 360 px (inspecter `document.documentElement.scrollWidth <= innerWidth`) ; (b) cibles tactiles ≥ 44 × 44 px (nav, titres, boutons, nombres de la matrice, `summary`, cases à cocher) ; (c) texte des champs ≥ 16 px et pas de zoom à la prise de focus sur iOS ; (d) `summary` activable au clavier et au toucher, état annoncé par un lecteur d'écran ; (e) zoom navigateur 200 % sans perte ; (f) au-dessus de 40 rem : filtres visibles sans clic sur Chrome, Firefox, Safari de bureau, 7 colonnes présentes, matrice inchangée en structure ; (g) information non portée par la couleur seule ; (h) focus visible partout.

### À faire valider avant implémentation (structurant ou à choisir)

1. **Nouveaux composants communs** `TaskUrgencyFilter` et `UrgencyClassifier::placement` (et refactor de `ProjectOverviewService`) : contrat interne, pas de schéma ni de dépendance.
2. **Contrat portail** : paramètres `urgency` et `no_due_date`, 422 sur `urgency` invalide, ET avec les autres filtres, `urgency` + `status=done` = vide.
3. **Écart de parité REST/MCP** (E1, validé) : la liste d'urgence n'existe qu'au portail.
4. **Signature** : `PortalAdapter` gagne un dernier argument facultatif (`$today`); aucune rupture.
5. **Repli des filtres** : `details` + `::details-content` (progressif, non vérifié que l'écrasement fonctionne partout) ; repli accepté si la recette échoue : panneau replié à toutes les largeurs, y compris au bureau.
6. **Lecteurs d'écran** : les colonnes masquées à l'écran étroit le sont aussi pour eux (`display: none`).
7. Limite connue : écart d'un jour possible entre la vue projet et la liste ouverte autour de minuit (Paris).

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
