# Architecture

## État du dépôt

Au 2026-10-08, le dépôt ne contient qu’un README minimal. Il n’y a ni application, ni dépendance, ni test, ni contrat REST/MCP, ni schéma ou migration existants. Les éléments ci-dessous décrivent une direction proposée, pas une architecture déjà implémentée ou validée.

## Direction proposée

- PHP et PostgreSQL sont les contraintes du projet; la compatibilité exacte avec l’offre AlwaysData visée reste à vérifier avant de choisir versions, extensions, framework ou dépendances.
- Garder une application de taille proportionnée : règles métier et cas d’usage communs, avec des adaptateurs distincts pour REST, MCP et le portail web. Les interfaces ne doivent pas réimplémenter la validation métier.
- Organiser le code autour du domaine et des cas d’usage; isoler l’accès PostgreSQL et les adaptateurs HTTP/MCP. Le découpage précis sera arrêté après validation des contrats et des prérequis d’hébergement.
- Le domaine initial de tâche comprend un titre obligatoire, une catégorie `Pro` ou `Perso`, une date butée, une périodicité, une estimation, un contexte et zéro ou plusieurs tâches requises. Une tâche ne peut dépendre d’elle-même, directement ou indirectement.

## Contrats à définir

- **REST :** opérations CRUD sur les tâches; méthode, chemin, formats d’entrée/sortie, codes d’erreur et versionnement restent à préciser avant implémentation.
- **MCP :** opérations CRUD équivalentes, traduites vers les mêmes cas d’usage. Transport, authentification, noms d’outils et schémas dépendent des prérequis Claude/AlwaysData et d’une décision produit.
- **Portail :** utilise les mêmes cas d’usage et validations; accès et authentification dépendent de la décision sur le modèle mono- ou multi-utilisateur.

Aucune interface ni compatibilité descendante n’existe actuellement. Avant une évolution ultérieure d’un contrat partagé, inventorier ses consommateurs et privilégier une évolution compatible.

## Données et migrations

- Emplacement réservé proposé : `database/migrations/`. Ce dépôt n’a actuellement aucune migration ni aucun mécanisme d’exécution.
- Choisir un mécanisme léger, ses commandes reproductibles et ses vérifications avant la première modification du schéma; ne pas ajouter de dépendance avant d’avoir vérifié son intérêt et sa compatibilité.
- Ajouter chaque évolution sous forme de migration ordonnée, traçable et non destructive. Ne jamais réécrire une migration appliquée; toute correction passe par une nouvelle migration.
- Documenter sauvegarde, ordre de déploiement et retour arrière lorsque pertinent. Ne pas lancer de migration sur un environnement opérationnel sans autorisation explicite.
- Les décisions encore ouvertes sur périodicité et suppression de dépendances peuvent modifier le modèle de données; elles doivent être tranchées avant de figer le schéma.

## Validation, sécurité et tests

- Valider côté serveur les entrées de toutes les interfaces; utiliser des requêtes PostgreSQL paramétrées et ne pas exposer de détails sensibles dans les erreurs.
- Garder configuration et secrets hors du dépôt. Définir authentification et protections web avant toute exposition publique.
- Aucun outil de test n’existe encore. Choisir une stratégie légère avec le premier incrément, puis couvrir les règles métier (dont les cycles), les contrats et les migrations sans présumer d’une extension PHP indisponible.
- Vérifier les prérequis et versions supportés par l’offre AlwaysData cible avant de sélectionner une solution technique ou de documenter une commande de déploiement.

## Décisions en attente

Voir [BACKLOG.md](../BACKLOG.md) pour les questions produit et techniques, leurs dépendances, leur priorité et la prochaine tranche proposée.
