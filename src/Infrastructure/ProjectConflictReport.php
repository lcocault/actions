<?php

declare(strict_types=1);

namespace Actions\Infrastructure;

/**
 * Temps 1 de la migration 0004 : rapport en lecture seule (transaction READ ONLY) des tâches dont la catégorie
 * diffère de celle du projet de destination. Même règle que la garde de 0004_projects.sql (un test vérifie
 * que les deux ensembles sont identiques). À lancer sur le schéma d'avant 0004.
 */
final class ProjectConflictReport
{
    /** Catégorie des quatre projets existants avant 0004 (celle que leur donne la migration). */
    private const DESTINATION = "(VALUES ('MS-HR', 'Pro'), ('Jardin/Maison', 'Perso'), ('OPF', 'Perso'), ('Erendil', 'Perso')) AS p (name, category)";

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return array{conflicts:list<array{id:int,title:string,status:string,category:string,project:string,expected:string}>,without_project:array{Pro:int,Perso:int}}
     * @throws \RuntimeException 0004 déjà appliquée, ou 0003 absente
     */
    public function build(): array
    {
        $this->pdo->exec('BEGIN READ ONLY');
        try {
            if ($this->pdo->query("SELECT to_regclass('public.projects')")->fetchColumn() !== null) {
                throw new \RuntimeException('La table projects existe déjà : la migration 0004 est appliquée, rien à rapporter.');
            }
            $columns = $this->pdo->query(
                "SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'tasks'"
                . " AND column_name IN ('category', 'project')"
            )->fetchColumn();
            if ((int) $columns !== 2) {
                throw new \RuntimeException('Schéma inattendu : les colonnes tasks.category et tasks.project (migration 0003) sont requises.');
            }
            $conflicts = [];
            foreach ($this->pdo->query(
                'SELECT t.id, t.title, t.status, t.category, t.project, p.category AS expected'
                . ' FROM tasks t JOIN ' . self::DESTINATION . ' ON p.name = t.project'
                . ' WHERE t.category <> p.category ORDER BY t.id'
            )->fetchAll() as $r) {
                $conflicts[] = [
                    'id' => (int) $r['id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'],
                    'category' => (string) $r['category'], 'project' => (string) $r['project'], 'expected' => (string) $r['expected'],
                ];
            }
            $without = ['Pro' => 0, 'Perso' => 0];
            foreach ($this->pdo->query('SELECT category, count(*) AS n FROM tasks WHERE project IS NULL GROUP BY category')->fetchAll() as $r) {
                $without[(string) $r['category']] = (int) $r['n'];
            }

            return ['conflicts' => $conflicts, 'without_project' => $without];
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
    }
}
