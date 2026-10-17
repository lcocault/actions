<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Tout le SQL des tâches et la traduction PDOException -> exception de domaine.
 * Requêtes paramétrées, colonnes toujours nommées (jamais SELECT *).
 */
final class TaskRepository extends PdoRepository
{
    /** La catégorie et le nom du projet viennent de la jointure `projects p` (la catégorie n'est plus stockée sur la tâche). */
    private const TASK_COLUMNS = "t.id, t.title, p.category AS category, to_char(t.due_date, 'YYYY-MM-DD') AS due_date,"
        . ' t.recurrence_interval, t.recurrence_unit, t.estimated_minutes, t.description, p.name AS project, t.conclusions, t.importance, t.status,'
        . " to_char(t.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at,"
        . " to_char(t.completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS completed_at,"
        . " to_char(t.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS updated_at";
    private const FROM = ' FROM tasks t JOIN projects p ON p.id = t.project_id';

    /**
     * Insère une tâche validée (forme du validateur) rattachée au projet `$projectId` (déjà résolu) et renvoie son id.
     *
     * @param array<string,mixed> $task
     * @throws InvalidTask project_invalid si le projet a disparu entre sa résolution et l'écriture
     */
    public function insert(array $task, int $projectId): int
    {
        try {
            $stmt = $this->run(
                'INSERT INTO tasks (title, project_id, due_date, recurrence_interval, recurrence_unit, estimated_minutes, description, conclusions, importance)'
                . ' VALUES (:title, :project_id, :due_date, :recurrence_interval, :recurrence_unit, :estimated_minutes, :description, :conclusions, :importance)'
                . ' RETURNING id',
                [
                    'title' => $task['title'],
                    'project_id' => $projectId,
                    'due_date' => $task['due_date'],
                    'recurrence_interval' => $task['recurrence']['interval'] ?? null,
                    'recurrence_unit' => $task['recurrence']['unit'] ?? null,
                    'estimated_minutes' => $task['estimated_minutes'],
                    'description' => $task['description'],
                    'conclusions' => $task['conclusions'],
                    'importance' => $task['importance'],
                ],
            );
        } catch (\PDOException $e) {
            throw $this->projectGone($e) ?? $e;
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crée l'occurrence suivante d'une périodique en un `INSERT ... SELECT` depuis la ligne source (déjà verrouillée) :
     * titre, projet (donc catégorie), importance, durée, description et périodicité recopiés; conclusions NULL; aucune dépendance;
     * état par défaut. Renvoie l'id de la nouvelle tâche.
     */
    public function copyAsOccurrence(int $sourceId, string $dueDate): int
    {
        $stmt = $this->run(
            'INSERT INTO tasks (title, project_id, due_date, recurrence_interval, recurrence_unit, estimated_minutes, description, conclusions, importance)'
            . ' SELECT title, project_id, CAST(:due_date AS date), recurrence_interval, recurrence_unit, estimated_minutes, description, NULL, importance'
            . ' FROM tasks WHERE id = :id RETURNING id',
            ['due_date' => $dueDate, 'id' => $sourceId],
        );
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new TaskNotFound($sourceId);
        }

        return (int) $id;
    }

    /**
     * Applique les champs modifiés (clés : title, due_date, recurrence, estimated_minutes, description,
     * project_id, conclusions, importance) et positionne updated_at.
     *
     * @param array<string,mixed> $changes
     * @throws InvalidTask project_invalid si le projet a disparu entre sa résolution et l'écriture
     */
    public function update(int $id, array $changes): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($changes as $field => $value) {
            if ($field === 'recurrence') {
                $sets[] = 'recurrence_interval = :recurrence_interval';
                $sets[] = 'recurrence_unit = :recurrence_unit';
                $params['recurrence_interval'] = $value['interval'] ?? null;
                $params['recurrence_unit'] = $value['unit'] ?? null;
                continue;
            }
            // Noms de colonnes issus d'une liste fermée (clés déjà validées), jamais de l'entrée.
            if (!in_array($field, ['title', 'due_date', 'estimated_minutes', 'description', 'project_id', 'conclusions', 'importance'], true)) {
                throw new \LogicException('Champ non modifiable : ' . $field);
            }
            $sets[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        try {
            $this->run('UPDATE tasks SET ' . implode(', ', $sets) . ', updated_at = now() WHERE id = :id', $params);
        } catch (\PDOException $e) {
            throw $this->projectGone($e) ?? $e;
        }
    }

    /** 23503 sur tasks_project_id_fkey : le projet a été supprimé entre sa résolution et l'écriture. */
    private function projectGone(\PDOException $e): ?InvalidTask
    {
        if ($this->sqlState($e) === '23503' && str_contains($e->getMessage(), 'tasks_project_id_fkey')) {
            return new InvalidTask('project_invalid', 'project', 'Projet inconnu.');
        }

        return null;
    }

    /** Positionne updated_at seulement. */
    public function touch(int $id): void
    {
        $this->run('UPDATE tasks SET updated_at = now() WHERE id = :id', ['id' => $id]);
    }

    public function exists(int $id): bool
    {
        return $this->run('SELECT 1 FROM tasks WHERE id = :id', ['id' => $id])->fetchColumn() !== false;
    }

    /** @return ?array<string,mixed> forme `Task` */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        // FOR UPDATE OF t : jamais FOR UPDATE seul, qui verrouillerait aussi la ligne projet.
        $stmt = $this->run(
            'SELECT ' . self::TASK_COLUMNS . self::FROM . ' WHERE t.id = :id' . ($forUpdate ? ' FOR UPDATE OF t' : ''),
            ['id' => $id],
        );
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $required = $this->requiredActions([$id]);

        return $this->toTask($row, $required[$id] ?? []);
    }

    /**
     * Triées due_date croissante (sans date en dernier), puis id. Clauses fixes, valeurs liées.
     *
     * @param array{status?:TaskStatus,category?:string,project_id?:int,importance?:string} $filters déjà validés (projet résolu en identifiant)
     * @return list<array<string,mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (isset($filters['status'])) {
            $where[] = 't.status = :status';
            $params['status'] = $filters['status']->value;
        }
        if (isset($filters['category'])) {
            $where[] = 'p.category = :category';
            $params['category'] = $filters['category'];
        }
        if (isset($filters['project_id'])) {
            $where[] = 't.project_id = :project_id';
            $params['project_id'] = $filters['project_id'];
        }
        if (isset($filters['importance'])) {
            $where[] = 't.importance = :importance';
            $params['importance'] = $filters['importance'];
        }
        $rows = $this->run(
            'SELECT ' . self::TASK_COLUMNS . self::FROM . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY t.due_date ASC NULLS LAST, t.id ASC',
            $params,
        )->fetchAll();
        $required = $this->requiredActions(null);

        return array_map(fn (array $r): array => $this->toTask($r, $required[(int) $r['id']] ?? []), $rows);
    }

    /** Positionne l'état, la clôture (now() si done, sinon NULL) et updated_at. */
    public function setStatus(int $id, TaskStatus $status): void
    {
        $completed = $status === TaskStatus::Done ? 'now()' : 'NULL'; // constantes, jamais de l'entrée
        $this->run(
            'UPDATE tasks SET status = :status, completed_at = ' . $completed . ', updated_at = now() WHERE id = :id',
            ['status' => $status->value, 'id' => $id],
        );
    }

    /**
     * Actions requises de la tâche avec leur état, triées par id; verrouille ces lignes (FOR SHARE) pour qu'aucune
     * ne soit rouverte avant la fin de la transaction.
     *
     * @return list<array{id:int,title:string,status:string}>
     */
    public function lockRequiredActionsWithStatus(int $id): array
    {
        $rows = $this->run(
            'SELECT t.id, t.title, t.status FROM task_dependencies d JOIN tasks t ON t.id = d.required_task_id'
            . ' WHERE d.task_id = :id ORDER BY t.id FOR SHARE OF t',
            ['id' => $id],
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'],
        ], $rows);
    }

