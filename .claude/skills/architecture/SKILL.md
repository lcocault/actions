---
name: architecture
description: Conventions de docs/architecture.md — décisions d’architecture, contrats REST et MCP, modèle de données, migrations, analyse d’impact et vérification des prérequis AlwaysData. À utiliser pour concevoir ou faire évoluer une interface, un schéma ou l’organisation du code, avant toute implémentation.
---

# Documenter l’architecture

`docs/architecture.md` est la référence de conception. Chaque affirmation y porte l’un de ces trois statuts, sans ambiguïté :

- **existant** : présent dans le dépôt et vérifiable;
- **proposé** : direction soumise à validation;
- **validé** : accepté par l’utilisateur, donc opposable au Developer.

Le Developer n’implémente que ce qui est validé. Quand une proposition est validée, mettre à jour son statut plutôt que d’ajouter un paragraphe.

## Décisions

Une décision tient en quelques lignes : le contexte, le choix, ce qu’il implique, et l’alternative écartée si elle était sérieuse. Ne documenter que les décisions qui contraignent la suite; le reste se lit dans le code.

Toute dépendance, tout framework ou composant ajouté est justifié par ce qu’il évite d’écrire ou de maintenir, et par sa compatibilité vérifiée avec l’hébergement.

## Contrats

Les trois interfaces appellent les mêmes cas d’usage : la validation et les règles métier vivent à un seul endroit, les adaptateurs ne font que traduire.

- **REST** — pour chaque opération : méthode, chemin, format d’entrée et de sortie, codes et format d’erreur. Préciser le versionnement.
- **MCP** — transport, authentification, puis pour chaque outil : nom, schéma d’entrée, résultat, erreurs. Chaque outil renvoie au cas d’usage qu’il expose.
- **Portail** — cas d’usage appelés et modèle d’accès.

Un contrat est assez précis quand le Developer peut écrire le test avant le code.

## Analyse d’impact

Avant de modifier un contrat partagé ou le modèle de données :

1. inventorier les consommateurs : adaptateurs REST, MCP et portail, tests, migrations déjà appliquées, données existantes;
2. dire ce qui casse pour chacun;
3. proposer d’abord une évolution compatible (ajout facultatif, nouvelle version à côté de l’ancienne);
4. si la rupture est inévitable, l’écrire explicitement avec le chemin de migration — c’est une étape structurante, à faire valider.

## Données et migrations

- Migrations versionnées dans `database/migrations/`, ordonnées, traçables et non destructives.
- Une migration appliquée n’est jamais réécrite : toute correction passe par une nouvelle migration.
- Documenter la commande d’application, la vérification et, lorsque c’est pertinent, la sauvegarde et le retour arrière.
- Une question produit ouverte qui touche le modèle (voir `BACKLOG.md`) doit être tranchée avant de figer le schéma correspondant.

## Hébergement

Rien n’est supposé disponible sur AlwaysData. Pour chaque prérequis (version de PHP, extension, version de PostgreSQL, transport, tâche planifiée), noter la source consultée et sa date, ou marquer le point « non vérifié ». Un choix technique qui repose sur un point non vérifié reste une proposition.

## Sécurité, erreurs et configuration

Pour chaque interface conçue, préciser : validation côté serveur, requêtes paramétrées, authentification, ce que les erreurs exposent ou taisent, et où vit la configuration (hors dépôt pour les secrets).

## Avant de rendre la main

- Les statuts existant / proposé / validé sont à jour et cohérents avec le dépôt.
- Aucune règle métier n’est décrite dans deux adaptateurs.
- Les points qui demandent une validation de l’utilisateur sont listés.
- Les questions produit rencontrées sont renvoyées au backlog, pas tranchées ici.
