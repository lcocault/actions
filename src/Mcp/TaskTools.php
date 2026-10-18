<?php

declare(strict_types=1);

namespace Actions\Mcp;

use Actions\Application\ProjectService;
use Actions\Application\TaskService;
use Actions\Application\TaskStatus;
use Actions\Application\TaskValidator;

/**
 * Les outils des tâches (et `list_projects`, lecture seule) : définition (nom, description, schéma, annotations) et appel de TaskService.
 * Chaque outil = un cas d'usage + une traduction; les exceptions remontent à McpAdapter, seul endroit qui les convertit.
 */
final class TaskTools
{
    public function __construct(private readonly TaskService $tasks, private readonly ProjectService $projects)
    {
    }

    public function has(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    private const NAMES = [
        'create_task', 'get_task', 'list_tasks', 'update_task', 'delete_task',
        'add_required_action', 'remove_required_action', 'change_task_status', 'list_projects',
    ];

    /**
     * @param array<string,mixed> $arguments
     * @return mixed valeur à encoder en JSON (texte du résultat)
     * @throws \Actions\Application\DomainError
     */
    public function call(string $name, array $arguments): mixed
    {
        switch ($name) {
            case 'create_task':
                return $this->tasks->create($arguments);
            case 'list_tasks':
                return $this->tasks->list($arguments);
            case 'get_task':
                ToolArguments::rejectUnknownKeys($arguments, ['id']);

                return $this->tasks->get(ToolArguments::id($arguments, 'id'));
            case 'update_task':
                // `update` et non `patch` : `patch` aiguillerait `status` vers le changement d'état.
                $id = ToolArguments::id($arguments, 'id');
                unset($arguments['id']);

                return $this->tasks->update($id, $arguments);
            case 'delete_task':
                ToolArguments::rejectUnknownKeys($arguments, ['id', 'confirm']);
                $id = ToolArguments::id($arguments, 'id');
                ToolArguments::requireConfirmation($arguments);
                $this->tasks->delete($id);

                return ['deleted' => true, 'id' => $id];
            case 'add_required_action':
            case 'remove_required_action':
                ToolArguments::rejectUnknownKeys($arguments, ['id', 'required_task_id']);
                $id = ToolArguments::id($arguments, 'id');
                $requiredId = ToolArguments::id($arguments, 'required_task_id');

                return $name === 'add_required_action'
                    ? $this->tasks->addRequiredAction($id, $requiredId)
                    : $this->tasks->removeRequiredAction($id, $requiredId);
            case 'list_projects':
                ToolArguments::rejectUnknownKeys($arguments, []);

                return $this->projects->list();
            case 'change_task_status':
                ToolArguments::rejectUnknownKeys($arguments, ['id', 'status']);
                $id = ToolArguments::id($arguments, 'id');
                $result = $this->tasks->changeStatus($id, $arguments['status'] ?? null);

                return $result['task'] + ['next_occurrence' => $result['next_occurrence']];
        }
        throw new \LogicException('Outil inconnu : ' . $name);
    }

    /**
     * Noms de projets pour l'`enum` des schémas, lus à chaque `tools/list`. Base injoignable (ou liste vide) :
     * schéma servi sans `enum` (erreur journalisée), pour que `tools/list` reste indépendant de la base.
     *
     * @return ?list<string>
     */
    private function projectNames(): ?array
    {
        try {
            $names = $this->projects->names();
        } catch (\Throwable $e) {
            error_log('mcp: noms de projets indisponibles pour tools/list : ' . $e::class);

            return null;
        }

        return $names === [] ? null : $names;
    }

    /** @return list<array<string,mixed>> */
    public function definitions(): array
    {
        $names = $this->projectNames();
        $projectEnum = $names === null ? [] : ['enum' => $names];
        $projectHint = $names === null ? 'nom d\'un projet configuré' : 'un des projets configurés';
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifiant de la tâche.'];
        $fields = [
            'title' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Titre (255 caractères au plus, non vide).'],
            'due_date' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'Date butée AAAA-MM-JJ, ou null pour l\'effacer.'],
            'recurrence' => [
                'type' => ['object', 'null'],
                'description' => 'Périodicité après la date butée (exige une date butée), ou null pour l\'effacer.',
                'properties' => [
                    'interval' => ['type' => 'integer', 'minimum' => 1],
                    'unit' => ['type' => 'string', 'enum' => TaskValidator::UNITS],
                ],
                'required' => ['interval', 'unit'],
                'additionalProperties' => false,
            ],
            'estimated_minutes' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Estimation de durée en minutes, ou null pour l\'effacer.'],
            'description' => ['type' => ['string', 'null'], 'description' => 'Description ou contexte, ou null pour l\'effacer.'],
            'project' => ['type' => 'string', 'description' => 'Projet (' . $projectHint . ', casse exacte); la catégorie Pro/Perso en découle. Obligatoire à la création; ne peut pas être effacé.'] + $projectEnum,
            'conclusions' => ['type' => ['string', 'null'], 'description' => 'Conclusions (ce qui a été appris ou décidé), ou null pour l\'effacer.'],
            'importance' => ['type' => 'string', 'enum' => TaskValidator::IMPORTANCES, 'description' => 'Importance de la tâche (' . TaskValidator::DEFAULT_IMPORTANCE . ' par défaut à la création); ne peut pas être effacée.'],
        ];
        $status = ['type' => 'string', 'enum' => TaskStatus::codes()];
        $requiredLink = [
            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifiant de la tâche qui a besoin de l\'action.'],
            'required_task_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifiant de la tâche à réaliser avant.'],
        ];

        return [
            self::tool('create_task', 'Crée une tâche. Titre et projet obligatoires (la catégorie Pro ou Perso est celle du projet, elle ne se saisit pas); les autres champs sont facultatifs (absents ou null = non renseignés). Renvoie la tâche créée.', $fields, ['title', 'project']),
            self::tool('get_task', 'Lit une tâche par son identifiant, avec ses actions requises (tâches à réaliser avant).', ['id' => $id], ['id'], ['readOnlyHint' => true]),
            self::tool(
                'list_tasks',
                'Liste les tâches (date butée croissante, sans date en dernier). Sans pagination : filtrer par état (par exemple todo), par catégorie, par projet ou par importance pour limiter la taille de la réponse.',
                [
                    'status' => $status + ['description' => 'Filtre sur l\'état.'],
                    'category' => ['type' => 'string', 'enum' => TaskValidator::CATEGORIES, 'description' => 'Filtre sur la catégorie (celle du projet de la tâche).'],
                    'project' => ['type' => 'string', 'description' => 'Filtre exact sur le projet (nom, casse exacte).'] + $projectEnum,
                    'importance' => ['type' => 'string', 'enum' => TaskValidator::IMPORTANCES, 'description' => 'Filtre exact sur l\'importance.'],
                ],
                [],
                ['readOnlyHint' => true],
            ),
            self::tool(
                'update_task',
                'Modifie des champs d\'une tâche : un champ absent reste inchangé, null efface un champ facultatif. Ne change pas l\'état (utiliser change_task_status) ni les actions requises (utiliser add_required_action et remove_required_action).',
                ['id' => $id] + $fields,
                ['id'],
            ),
            self::tool(
                'delete_task',
                'Supprime définitivement une tâche (destructif, sans retour). Demander l\'accord de l\'utilisateur avant d\'appeler, puis passer confirm: true. Refusé si d\'autres tâches la requièrent.',
                ['id' => $id, 'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Doit valoir true, après accord explicite de l\'utilisateur.']],
                ['id', 'confirm'],
                ['destructiveHint' => true],
            ),
            self::tool('add_required_action', 'Ajoute une action requise : la tâche required_task_id doit être réalisée avant la tâche id. Sans effet si le lien existe déjà. Refuse les cycles et l\'auto-dépendance.', $requiredLink, ['id', 'required_task_id'], ['idempotentHint' => true]),
            self::tool('remove_required_action', 'Retire une action requise. Sans effet si le lien n\'existe pas.', $requiredLink, ['id', 'required_task_id'], ['idempotentHint' => true]),
            self::tool(
                'change_task_status',
                'Change l\'état d\'une tâche (todo, in_progress, done). Terminer une tâche périodique crée l\'occurrence suivante, renvoyée dans next_occurrence. Les actions requises doivent être terminées avant.',
                ['id' => $id, 'status' => $status + ['description' => 'Nouvel état.']],
                ['id', 'status'],
            ),
            self::tool('list_projects', 'Liste les projets (nom, catégorie Pro/Perso, nombre de tâches), triés par nom.', [], [], ['readOnlyHint' => true]),
        ];
    }

    /**
     * @param array<string,mixed> $properties
     * @param list<string> $required
     * @param array<string,bool> $annotations
     * @return array<string,mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required, array $annotations = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;
        $tool = ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
        if ($annotations !== []) {
            $tool['annotations'] = $annotations;
        }

        return $tool;
    }
}