    /**
     * Ajoute un lien (idempotent). Renvoie true si le lien a été créé.
     *
     * @throws CyclicDependency|InvalidDependency|RequiredTaskNotFound|TaskNotFound
     */
    public function addLink(int $taskId, int $requiredTaskId): bool
    {
        try {
            $stmt = $this->run(
                'INSERT INTO task_dependencies (task_id, required_task_id) VALUES (:task_id, :required_task_id)'
                . ' ON CONFLICT (task_id, required_task_id) DO NOTHING',
                ['task_id' => $taskId, 'required_task_id' => $requiredTaskId],
            );

            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $state = $this->sqlState($e);
            $message = $e->getMessage();
            if ($state === '23514' && str_contains($message, 'task_dependencies_no_cycle')) {
                throw new CyclicDependency($taskId, $requiredTaskId);
            }
            if ($state === '23514' && str_contains($message, 'task_dependencies_no_self')) {
                throw new InvalidDependency($taskId);
            }
            if ($state === '23503' && str_contains($message, 'task_dependencies_required_task_id_fkey')) {
                throw new RequiredTaskNotFound($requiredTaskId);
            }
            if ($state === '23503' && str_contains($message, 'task_dependencies_task_id_fkey')) {
                throw new TaskNotFound($taskId);
            }
            throw $e;
        }
    }

