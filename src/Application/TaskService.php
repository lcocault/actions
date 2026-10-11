<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Cas d'usage des tâches, seul point d'entrée des adaptateurs REST, MCP et portail.
 * Les règles métier sont ici (validation) et en base (cycles, contraintes); les adaptateurs ne les répètent pas.
 *
 * Forme d'une tâche (`Task`) : id, title, category, due_date, recurrence{interval,unit}|null,
 * estimated_minutes, description, required_actions[{id,title}], created_at, updated_at.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepository $repository,
        private readonly TaskValidator $validator = new TaskValidator(),
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> Task
     * @throws InvalidTask
     */
    public function create(array $data): array
    {
        $task = $this->validator->validateCreate($data);

        return $this->repository->transaction(function () use ($task): array {
            $id = $this->repository->insert($task);

            return $this->mustFind($id);
        });
    }

    /**
     * @return array<string,mixed> Task
     * @throws TaskNotFound
     */
    public function get(int $id): array
    {
        return $this->mustFind($id);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->repository->findAll();
    }

    /**
     * Clés présentes = modifiées; null efface un champ facultatif; clé absente = inchangé.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed> Task
     * @throws TaskNotFound|InvalidTask
     */
    public function update(int $id, array $changes): array
    {
        return $this->repository->transaction(function () use ($id, $changes): array {
            $current = $this->repository->find($id, true) ?? throw new TaskNotFound($id);
            $merged = $this->validator->validateUpdate($current, $changes);
            $diff = [];
            foreach ($merged as $field => $value) {
                if ($value !== $current[$field]) {
                    $diff[$field] = $value;
                }
            }
            if ($diff === []) {
                return $current; // aucun changement : pas d'écriture, updated_at inchangé
            }
            $this->repository->update($id, $diff);

            return $this->mustFind($id);
        });
    }

    /** @throws TaskNotFound|TaskIsRequired */
    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Idempotent : un lien existant renvoie un succès sans doublon ni changement.
     *
     * @return array<string,mixed> Task de $taskId
     * @throws TaskNotFound|RequiredTaskNotFound|InvalidDependency|CyclicDependency
     */
    public function addRequiredAction(int $taskId, int $requiredTaskId): array
    {
        return $this->repository->transaction(function () use ($taskId, $requiredTaskId): array {
            if (!$this->repository->exists($taskId)) {
                throw new TaskNotFound($taskId);
            }
            if (!$this->repository->exists($requiredTaskId)) {
                throw new RequiredTaskNotFound($requiredTaskId);
            }
            // L'auto-dépendance (tâche connue) est refusée par le CHECK, puis traduite par le dépôt.
            if ($this->repository->addLink($taskId, $requiredTaskId)) {
                $this->repository->touch($taskId);
            }

            return $this->mustFind($taskId);
        });
    }

    /**
     * Idempotent : un lien absent est un succès.
     *
     * @return array<string,mixed> Task de $taskId
     * @throws TaskNotFound
     */
    public function removeRequiredAction(int $taskId, int $requiredTaskId): array
    {
        return $this->repository->transaction(function () use ($taskId, $requiredTaskId): array {
            if (!$this->repository->exists($taskId)) {
                throw new TaskNotFound($taskId);
            }
            if ($this->repository->removeLink($taskId, $requiredTaskId)) {
                $this->repository->touch($taskId);
            }

            return $this->mustFind($taskId);
        });
    }

    /** @return array<string,mixed> */
    private function mustFind(int $id): array
    {
        return $this->repository->find($id) ?? throw new TaskNotFound($id);
    }
}
