<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Tout le SQL des projets et la traduction PDOException -> exception de domaine.
 * Requêtes paramétrées, colonnes toujours nommées.
 */
final class ProjectRepository extends PdoRepository
{
    private const LIST_SQL = 'SELECT p.id, p.name, p.category,'
        . ' (SELECT count(*) FROM tasks t WHERE t.project_id = p.id) AS task_count FROM projects p';

    /** @return list<array{id:int,name:string,category:string,task_count:int}> triés par lower(name), puis id */
    public function findAll(): array
    {
        return array_map($this->toProject(...), $this->run(self::LIST_SQL . ' ORDER BY lower(p.name), p.id')->fetchAll());
    }

    /** @return ?array{id:int,name:string,category:string,task_count:int} */
    public function find(int $id): ?array
    {
        $row = $this->run(self::LIST_SQL . ' WHERE p.id = :id', ['id' => $id])->fetch();

        return $row === false ? null : $this->toProject($row);
    }

    /**
     * Ligne projet seule, verrouillée (FOR UPDATE) : l'état à fusionner dans une modification.
     *
     * @return ?array{id:int,name:string,category:string}
     */
    public function lock(int $id): ?array
    {
        $row = $this->run('SELECT id, name, category FROM projects WHERE id = :id FOR UPDATE', ['id' => $id])->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'category' => (string) $row['category']];
    }

    /** Identifiant du projet de ce nom exact (casse comprise), ou null. */
    public function idByName(string $name): ?int
    {
        $id = $this->run('SELECT id FROM projects WHERE name = :name', ['name' => $name])->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return list<string> noms, triés par lower(name), puis id */
    public function names(): array
    {
        return array_map('strval', $this->run('SELECT name FROM projects ORDER BY lower(name), id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function countTasks(int $id): int
    {
        return (int) $this->run('SELECT count(*) FROM tasks WHERE project_id = :id', ['id' => $id])->fetchColumn();
    }

    /** @throws ProjectNameTaken */
    public function insert(string $name, string $category): int
    {
        try {
            return (int) $this->run(
                'INSERT INTO projects (name, category) VALUES (:name, :category) RETURNING id',
                ['name' => $name, 'category' => $category],
            )->fetchColumn();
        } catch (\PDOException $e) {
            throw $this->nameTaken($e) ?? $e;
        }
    }

    /** @throws ProjectNameTaken */
    public function update(int $id, string $name, string $category): void
    {
        try {
            $this->run('UPDATE projects SET name = :name, category = :category WHERE id = :id', ['name' => $name, 'category' => $category, 'id' => $id]);
        } catch (\PDOException $e) {
            throw $this->nameTaken($e) ?? $e;
        }
    }

    /**
     * DELETE direct (instruction unique, donc atomique). Le refus est arbitré par la base (23503).
     *
     * @throws ProjectNotFound|ProjectInUse
     */
    public function delete(int $id): void
    {
        try {
            $count = $this->run('DELETE FROM projects WHERE id = :id', ['id' => $id])->rowCount();
        } catch (\PDOException $e) {
            if ($this->sqlState($e) === '23503' && str_contains($e->getMessage(), 'tasks_project_id_fkey')) {
                throw new ProjectInUse($id, $this->countTasks($id));
            }
            throw $e;
        }
        if ($count === 0) {
            throw new ProjectNotFound($id);
        }
    }

    /** 23505 sur l'index d'unicité du nom (insensible à la casse). */
    private function nameTaken(\PDOException $e): ?ProjectNameTaken
    {
        if ($this->sqlState($e) === '23505' && str_contains($e->getMessage(), 'projects_name_lower_key')) {
            return new ProjectNameTaken();
        }

        return null;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,name:string,category:string,task_count:int}
     */
    private function toProject(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'category' => (string) $r['category'], 'task_count' => (int) $r['task_count']];
    }
}