    /** Retire un lien. Renvoie true si un lien existait. */
    public function removeLink(int $taskId, int $requiredTaskId): bool
    {
        return $this->run(
            'DELETE FROM task_dependencies WHERE task_id = :task_id AND required_task_id = :required_task_id',
            ['task_id' => $taskId, 'required_task_id' => $requiredTaskId],
        )->rowCount() > 0;
    }

    /**
     * DELETE direct (instruction unique, donc atomique). Le refus est arbitré par la base (23503).
     *
     * @throws TaskNotFound|TaskIsRequired
     */
    public function delete(int $id): void
    {
        try {
            $count = $this->run('DELETE FROM tasks WHERE id = :id', ['id' => $id])->rowCount();
        } catch (\PDOException $e) {
            if ($this->sqlState($e) === '23503' && str_contains($e->getMessage(), 'task_dependencies_required_task_id_fkey')) {
                $dependents = $this->run(
                    'SELECT t.id, t.title FROM task_dependencies d JOIN tasks t ON t.id = d.task_id'
                    . ' WHERE d.required_task_id = :id ORDER BY t.id',
                    ['id' => $id],
                )->fetchAll();
                throw new TaskIsRequired($id, array_map(
                    static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
                    $dependents,
                ));
            }
            throw $e;
        }
        if ($count === 0) {
            throw new TaskNotFound($id);
        }
    }

    /**
     * @param ?list<int> $ids null = toutes les tâches
     * @return array<int,list<array{id:int,title:string}>>
     */
    private function requiredActions(?array $ids): array
    {
        $sql = 'SELECT d.task_id, t.id, t.title FROM task_dependencies d JOIN tasks t ON t.id = d.required_task_id';
        $params = [];
        if ($ids !== null) {
            $sql .= ' WHERE d.task_id = :task_id';
            $params['task_id'] = $ids[0];
        }
        $out = [];
        foreach ($this->run($sql . ' ORDER BY d.task_id, t.id', $params)->fetchAll() as $r) {
            $out[(int) $r['task_id']][] = ['id' => (int) $r['id'], 'title' => (string) $r['title']];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @param list<array{id:int,title:string}> $required
     * @return array<string,mixed>
     */
    private function toTask(array $r, array $required): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'category' => (string) $r['category'],
            'due_date' => $r['due_date'] === null ? null : (string) $r['due_date'],
            'recurrence' => $r['recurrence_interval'] === null
                ? null
                : ['interval' => (int) $r['recurrence_interval'], 'unit' => (string) $r['recurrence_unit']],
            'estimated_minutes' => $r['estimated_minutes'] === null ? null : (int) $r['estimated_minutes'],
            'description' => $r['description'] === null ? null : (string) $r['description'],
            'project' => (string) $r['project'],
            'conclusions' => $r['conclusions'] === null ? null : (string) $r['conclusions'],
            'importance' => (string) $r['importance'],
            'required_actions' => $required,
            'status' => (string) $r['status'],
            'completed_at' => $r['completed_at'] === null ? null : (string) $r['completed_at'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
}
