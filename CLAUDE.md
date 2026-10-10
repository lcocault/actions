# Actions — gestionnaire de tâches

Outil de gestion de tâches professionnelles et personnelles, développé en mode itératif. Les tâches doivent pouvoir être alimentées et consultées depuis Claude au moyen d’une interface MCP.

L’application propose une API REST, une interface MCP et un portail web. Dans un premier temps, les opérations sont limitées au CRUD.

L’état d’avancement n’est pas décrit ici : il se lit dans [BACKLOG.md](BACKLOG.md), [docs/architecture.md](docs/architecture.md) et [CHANGELOG.md](CHANGELOG.md).

## Modèle initial d’une tâche

- **Titre** : obligatoire.
- **Catégorie** : obligatoire, `Pro` ou `Perso`.
- **Date butée** : facultative.
- **Périodicité** : facultative; si elle est définie, la tâche se répète à fréquence régulière après sa date butée.
- **Estimation de durée** : facultative.
- **Description / Contexte** : facultative.
- **Actions requises** : zéro, une ou plusieurs autres tâches à réaliser avant celle-ci.

Une tâche ne peut pas dépendre d’elle-même, directement ou indirectement.

## Contraintes techniques

- Langage : PHP. Base de données : PostgreSQL. Déploiement cible : AlwaysData.
- Vérifier les prérequis et la compatibilité des solutions choisies avec ces contraintes; ne pas supposer qu’une extension PHP, un service ou une fonctionnalité est disponible sur l’hébergement.
- Ne pas introduire de dépendance ou de composant non nécessaire sans expliquer son intérêt.

## Rôles

Le travail est réparti entre trois agents spécialisés. L’utilisateur prend les décisions produit importantes et valide les étapes structurantes.

| Rôle | Agent | Skill | Référence tenue |
|---|---|---|---|
| Product Owner | `product-owner` | `backlog` | [BACKLOG.md](BACKLOG.md) |
| Architecte | `architecte` | `architecture` | [docs/architecture.md](docs/architecture.md) |
| Developer | `developer` | `implementation` | code, tests, migrations, [CHANGELOG.md](CHANGELOG.md) |

- Déléguer à l’agent dont c’est la responsabilité plutôt que de traiter le sujet dans la session principale. Pour une tranche complète, suivre le skill `tranche` (`/tranche`).
- Ne jamais prétendre avoir délégué une tâche si ce n’est pas le cas. Si un rôle est tenu dans la session principale, indiquer clairement lequel intervient.
- Un agent ne peut pas interroger l’utilisateur : il renvoie ses questions dans son rapport, et la session principale les pose avant de poursuivre.

## Règles communes

- Signaler les ambiguïtés fonctionnelles importantes avant de décider d’un comportement qui aurait des conséquences sur les données ou leur utilisation. Ne pas trancher à la place de l’utilisateur.
- Dans les documents, distinguer explicitement l’existant, le proposé et le validé.
- Ne jamais lancer de migration sur un environnement opérationnel sans autorisation explicite.
- Indiquer précisément les vérifications réellement effectuées et celles qui restent à faire.
- Documents du dépôt rédigés en français, de façon concise.
