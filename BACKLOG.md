# Backlog

Référence fonctionnelle et technique du projet. Aucun comportement applicatif n’est implémenté à ce stade.

## Éléments priorisés

| Priorité | Type | Besoin / élément | Critères d’acceptation | État |
|---|---|---|---|---|
| P0 | Décision | Trancher les règles de périodicité, la suppression d’une tâche requise, le modèle d’accès des utilisateurs et les choix d’interface MCP. | Les règles et contrats concernés sont documentés avant la conception du schéma et des interfaces. | Bloqué — avis produit requis |
| P0 | Amélioration technique | Vérifier les versions PHP/PostgreSQL, extensions et transports réellement disponibles sur l’offre AlwaysData cible. | Les prérequis sont confirmés à partir de sources d’hébergement à jour, sans supposer qu’une extension ou un service est disponible. | À faire |
| P1 | Amélioration technique | Définir l’architecture minimale, les contrats REST/MCP, les conventions de tests et le mécanisme de migrations. | Les composants partagent les règles métier; les choix de dépendances et d’hébergement sont justifiés; les migrations sont versionnées et leur commande d’application est documentée. | À faire |
| P1 | Fonctionnalité | Enregistrer et appliquer les migrations PostgreSQL initiales, puis fournir le modèle de tâche et les règles métier communes. | Les champs obligatoires et facultatifs sont validés; les cycles de dépendances sont refusés; une migration préserve les données et échoue clairement en cas d’erreur. | À faire — dépend de P0 |
| P2 | Fonctionnalité | Exposer le CRUD des tâches par l’API REST. | Les opérations créer, consulter, modifier et supprimer respectent les validations métier et renvoient des réponses et erreurs documentées. | À faire — dépend de P1 |
| P2 | Fonctionnalité | Exposer le CRUD des tâches par une interface MCP utilisable depuis Claude. | Les outils MCP couvrent les mêmes opérations et règles métier que REST; transport, schémas d’entrée et erreurs sont documentés. | À faire — dépend de P0 et P1 |
| P3 | Fonctionnalité | Fournir un portail web de gestion des tâches. | Un utilisateur autorisé peut créer, consulter, modifier et supprimer des tâches avec les mêmes règles métier que les autres interfaces. | À faire — dépend du modèle d’accès et de P1 |
| P3 | Amélioration technique | Préparer une procédure de déploiement et d’exploitation progressive sur AlwaysData. | Configuration hors dépôt, sauvegarde et restauration, ordre de déploiement et vérification des migrations sont documentés; aucune migration opérationnelle n’est lancée sans autorisation. | À faire |
| P0 | Amélioration technique | Établir les documents de suivi initiaux à partir de l’état réel du dépôt. | Le backlog, le journal des changements, l’architecture initiale et l’état des migrations distinguent explicitement l’existant des propositions. | Terminé — documents vérifiés |

## Décisions et risques ouverts

- **Périodicité :** préciser les unités et le calcul de la prochaine échéance (notamment fin de mois/année bissextile) et le comportement si aucune date butée n’est définie.
- **Dépendances :** préciser si la suppression d’une tâche requise est refusée, répercute la suppression ou retire le lien. Aucun de ces comportements n’est choisi ici.
- **Accès :** préciser si l’application est mono-utilisateur ou multi-utilisateur et le niveau d’authentification attendu avant toute exposition publique.
- **MCP :** choisir le transport et le mode d’authentification compatibles avec Claude et l’hébergement.
- **Hébergement :** confirmer les versions et extensions PHP nécessaires ainsi que l’accès PostgreSQL pour l’offre AlwaysData visée avant de choisir framework, pilote ou dépendance.

## Prochaine tranche proposée

Traiter les décisions P0 avec le Product Owner et vérifier les prérequis AlwaysData; ensuite seulement valider l’architecture et démarrer un premier incrément de domaine et de schéma non destructif. Aucun travail fonctionnel n’est commencé avant cette validation.
