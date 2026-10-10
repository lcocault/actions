---
name: backlog
description: Conventions de tenue de BACKLOG.md — structure, types d’éléments, états, critères d’acceptation, décisions ouvertes et prochaine tranche. À utiliser pour ajouter, préciser, re-prioriser ou clôturer un élément du backlog, ou pour enregistrer une décision produit.
---

# Tenir le backlog

`BACKLOG.md` est la référence de suivi fonctionnel et technique. Il garde trois sections, dans cet ordre.

## 1. Éléments priorisés

Un tableau, une ligne par élément :

| Colonne | Contenu |
|---|---|
| Priorité | `P0` (bloque le reste) à `P3`. |
| Type | `Fonctionnalité`, `Amélioration technique`, `Correction` ou `Décision` (question à trancher). |
| Besoin / élément | Le besoin ou l’histoire utilisateur, en une phrase. Pas de solution technique. |
| Critères d’acceptation | Vérifiables : quelqu’un doit pouvoir constater qu’ils sont satisfaits ou non. |
| État | `À faire`, `En cours`, `Bloqué — <raison>`, `Terminé — <vérification effectuée>`. Les dépendances s’ajoutent à l’état : `À faire — dépend de P1`. |

- Un élément trop gros pour une tranche est découpé avant d’être démarré.
- Un critère du type « fonctionne correctement » n’est pas vérifiable : nommer l’entrée, le résultat attendu ou l’erreur attendue.
- `Terminé` exige une vérification réellement effectuée, nommée dans l’état. Sans preuve, l’élément reste `En cours`.
- Les éléments terminés restent dans le tableau tant qu’ils éclairent le contexte; le détail de ce qui a été livré va dans `CHANGELOG.md`, pas ici.

## 2. Décisions et risques ouverts

Une puce par sujet : la question, pourquoi elle compte, et ce qu’elle bloque. Y figurent aussi les hypothèses non vérifiées et les risques.

Quand l’utilisateur tranche :

1. retirer la puce de cette section;
2. reporter la règle retenue dans les critères d’acceptation des éléments concernés;
3. débloquer les éléments qui en dépendaient;
4. signaler à l’Architecte les décisions qui touchent un contrat ou le modèle de données, pour qu’elles soient reportées dans `docs/architecture.md`.

Ne jamais enregistrer comme décidé un comportement que l’utilisateur n’a pas explicitement choisi. Présenter les options et leurs conséquences sur les données, puis attendre.

## 3. Prochaine tranche proposée

Un court paragraphe : ce qui est proposé, pourquoi maintenant, et ce qui doit être validé avant de commencer. Une tranche tient en un incrément livrable et vérifiable; elle ne contient aucun élément bloqué.

## Avant de rendre la main

- Chaque élément a un type, une priorité, des critères vérifiables et un état.
- Les dépendances citées existent encore et sont à jour.
- Rien de ce qui est décrit comme existant n’est en réalité une proposition.
- Le document reste court : supprimer ce qui ne sert ni à décider, ni à concevoir, ni à valider.
