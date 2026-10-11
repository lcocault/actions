<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Tout le SQL des tâches et la traduction PDOException -> exception de domaine.
 * Requêtes paramétrées, colonnes toujours nommées (jamais SELECT *).
 */
final class TaskRepository
{
    private const TASK_COLUMNS = "id, title, category, to_char(due_date, 'YYYY-MM-DD') AS due_date,"
        . ' recurrence_interval, recurrence_unit, estimated_minutes, description,'
        . " to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created_at,"
        . " to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS updated_at";

    private ?\PDO $pdo = null;

    /** @param \Closure():\PDO $connect */
    public function __construct(private readonly \Closure $connect)
    {
    }

    /**
     * Transaction explicite en READ COMMITTED (exigé par le trigger anti-cycle).
     *
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $pdo = $this->pdo();
        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->unavailableOrSame($e);
        }
    }

    /** Insère une tâche validée (forme du validateur) et renvoie son id. */
    public function insert(array $task): int
    {
        $stmt = $this->run(
            'INSERT INTO tasks (title, category, due_date, recurrence_interval, recurrence_unit, estimated_minutes, description)'
            . ' VALUES (:title, :category, :due_date, :recurrence_interval, :recurrence_unit, :estimated_minutes, :description)'
            . ' RETURNING id',
            [
                'title' => $task['title'],
                'category' => $task['category'],
                'due_date' => $task['due_date'],
                'recurrence_interval' => $task['recurrence']['interval'] ?? null,
                'recurrence_unit' => $task['recurrence']['unit'] ?? null,
                'estimated_minutes' => $task['estimated_minutes'],
                'description' => $task['description'],
            ],
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * Applique les champs modifiés (clés publiques : title, category, due_date, recurrence,
     * estimated_minutes, description) et positionne updated_at.
     *
     * @param array<string,mixed> $changes
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
            if (!in_array($field, ['title', 'category', 'due_date', 'estimated_minutes', 'description'], true)) {
                throw new \LogicException('Champ non modifiable : ' . $field);
            }
            $sets[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
        $this->run('UPDATE tasks SET ' . implode(', ', $sets) . ', updated_at = now() WHERE id = :id', $params);
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
        $stmt = $this->run(
            'SELECT ' . self::TASK_COLUMNS . ' FROM tasks WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id],
        );
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $required = $this->requiredActions([$id]);

        return $this->toTask($row, $required[$id] ?? []);
    }

    /** @return list<array<string,mixed>> triées due_date croissante (sans date en dernier), puis id */
    public function findAll(): array
    {
        $rows = $this->run(
            'SELECT ' . self::TASK_COLUMNS . ' FROM tasks ORDER BY due_date ASC NULLS LAST, id ASC',
        )->fetchAll();
        $required = $this->requiredActions(null);

        return array_map(fn (array $r): array => $this->toTask($r, $required[(int) $r['id']] ?? []), $rows);
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
            'required_actions' => $required,
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    /** @param array<string,mixed> $params */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (\PDOException $e) {
            throw $this->unavailableOrSame($e);
        }
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = ($this->connect)();
            } catch (\PDOException $e) {
                throw new DatabaseUnavailable('Base injoignable.', 0, $e);
            }
        }

        return $this->pdo;
    }

    private function sqlState(\PDOException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    /** Connexion perdue (classe 08) -> DatabaseUnavailable; sinon l'exception d'origine (erreur interne). */
    private function unavailableOrSame(\Throwable $e): \Throwable
    {
        if ($e instanceof \PDOException && str_starts_with($this->sqlState($e), '08')) {
            return new DatabaseUnavailable('Base injoignable.', 0, $e);
        }

        return $e;
    }
}
