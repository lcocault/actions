<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Cas d'usage des projets, seul point d'entrée des adaptateurs REST, MCP et portail.
 *
 * Forme d'un projet (`Project`) : id, name, category (Pro|Perso), task_count (toutes les tâches, tous états).
 */
final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly ProjectValidator $validator = new ProjectValidator(),
    ) {
    }

    /** @return list<array<string,mixed>> Project, triés par lower(name), puis id */
    public function list(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @return array<string,mixed> Project
     * @throws ProjectNotFound
     */
    public function get(int $id): array
    {
        return $this->repository->find($id) ?? throw new ProjectNotFound($id);
    }

    /** @return list<string> noms configurés (énumérations MCP, listes du portail) */
    public function names(): array
    {
        return $this->repository->names();
    }

    /**
     * @param array<string,mixed> $data name et category obligatoires
     * @return array<string,mixed> Project
     * @throws InvalidProject|ProjectNameTaken
     */
    public function create(array $data): array
    {
        $project = $this->validator->validateCreate($data);

        return $this->get($this->repository->insert($project['name'], $project['category']));
    }

    /**
     * `name` et `category` facultatifs (`null` interdit, clé absente = inchangé). `tasks_recategorized` : nombre de
     * tâches du projet (tous états) si la catégorie change effectivement, sinon 0. Aucune écriture si rien ne change;
     * le changement de catégorie n'écrit aucune ligne `tasks` (updated_at des tâches inchangé).
     *
     * @param array<string,mixed> $changes
     * @return array{project:array<string,mixed>,tasks_recategorized:int}
     * @throws ProjectNotFound|InvalidProject|ProjectNameTaken
     */
    public function update(int $id, array $changes): array
    {
        return $this->repository->transaction(function () use ($id, $changes): array {
            $current = $this->repository->lock($id) ?? throw new ProjectNotFound($id);
            $merged = $this->validator->validateUpdate($current, $changes);
            if ($merged['name'] === $current['name'] && $merged['category'] === $current['category']) {
                return ['project' => $this->get($id), 'tasks_recategorized' => 0];
            }
            $this->repository->update($id, $merged['name'], $merged['category']);
            $touched = $merged['category'] !== $current['category'] ? $this->repository->countTasks($id) : 0;

            return ['project' => $this->get($id), 'tasks_recategorized' => $touched];
        });
    }

    /** @throws ProjectNotFound|ProjectInUse */
    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }
}
