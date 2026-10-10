---
name: implementation
description: Démarche d’implémentation d’une tranche — conditions de départ, tests, migrations PostgreSQL, sécurité, écarts avec la conception, CHANGELOG.md et rapport de vérification. À utiliser pour écrire ou modifier du code, des tests ou des migrations dans ce dépôt.
---

# Implémenter une tranche

## Avant d’écrire

Vérifier, et s’arrêter si une condition manque :

- l’élément du backlog est `À faire` ou `En cours`, non bloqué, avec des critères d’acceptation vérifiables;
- les contrats et le schéma concernés sont au statut **validé** dans `docs/architecture.md`;
- la tranche est assez petite pour être livrée et vérifiée d’un seul tenant — sinon, proposer un découpage.

## Pendant

- **Règles métier à un seul endroit.** REST, MCP et portail appellent les mêmes cas d’usage; un adaptateur ne revalide pas et ne réimplémente pas.
- **Tests avec le code.** Chaque critère d’acceptation a un test qui échouerait sans le changement. Couvrir en priorité les règles métier (dont les cycles de dépendances), les contrats et les migrations.
- **Sécurité.** Validation côté serveur de toutes les entrées, requêtes PostgreSQL paramétrées, pas de détail sensible dans les erreurs, configuration et secrets hors du dépôt.
- **Rien de silencieux.** Un changement de comportement ou de données existantes est annoncé, jamais glissé dans un autre changement.
- **Dépendances.** Uniquement celles prévues par l’architecture. Ne pas présumer d’une extension PHP : vérifier qu’elle fait partie des prérequis confirmés.

## Écarts avec la conception

Si le contrat ou l’architecture documentés ne peuvent pas être respectés tels quels, ne pas adopter l’écart. Décrire ce qui bloque, l’écart proposé et ses conséquences, puis attendre la décision.

## Migrations

- Une migration par évolution, dans `database/migrations/`, ordonnée et non destructive.
- Ne jamais modifier une migration déjà appliquée; corriger par une nouvelle migration.
- Une migration échoue clairement plutôt que de laisser un état partiel, et préserve les données existantes.
- L’exécuter et la vérifier sur une base locale ou de test uniquement. Jamais sur un environnement opérationnel sans autorisation explicite.

## Journal des changements

Ajouter une entrée à `CHANGELOG.md` sous la date du jour (`## AAAA-MM-JJ`) : ce qui a changé, les migrations ajoutées ou l’absence de migration, et les limites connues. Les faits, pas les intentions.

## Rapport de vérification

Distinguer strictement :

- **Vérifié** : chaque commande réellement exécutée et son résultat (tests, analyse statique, migration sur base de test, appel manuel d’une interface).
- **Non vérifié** : ce qui n’a pas pu l’être, et pourquoi (outil absent, pas d’accès à l’hébergement, pas de base disponible).
- **Critères d’acceptation** : pour chacun, satisfait, non satisfait ou non vérifié.

Un test qui n’a pas été lancé n’est pas un test qui passe. Ne pas modifier l’état d’un élément dans `BACKLOG.md` : le Product Owner le fait à partir de ce rapport.
