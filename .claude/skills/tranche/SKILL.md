---
name: tranche
description: Déroule une tranche de travail complète en enchaînant les agents product-owner, architecte et developer, avec validation de l’utilisateur aux étapes structurantes. À utiliser quand l’utilisateur demande de traiter un élément du backlog, de démarrer la prochaine tranche ou de faire avancer le projet.
argument-hint: "[élément du backlog ou besoin — par défaut : la prochaine tranche proposée]"
---

# Dérouler une tranche

Tranche demandée : $ARGUMENTS

Sans précision, prendre la « Prochaine tranche proposée » de `BACKLOG.md`.

Tu orchestres depuis la session principale : chaque étape est confiée à l’agent du rôle, lancé au premier plan, et tu attends son rapport avant la suivante. Les agents ne peuvent pas interroger l’utilisateur — c’est toi qui poses leurs questions et rapportes ses réponses. Annonce à chaque étape quel rôle intervient, et ne dis jamais qu’un rôle est intervenu s’il n’a pas été lancé.

## 1. Product Owner — le besoin

Lancer `product-owner` pour préciser le besoin, sa priorité et ses critères d’acceptation, et relever les questions ouvertes qui touchent la tranche.

**Arrêt :** si une décision produit est nécessaire, la poser à l’utilisateur avec les options et leurs conséquences, puis relancer `product-owner` pour l’enregistrer. Ne pas continuer sur une hypothèse.

## 2. Architecte — les impacts

Lancer `architecte` avec le besoin précisé, pour vérifier les impacts sur les interfaces, les données et l’organisation du code, et mettre à jour `docs/architecture.md`.

**Arrêt :** faire valider par l’utilisateur tout ce qui est structurant — nouveau contrat ou contrat modifié, schéma de données, nouvelle dépendance, rupture de compatibilité. Une tranche qui ne touche à rien de cela passe sans validation.

Si la tranche est purement documentaire ou de décision, elle s’arrête ici : passer à l’étape 4.

## 3. Developer — la réalisation

Lancer `developer` avec l’élément du backlog et les décisions validées. Il implémente, teste, met à jour `CHANGELOG.md` et rend un rapport de vérification.

**Arrêt :** s’il signale un écart avec la conception, revenir à l’étape 2 avec cet écart au lieu de le laisser l’adopter.

## 4. Product Owner — la clôture

Relancer `product-owner` avec le rapport de vérification, pour confronter le résultat aux critères d’acceptation, mettre à jour les états du backlog et proposer la tranche suivante.

## 5. Compte rendu à l’utilisateur

En quelques lignes : ce qui a été livré, par quel rôle, ce qui a été réellement vérifié, ce qui reste à vérifier, les décisions en attente et la prochaine tranche proposée. Ne pas créer de commit sans demande.
