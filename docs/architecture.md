# Architecture

Légende des statuts : **existant** (dans le dépôt, vérifiable), **proposé** (soumis à validation, non opposable au Developer), **validé** (accepté par l’utilisateur). Sans mention, un élément est **proposé**.

## État du dépôt

**Existant** (au 2026-10-10) : documentation, et squelette implémenté par le Developer (`src/`, `bin/migrate`, `compose.yaml`, `.env.example`, `tests/`, `README.md`). Aucun schéma métier ni migration SQL : seule la table de suivi `schema_migrations` est créée par `bin/migrate`. L'Architecte a relu `compose.yaml`, `.env.example`, `Config.php` et `bin/migrate`; la conformité complète du code aux contrats relève des tests du Developer.

## Décisions validées (tranche « squelette »)

- **Validé** : PHP sans framework + Composer minimal (chaque dépendance justifiée).
- **Validé** : MCP en HTTP distant.
- **Validé** : squelette sans authentification, jamais exposé publiquement ni déployé sur AlwaysData dans cette tranche. L’authentification est un élément séparé du backlog, requis avant toute exposition.
- **Validé** (2026-10-10, utilisateur) : PostgreSQL local par conteneur Docker/Podman (`compose.yaml`).
- **Validé** (2026-10-10, utilisateur) : contrat REST `GET /api/v1/health` (200/503/404/405, format d'erreur commun).
- **Validé** (2026-10-10, utilisateur) : contrat MCP minimal : `POST /mcp`, sans session, JSON seul (GET/DELETE : 405), batchs refusés, contrôle de l'en-tête `Origin`, outil `ping`, erreurs JSON-RPC décrites.
- **Validé** (2026-10-10, utilisateur) : zéro dépendance d'exécution, `phpunit/phpunit` en dev, PHP >= 8.3.
- **Validé** (2026-10-10, utilisateur) : migrations en `NNNN_nom.sql`, table `schema_migrations` avec checksum, pas de « down », refus hors `local`/`test` sauf option explicite.

Les sections « Contrat REST » et « Contrat MCP » du squelette sont validées et implémentées; les autres sections « (proposé) » ci-dessous couvrant des points de cette liste (migrations, dépendances, PHP) ont le statut de la liste. Restent **proposés** ou **à vérifier** : tous les points « non vérifiés » AlwaysData, le contrat du portail, la procédure de démarrage détaillée, les variables de configuration et la convention de tests (acceptées de fait par le Developer, sans validation explicite de l'utilisateur).

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
| Réécriture d’URL (`mod_rewrite` via `.htaccess` vers un front controller) | **Non vérifié** | La page PHP n’évoque que `.htaccess`, pas `mod_rewrite`. La page dédiée `.htaccess` n’a pas pu être lue (404 sur l’URL essayée). |
| Racine web pointant sur `public/` | **Non vérifié** | Se règle dans Web > Sites; page de configuration non lue. |
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
  Portal/                 Adaptateur portail (pages)
  Application/            Cas d’usage et règles métier, partagés par les trois adaptateurs
    Diagnostic.php        Seul cas d’usage du squelette
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

## Contrat du portail (proposé)

`GET /` : 200 `text/html; charset=utf-8`, page HTML statique indiquant que l’application fonctionne (aucun style, aucune authentification dans cette tranche). Cas d’usage appelé : `Diagnostic::ping()`.

## Configuration (proposé)

- Lue depuis des variables d’environnement ; en local, un chargeur minimal (une trentaine de lignes, sans dépendance) lit un fichier `KEY=VALUE` dont le chemin vient de `ACTIONS_ENV_FILE` (défaut : `.env` à la racine, ignoré par Git). Les variables déjà définies priment.
- Variables : `APP_ENV` (`local`, `test`, `production`; **défaut `production` si absente**, choix le plus restrictif pour le garde-fou des migrations), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `MCP_ALLOWED_ORIGINS` (liste séparée par des virgules, vide par défaut), `TEST_DB_NAME` (défaut `actions_test`), `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` (utilisées par `compose.yaml` seulement).
- État au 2026-10-10 : implémenté par le Developer (existant, relu dans `src/Infrastructure/Config.php`, `compose.yaml`, `.env.example`); le comportement reste à valider par l'utilisateur.
- `.env.example` fourni, sans secret; `.env` et tout fichier de secrets dans `.gitignore`.
- Le mécanisme de configuration sur AlwaysData (variables d’environnement ou fichier hors racine web) n’est pas vérifié : à trancher à la tranche de déploiement.

## Données et migrations

- **Existant** : `database/migrations/` ne contient qu’un README.
- **Proposé** : fichiers SQL `NNNN_description.sql` (par exemple `0001_...`), appliqués dans l’ordre numérique par `bin/migrate`. Aucun fichier de migration dans cette tranche (critère 4 : seule la table de suivi est créée).
- Table de suivi `schema_migrations(version text primary key, checksum text not null, applied_at timestamptz not null default now())`, créée par `bin/migrate` lui-même avec `CREATE TABLE IF NOT EXISTS` (donc rejouable sans effet).
- Comportement : verrou consultatif PostgreSQL pendant l’exécution; chaque migration dans une transaction (DDL transactionnel de PostgreSQL) avec échec explicite et retour de code non nul; une migration déjà appliquée dont le checksum SHA-256 a changé fait échouer l’exécution (jamais de réécriture). Pas de migration « down » : correction par nouvelle migration, retour arrière par restauration de sauvegarde.
- Commandes : `php bin/migrate` (applique), `php bin/migrate status` (liste appliquées / en attente). Garde-fou : refus si `APP_ENV` n’est ni `local` ni `test`, sauf option explicite `--allow-env=<APP_ENV>`; aucune exécution sur un environnement opérationnel sans autorisation de l’utilisateur.
- Alternative écartée : Phinx ou Doctrine Migrations (dépendances lourdes pour un besoin de quelques dizaines de lignes; compatibilité hébergement non évaluée).
- **Existant** (relu dans `src/Infrastructure/Migrator.php`) : chaque fichier est exécuté par un unique `PDO::exec($sql)` dans une transaction. Conséquences : plusieurs instructions par fichier, corps de fonction `$$ ... $$` avec points-virgules internes et `CREATE TRIGGER` sont supportés (protocole de requête simple, sans découpage côté PHP; `ATTR_EMULATE_PREPARES=false` ne concerne que `prepare`). Interdits dans un fichier : `BEGIN`/`COMMIT`, `CREATE INDEX CONCURRENTLY`, tout ce qui ne tourne pas en transaction. Le verrou consultatif de session de `bin/migrate` suppose une connexion directe (port 5432, pas pgBouncer 5433 : non vérifié, à respecter pour la migration AlwaysData). Le nom doit respecter `^\d{4}_[a-z0-9_]+$`.

### Schéma des tâches (proposé, à valider; décisions produit du 2026-10-10 **validées**)

**Validé par l'utilisateur (2026-10-10)** : suppression d'une tâche requise par d'autres refusée; périodicité = entier > 0 + unité (jour/semaine/mois/année), colonnes nulles ou renseignées ensemble; mono-utilisateur (ni table `users`, ni propriétaire); périodicité non nulle ⇒ date butée non nulle.

**Proposé** : migration `0001_tasks_and_dependencies.sql` (une seule : table, liens, fonction et trigger doivent être atomiques). Esquisse (le Developer écrit le fichier) :

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
    OR (recurrence_interval > 0 AND recurrence_unit IN ('day','week','month','year'))),
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

Sens : la ligne `(task_id, required_task_id)` signifie « `task_id` requiert `required_task_id` ». Les contraintes sont nommées explicitement pour que l'application les reconnaisse (`PDOException::errorInfo`, SQLSTATE + nom de contrainte) sans analyser les messages.

Choix de types :
- **`due_date` en `date`, pas `timestamptz`** : une date butée est un jour calendaire; `timestamptz` ajouterait une heure fictive et des décalages de fuseau (une échéance « le 12 » pourrait devenir le 11 ou le 13 selon le client) et rendrait l'arithmétique de périodicité mois/année ambiguë. Évolution compatible si une heure devient utile : colonne facultative `due_time` ajoutée. Hypothèse produit à confirmer (question 1).
- **Estimation en minutes entières** (`estimated_minutes integer > 0`) : triable, additionnable, sans ambiguïté de format; `interval` PostgreSQL écarté (sérialisation JSON/MCP plus lourde, sémantique jours/mois inutile). Les interfaces exposent des minutes entières.
- **Catégorie en `text` + `CHECK`**, pas de type `ENUM` : ajouter une valeur ne demande qu'une nouvelle migration sur le `CHECK`, sans les contraintes d'`ALTER TYPE`. Valeurs exactes `Pro` et `Perso` (sensibles à la casse; la normalisation, si elle existe, est dans la couche Application).
- **Périodicité** : `recurrence_interval` + `recurrence_unit` (valeurs anglaises stables en base, libellés français côté interfaces). La règle « la tâche se répète après sa date butée » est portée par la donnée; le calcul de la prochaine occurrence est un cas d'usage futur, hors schéma.
- Identifiants `bigint` identité : opaques pour les clients. `updated_at` est positionné par la couche Application (pas de trigger, règle visible au même endroit que les autres). Aucune colonne de statut/« terminée » : absente du modèle validé (question 2).
- Suppression : `ON DELETE RESTRICT` côté tâche requise, donc `DELETE` d'une tâche requise par d'autres échoue (SQLSTATE `23503`, contrainte `task_dependencies_required_task_id_fkey`); `ON DELETE CASCADE` côté `task_id` : supprimer une tâche retire ses propres prérequis (elle n'en a plus besoin), ce qui ne viole aucune règle validée.

#### Refus des cycles indirects (proposé)

Règle métier unique, portée par **un trigger PL/pgSQL en base**; la couche Application ne réimplémente pas l'algorithme : elle traduit l'erreur en exception de domaine (`CyclicDependency`), commune à REST (422), MCP (erreur outil) et portail.

Trigger `BEFORE INSERT OR UPDATE OF task_id, required_task_id ON task_dependencies FOR EACH ROW`, fonction `task_dependencies_check_cycle()` :
1. `PERFORM pg_advisory_xact_lock(hashtext('actions.task_dependencies'))` : sérialise toute écriture de lien (verrou global de transaction, relâché au `COMMIT`; coût négligeable en mono-utilisateur, compatible pgBouncer en mode transaction).
2. Atteignabilité depuis `NEW.required_task_id` en suivant « requiert » : `WITH RECURSIVE reach(id) AS (SELECT NEW.required_task_id UNION SELECT d.required_task_id FROM task_dependencies d JOIN reach ON d.task_id = reach.id) SELECT 1 FROM reach WHERE id = NEW.task_id`. Si une ligne est trouvée, le nouveau lien fermerait un cycle. `UNION` (et non `UNION ALL`) garantit la terminaison même sur des données déjà cycliques.
3. Si cycle : `RAISE EXCEPTION '...' USING ERRCODE = '23514', CONSTRAINT = 'task_dependencies_no_cycle'`. L'auto-dépendance reste refusée par le `CHECK` (même SQLSTATE, autre nom de contrainte).
4. Sinon `RETURN NEW`.

Compromis :
- **Trigger seul (retenu)** : garantit l'invariant quel que soit le chemin d'écriture (REST, MCP, portail, `psql`, futures importations), sans duplication. Coût : logique en PL/pgSQL, à tester en base réelle (donc tests d'intégration obligatoires), et un message d'erreur sans le chemin du cycle.
- **Application seule (écarté)** : lecture du graphe puis `INSERT` en deux temps; non sûr en concurrence (deux transactions voient chacune un graphe sans cycle, `A→B` et `B→A` sont validées ensemble) sauf à reprendre un verrou équivalent, et contournable par tout accès hors Application.
- **Les deux (écarté pour l'instant)** : doublon de règle. Si un message plus riche est voulu (chemin `A → B → A`), l'Application peut, *après* une erreur du trigger, relire le chemin pour l'afficher : affichage seulement, pas de décision.
- **Concurrence** : en `READ COMMITTED` (défaut PostgreSQL, à ne pas changer), chaque instruction prend un instantané neuf : après obtention du verrou, la requête de l'étape 2 voit les liens validés par la transaction concurrente, donc la seconde insertion échoue. En `REPEATABLE READ`/`SERIALIZABLE` l'instantané antérieur au verrou pourrait ne pas les voir : ne pas utiliser ces niveaux pour écrire des liens (à écrire dans les conventions du Developer). Un `INSERT` multi-lignes (`A→B, B→A` en une instruction) doit être refusé : les lignes précédentes de la même instruction sont visibles du trigger (fonction volatile), **à confirmer par un test**, non exécuté ici. Risque d'interblocage : une seule clé de verrou, donc pas de cycle de verrous entre liens; un `DELETE` de tâche concurrent est arbitré par PostgreSQL via les clés étrangères.
- Performance : parcours borné par le nombre de tâches atteignables; sans objet à l'échelle d'un outil personnel. Index `task_dependencies_required_idx` utile aux recherches inverses (« qui requiert X ») et aux contrôles `RESTRICT`; la clé primaire couvre `task_id`.

Mapping d'erreurs (à implémenter dans `Application`, un seul endroit) : `23514` + `task_dependencies_no_cycle` ou `task_dependencies_no_self` -> `InvalidDependency`/`CyclicDependency`; `23505` sur la clé primaire des liens -> doublon (idempotent ou 409, à décider avec le contrat REST); `23503` côté `required_task_id` à l'insertion -> tâche requise inconnue; `23503` à la suppression -> `TaskIsRequired` (409), la liste des dépendants étant lue pour le message; `55P03`/`40P01` -> erreur interne générique.

Compatibilité hébergement : PostgreSQL 16–18 (vérifié le 2026-10-10, voir tableau AlwaysData). Utilisés : `GENERATED ... AS IDENTITY`, CTE récursive, PL/pgSQL (inclus par défaut), advisory locks : tous disponibles depuis PostgreSQL 10 ou moins; aucune extension. **Non vérifié** : droit de créer une fonction/un trigger avec le rôle de base AlwaysData et comportement de pgBouncer; à tester sur une base AlwaysData jetable avant la tranche de déploiement (aucune migration sans autorisation de l'utilisateur).

Analyse d'impact : aucune donnée ni consommateur existant (aucune migration métier appliquée, seul `schema_migrations` existe). REST, MCP et portail n'ont pas encore de contrat sur les tâches : les contrats CRUD seront écrits dans une étape suivante à partir de ce schéma. Évolutions compatibles prévues : colonnes facultatives ajoutées par migration (statut, `due_time`), nouvelle valeur de catégorie par modification du `CHECK`.

#### Tests d'intégration du schéma (proposé)

Base `actions_test` migrée par `bin/migrate` (donc la migration 0001 est testée sur base vide, et sa rejouabilité); chaque test isole ses données (transaction annulée ou `TRUNCATE tasks RESTART IDENTITY CASCADE`). Un test par règle, vérifiant le SQLSTATE et le nom de contrainte :
- tâche minimale valide; titre vide ou blanc, catégorie hors `Pro`/`Perso`, minutes `<= 0`, intervalle `<= 0`, unité inconnue : refusés.
- périodicité à moitié renseignée refusée; périodicité sans date butée refusée; date seule acceptée.
- auto-dépendance refusée; lien en double refusé; tâche requise inexistante refusée.
- cycles : `A→B, B→A`; indirect `A→B, B→C, C→A`; insertion multi-lignes formant un cycle; `UPDATE` d'un lien qui créerait un cycle; graphe en losange (sans cycle) accepté.
- suppression : tâche requise par une autre refusée (`23503`); tâche sans dépendant supprimée avec retrait de ses propres liens.
- concurrence déterministe, sans processus parallèle : connexion 1 `BEGIN` + insertion `A→B` non validée; connexion 2 avec `SET lock_timeout = '500ms'` tente `B→A` et doit échouer en `55P03` (le verrou sérialise); après `COMMIT` de la connexion 1, `B→A` échoue en `23514`.
- les tests de la couche Application vérifient seulement la traduction SQLSTATE -> exceptions de domaine.

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

Voir le rapport de l’Architecte : contrats REST/MCP ci-dessus, dépendances, mécanisme de migrations (table de suivi), questions ouvertes. Pour l’état des décisions produit, voir [BACKLOG.md](../BACKLOG.md).
