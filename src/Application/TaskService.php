<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Cas d'usage des tâches, seul point d'entrée des adaptateurs REST, MCP et portail.
 * Les règles métier sont ici (validation) et en base (cycles, contraintes); les adaptateurs ne les répètent pas.
 *
 * Forme d'une tâche (`Task`) : id, title, category, due_date, recurrence{interval,unit}|null,
 * estimated_minutes, description, project|null, conclusions|null, required_actions[{id,title}], status (todo|in_progress|done),
 * completed_at (ISO 8601 UTC|null), created_at, updated_at.
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

    /**
     * Filtres facultatifs `status`, `category` et `project` (une valeur chacun, combinés en ET).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     * @throws InvalidTask
     */
    public function list(array $filters = []): array
    {
        return $this->repository->findAll($this->validator->validateFilters($filters));
    }

    /**
     * Aiguillage de PATCH : `status` seul -> changeStatus; `status` avec une autre clé -> status_not_alone;
     * sans `status` -> update. Sortie toujours {task, next_occurrence} (null hors terminaison d'une périodique).
     *
     * @param array<string,mixed> $changes
     * @return array{task:array<string,mixed>,next_occurrence:?array<string,mixed>}
     * @throws TaskNotFound|InvalidTask|InvalidStatusTransition|RequiredActionsNotDone|NextDueDateOutOfRange
     */
    public function patch(int $id, array $changes): array
    {
        if (!array_key_exists('status', $changes)) {
            return ['task' => $this->update($id, $changes), 'next_occurrence' => null];
        }
        if (count($changes) > 1) {
            if (!$this->repository->exists($id)) { // 404 avant 422, comme update()
                throw new TaskNotFound($id);
            }
            throw new InvalidTask('status_not_alone', 'status', 'L\'état se change seul, sans autre champ.');
        }

        return $this->changeStatus($id, $changes['status']);
    }

    /**
     * Seul cas d'usage de changement d'état (la terminaison est la transition vers `done`).
     * Aucune écriture avant que tous les contrôles soient passés : tout refus laisse la base inchangée.
     *
     * @return array{task:array<string,mixed>,next_occurrence:?array<string,mixed>}
     * @throws TaskNotFound|InvalidTask|InvalidStatusTransition|RequiredActionsNotDone|NextDueDateOutOfRange
     */
    public function changeStatus(int $id, mixed $status): array
    {
        return $this->repository->transaction(function () use ($id, $status): array {
            $current = $this->repository->find($id, true) ?? throw new TaskNotFound($id);
            $target = $this->validator->validateStatus($status);
            $from = TaskStatus::from($current['status']);
            if (!$from->canTransitionTo($target)) {
                throw new InvalidStatusTransition($id, $from, $target);
            }
            $nextDueDate = null;
            if ($target === TaskStatus::Done) {
                $pending = array_values(array_filter(
                    $this->repository->lockRequiredActionsWithStatus($id),
                    static fn (array $r): bool => $r['status'] !== TaskStatus::Done->value,
                ));
                if ($pending !== []) {
                    throw new RequiredActionsNotDone($id, $pending);
                }
                if ($current['recurrence'] !== null) {
                    try {
                        $nextDueDate = NextDueDate::compute(
                            (string) $current['due_date'],
                            $current['recurrence']['interval'],
                            $current['recurrence']['unit'],
                        );
                    } catch (\RangeException) {
                        throw new NextDueDateOutOfRange($id, (string) $current['due_date']);
                    }
                }
            }
            $this->repository->setStatus($id, $target);
            $occurrence = null;
            if ($nextDueDate !== null) {
                // Valeurs d'une ligne déjà valide : pas de revalidation. Aucun lien copié, état `todo` par défaut.
                $occurrence = $this->mustFind($this->repository->insert([
                    'title' => $current['title'],
                    'category' => $current['category'],
                    'due_date' => $nextDueDate,
                    'recurrence' => $current['recurrence'],
                    'estimated_minutes' => $current['estimated_minutes'],
                    'description' => $current['description'],
                    'project' => $current['project'], // recopié (F7)
                    'conclusions' => null,            // propres à chaque occurrence
                ]));
            }

            return ['task' => $this->mustFind($id), 'next_occurrence' => $occurrence];
        });
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
