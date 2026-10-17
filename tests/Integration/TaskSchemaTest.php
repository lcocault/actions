<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Infrastructure\Database;
use Actions\Infrastructure\Migrator;

/** Schéma des tâches (migrations 0001 à 0005) sur base vide (actions_test), une règle par test : SQLSTATE + nom de contrainte. */
final class TaskSchemaTest extends DbTestCase
{
    private const ALL = ['0001_tasks_and_dependencies', '0002_task_status', '0003_task_project_conclusions', '0004_projects', '0005_task_importance'];

    /** @var list<\PDO> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropSchema();
        self::assertSame(self::ALL, (new Migrator($this->pdo, self::MIGRATIONS))->migrate());
    }

    protected function tearDown(): void
    {
        foreach ($this->extra as $c) {
            if ($c->inTransaction()) {
                $c->rollBack();
            }
        }
        $this->extra = [];
    }

    private function task(string $title = 't', array $o = []): int
    {
        $o += ['project' => 'Divers Pro', 'due_date' => null, 'recurrence_interval' => null, 'recurrence_unit' => null,
            'estimated_minutes' => null, 'description' => null];
        $o['project_id'] = $this->projectId($o['project']);
        unset($o['project']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, project_id, due_date, recurrence_interval, recurrence_unit, estimated_minutes, description)'
            . ' VALUES (:t, :project_id, :due_date, :recurrence_interval, :recurrence_unit, :estimated_minutes, :description) RETURNING id'
        );
        $stmt->execute(['t' => $title] + $o);

        return (int) $stmt->fetchColumn();
    }

    private function link(int $task, int $required, ?\PDO $pdo = null): void
    {
        ($pdo ?? $this->pdo)->prepare('INSERT INTO task_dependencies (task_id, required_task_id) VALUES (?, ?)')->execute([$task, $required]);
    }

    private function links(): array
    {
        return array_map(
            fn (array $r) => $r['task_id'] . '>' . $r['required_task_id'],
            $this->pdo->query('SELECT task_id, required_task_id FROM task_dependencies ORDER BY 1, 2')->fetchAll()
        );
    }

    /** Exécute $fn et vérifie SQLSTATE et nom de contrainte (cité dans le message PostgreSQL). */
    private function assertViolation(string $sqlstate, string $constraint, callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            self::assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            self::assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        self::fail("Violation $sqlstate / $constraint attendue.");
    }

    private function second(): \PDO
    {
        return $this->extra[] = Database::connect($this->config);
    }

    // --- tasks ---

    public function testMinimalTaskIsValidAndOptionalFieldsAreNull(): void
    {
        $id = $this->task('Titre', ['project' => 'Divers Perso']);
        $row = $this->pdo->query("SELECT t.*, p.category FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = $id")->fetch();
        self::assertSame('Perso', $row['category'], 'catégorie lue par le projet');
        self::assertArrayNotHasKey('project', $row, 'colonne tasks.project supprimée par 0004');
        foreach (['due_date', 'recurrence_interval', 'recurrence_unit', 'estimated_minutes', 'description', 'conclusions'] as $c) {
            self::assertNull($row[$c], $c);
        }
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['updated_at']);
    }

    public function testFullTaskIsValid(): void
    {
        $id = $this->task(str_repeat('é', 255), ['due_date' => '2026-12-31', 'recurrence_interval' => 2,
            'recurrence_unit' => 'week', 'estimated_minutes' => 90, 'description' => 'ctx']);
        self::assertSame('2026-12-31', $this->pdo->query("SELECT due_date FROM tasks WHERE id = $id")->fetchColumn());
    }

    public function testRequiredColumnsAreNotNull(): void
    {
        $pid = $this->projectId('OPF');
        $this->assertViolation('23502', 'title', fn () => $this->pdo->exec("INSERT INTO tasks (project_id) VALUES ($pid)"));
        $this->assertViolation('23502', 'project_id', fn () => $this->pdo->exec("INSERT INTO tasks (title) VALUES ('x')"));
    }

    public function testBlankOrTooLongTitleIsRejected(): void
    {
        foreach (['', '   ', str_repeat('a', 256)] as $title) {
            $this->assertViolation('23514', 'tasks_title_not_blank', fn () => $this->task($title));
        }
    }

    public function testTaskHasNoCategoryColumnAnymore(): void
    {
        $cols = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'tasks'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotContains('category', $cols);
        self::assertNotContains('project', $cols);
        self::assertContains('project_id', $cols);
        $this->assertViolation('42703', 'category', fn () => $this->pdo->exec("INSERT INTO tasks (title, category, project_id) VALUES ('x', 'Pro', 1)"));
    }

    public function testEstimatedMinutesMustBePositive(): void
    {
        foreach ([0, -5] as $m) {
            $this->assertViolation('23514', 'tasks_estimated_minutes_positive', fn () => $this->task('x', ['estimated_minutes' => $m]));
        }
        self::assertGreaterThan(0, $this->task('x', ['estimated_minutes' => 1]));
    }

    public function testRecurrenceIntervalAndUnitMustBeValid(): void
    {
        $due = '2026-01-01';
        foreach ([0, -1] as $i) {
            $this->assertViolation('23514', 'tasks_recurrence_valid', fn () => $this->task('x', ['due_date' => $due, 'recurrence_interval' => $i, 'recurrence_unit' => 'day']));
        }
        foreach (['jour', 'days', 'DAY', ''] as $u) {
            $this->assertViolation('23514', 'tasks_recurrence_valid', fn () => $this->task('x', ['due_date' => $due, 'recurrence_interval' => 1, 'recurrence_unit' => $u]));
        }
        foreach (['day', 'week', 'month', 'year'] as $u) {
            self::assertGreaterThan(0, $this->task('x', ['due_date' => $due, 'recurrence_interval' => 1, 'recurrence_unit' => $u]));
        }
    }

    public function testRecurrenceMustBeAPair(): void
    {
        $this->assertViolation('23514', 'tasks_recurrence_valid', fn () => $this->task('x', ['due_date' => '2026-01-01', 'recurrence_interval' => 1]));
        $this->assertViolation('23514', 'tasks_recurrence_valid', fn () => $this->task('x', ['due_date' => '2026-01-01', 'recurrence_unit' => 'day']));
    }

    public function testRecurrenceRequiresDueDateButDueDateAloneIsFine(): void
    {
        $this->assertViolation('23514', 'tasks_recurrence_needs_due_date', fn () => $this->task('x', ['recurrence_interval' => 1, 'recurrence_unit' => 'day']));
        self::assertGreaterThan(0, $this->task('x', ['due_date' => '2026-01-01']));
        $id = $this->task('y', ['due_date' => '2026-01-01', 'recurrence_interval' => 1, 'recurrence_unit' => 'day']);
        // Retirer la date butée d'une tâche périodique est refusé aussi.
        $this->assertViolation('23514', 'tasks_recurrence_needs_due_date', fn () => $this->pdo->exec("UPDATE tasks SET due_date = NULL WHERE id = $id"));
    }

    // --- task_dependencies ---

    public function testSelfDependencyIsRejectedByCheck(): void
    {
        $a = $this->task();
        $this->assertViolation('23514', 'task_dependencies_no_self', fn () => $this->link($a, $a));
    }

    public function testDuplicateLinkIsRejected(): void
    {
        [$a, $b] = [$this->task(), $this->task()];
        $this->link($a, $b);
        $this->assertViolation('23505', 'task_dependencies_pkey', fn () => $this->link($a, $b));
    }

    public function testUnknownTasksAreRejected(): void
    {
        $a = $this->task();
        $this->assertViolation('23503', 'task_dependencies_required_task_id_fkey', fn () => $this->link($a, 999999));
        $this->assertViolation('23503', 'task_dependencies_task_id_fkey', fn () => $this->link(999999, $a));
    }

    public function testDirectCycleIsRejected(): void
    {
        [$a, $b] = [$this->task(), $this->task()];
        $this->link($a, $b);
        $this->assertViolation('23514', 'task_dependencies_no_cycle', fn () => $this->link($b, $a));
        self::assertSame(["$a>$b"], $this->links());
    }

    public function testIndirectCycleOfThreeTasksIsRejected(): void
    {
        [$a, $b, $c] = [$this->task(), $this->task(), $this->task()];
        $this->link($a, $b);
        $this->link($b, $c);
        $this->assertViolation('23514', 'task_dependencies_no_cycle', fn () => $this->link($c, $a));
        self::assertCount(2, $this->links());
    }

    public function testCycleConstraintNameIsExposedInErrorFields(): void
    {
        [$a, $b] = [$this->task(), $this->task()];
        $this->link($a, $b);
        $this->pdo->exec("DO \$\$ DECLARE c text; BEGIN
            BEGIN INSERT INTO task_dependencies VALUES ($b, $a);
            EXCEPTION WHEN check_violation THEN GET STACKED DIAGNOSTICS c = CONSTRAINT_NAME;
              CREATE TEMP TABLE got (c text); INSERT INTO got VALUES (c); END; END \$\$");
        self::assertSame('task_dependencies_no_cycle', $this->pdo->query('SELECT c FROM got')->fetchColumn());
    }

    public function testMultiRowInsertFormingACycleIsRejectedAndNothingIsInserted(): void
    {
        [$a, $b] = [$this->task(), $this->task()];
        $this->assertViolation('23514', 'task_dependencies_no_cycle', fn () => $this->pdo->exec("INSERT INTO task_dependencies VALUES ($a, $b), ($b, $a)"));
        self::assertSame([], $this->links());
    }

    public function testMultiRowInsertOfThreeTasksFormingACycleIsRejected(): void
    {
        [$a, $b, $c] = [$this->task(), $this->task(), $this->task()];
        $this->assertViolation('23514', 'task_dependencies_no_cycle', fn () => $this->pdo->exec("INSERT INTO task_dependencies VALUES ($a, $b), ($b, $c), ($c, $a)"));
        self::assertSame([], $this->links());
    }

    public function testUpdateOfALinkCreatingACycleIsRejected(): void
    {
        [$a, $b, $c, $d] = [$this->task(), $this->task(), $this->task(), $this->task()];
        $this->link($a, $b);
        $this->link($b, $c);
        $this->link($c, $d);
        $this->assertViolation('23514', 'task_dependencies_no_cycle', fn () => $this->pdo->exec("UPDATE task_dependencies SET required_task_id = $a WHERE task_id = $c"));
        self::assertSame(["$a>$b", "$b>$c", "$c>$d"], $this->links());
    }

    public function testUpdateIgnoresTheOldVersionOfTheUpdatedLink(): void
    {
        // r>p, p>q puis p>q devient q>r : q>r>p, aucun cycle (l'ancien lien p>q n'existe plus).
        [$r, $p, $q] = [$this->task(), $this->task(), $this->task()];
        $this->link($r, $p);
        $this->link($p, $q);
        $this->pdo->exec("UPDATE task_dependencies SET task_id = $q, required_task_id = $r WHERE task_id = $p AND required_task_id = $q");
        self::assertSame(["$r>$p", "$q>$r"], $this->links());
    }

    public function testDiamondWithoutCycleIsAccepted(): void
    {
        [$a, $b, $c, $d] = [$this->task(), $this->task(), $this->task(), $this->task()];
        foreach ([[$a, $b], [$a, $c], [$b, $d], [$c, $d]] as [$x, $y]) {
            $this->link($x, $y);
        }
        self::assertCount(4, $this->links());
    }

    // --- suppression ---

    public function testDeletingARequiredTaskIsRefusedAndNothingChanges(): void
    {
        [$a, $b, $c] = [$this->task(), $this->task(), $this->task()];
        $this->link($a, $b);
        $this->link($c, $b);
        $this->assertViolation('23503', 'task_dependencies_required_task_id_fkey', fn () => $this->pdo->exec("DELETE FROM tasks WHERE id = $b"));
        self::assertSame(3, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
        self::assertSame(["$a>$b", "$c>$b"], $this->links());
    }

    public function testDeletingADependentTaskRemovesItsOwnLinksOnly(): void
    {
        [$a, $b, $c] = [$this->task(), $this->task(), $this->task()];
        $this->link($a, $b);
        $this->link($a, $c);
        $this->link($b, $c);
        $this->pdo->exec("DELETE FROM tasks WHERE id = $a");
        self::assertSame(["$b>$c"], $this->links());
        self::assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
    }

    // --- concurrence ---

    public function testConcurrentOppositeLinksAreSerialisedThenRejected(): void
    {
        [$a, $b] = [$this->task(), $this->task()];
        $c1 = $this->second();
        $c2 = $this->second();
        $c1->beginTransaction();
        $this->link($a, $b, $c1);   // non validé : tient le verrou consultatif

        $c2->exec("SET lock_timeout = '500ms'");
        $this->assertViolationOn('55P03', fn () => $this->link($b, $a, $c2));

        $c1->commit();
        try {
            $this->link($b, $a, $c2);
            self::fail('Cycle attendu après COMMIT.');
        } catch (\PDOException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
            self::assertStringContainsString('task_dependencies_no_cycle', $e->getMessage());
        }
        self::assertSame(["$a>$b"], $this->links());
    }

    private function assertViolationOn(string $sqlstate, callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            self::assertSame($sqlstate, $e->errorInfo[0], $e->getMessage());

            return;
        }
        self::fail("SQLSTATE $sqlstate attendu.");
    }

    // --- rejouabilité ---

    public function testMigrationIsReplayableWithoutEffect(): void
    {
        $id = $this->task('garde');
        self::assertSame([], (new Migrator($this->pdo, self::MIGRATIONS))->migrate());
        self::assertSame(['applied' => self::ALL, 'pending' => []], (new Migrator($this->pdo, self::MIGRATIONS))->status());
        self::assertSame('garde', $this->pdo->query("SELECT title FROM tasks WHERE id = $id")->fetchColumn());
    }

    // --- migration 0002 : état et clôture ---

    private function row(int $id): array
    {
        return $this->pdo->query("SELECT status, completed_at FROM tasks WHERE id = $id")->fetch();
    }

    public function testNewTaskDefaultsToTodoWithoutCompletion(): void
    {
        $row = $this->row($this->task());
        self::assertSame('todo', $row['status']);
        self::assertNull($row['completed_at']);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $id = $this->task();
        foreach (['Done', 'terminee', '', 'doing'] as $status) {
            $this->assertViolation('23514', 'tasks_status_valid', fn () => $this->pdo->exec("UPDATE tasks SET status = '$status' WHERE id = $id"));
        }
        $this->assertViolation('23502', 'status', fn () => $this->pdo->exec("UPDATE tasks SET status = NULL WHERE id = $id"));
    }

    public function testDoneWithoutCompletionIsRejected(): void
    {
        $id = $this->task();
        $this->assertViolation('23514', 'tasks_completed_at_consistent', fn () => $this->pdo->exec("UPDATE tasks SET status = 'done' WHERE id = $id"));
    }

    public function testNotDoneWithCompletionIsRejected(): void
    {
        $id = $this->task();
        foreach (['todo', 'in_progress'] as $status) {
            $this->assertViolation('23514', 'tasks_completed_at_consistent', fn () => $this->pdo->exec("UPDATE tasks SET status = '$status', completed_at = now() WHERE id = $id"));
        }
    }

    public function testDoneWithCompletionIsAccepted(): void
    {
        $id = $this->task();
        $this->pdo->exec("UPDATE tasks SET status = 'done', completed_at = now() WHERE id = $id");
        $row = $this->row($id);
        self::assertSame('done', $row['status']);
        self::assertNotNull($row['completed_at']);
        $this->pdo->exec("UPDATE tasks SET status = 'todo', completed_at = NULL WHERE id = $id");
        self::assertSame('todo', $this->row($id)['status']);
    }

    public function testMigration0002BackfillsExistingTasksAsTodoWithoutLosingData(): void
    {
        // Base remise à l'état « 0001 seule », avec des tâches et un lien, puis migration complète (0002 à 0005).
        $this->dropSchema();
        self::assertSame(['0001_tasks_and_dependencies'], $this->migrateTo('0001_tasks_and_dependencies'));
        $this->pdo->exec("INSERT INTO tasks (title, category, due_date, recurrence_interval, recurrence_unit, description)
            VALUES ('A', 'Pro', '2026-01-31', 1, 'month', 'ctx'), ('B', 'Perso', NULL, NULL, NULL, NULL)");
        $this->pdo->exec('INSERT INTO task_dependencies (task_id, required_task_id) VALUES (1, 2)');
        $before = $this->pdo->query('SELECT id, title, category, due_date, recurrence_interval, recurrence_unit, description, created_at, updated_at FROM tasks ORDER BY id')->fetchAll();

        self::assertSame(['0002_task_status', '0003_task_project_conclusions', '0004_projects', '0005_task_importance'], (new Migrator($this->pdo, self::MIGRATIONS))->migrate());

        $after = $this->pdo->query('SELECT t.id, t.title, p.category, t.due_date, t.recurrence_interval, t.recurrence_unit, t.description, t.created_at, t.updated_at FROM tasks t JOIN projects p ON p.id = t.project_id ORDER BY t.id')->fetchAll();
        self::assertSame($before, $after, 'colonnes existantes inchangées (catégorie lue par le projet d\'accueil)');
        foreach ($this->pdo->query('SELECT status, completed_at FROM tasks')->fetchAll() as $row) {
            self::assertSame('todo', $row['status']);
            self::assertNull($row['completed_at']);
        }
        self::assertSame(['1>2'], $this->links());
    }

    // --- migration 0003 : conclusions (le projet en liste fermée a été remplacé par 0004) ---

    public function testConclusionsAcceptAnyTextIncludingEmpty(): void
    {
        $pid = $this->projectId('Divers Pro');
        foreach ([null, '', "ligne 1\nligne 2 é", str_repeat('x', 100000)] as $text) {
            $this->pdo->prepare("INSERT INTO tasks (title, project_id, conclusions) VALUES ('c', $pid, ?)")->execute([$text]);
        }
        self::assertSame(4, (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn());
    }

    public function testMigration0003KeepsExistingTasksWithNullProjectAndConclusions(): void
    {
        $this->dropSchema();
        self::assertSame(['0001_tasks_and_dependencies', '0002_task_status'], $this->migrateTo('0002_task_status'));
        $this->pdo->exec("INSERT INTO tasks (title, category, due_date, recurrence_interval, recurrence_unit, description)
            VALUES ('A', 'Pro', '2026-01-31', 1, 'month', 'ctx'), ('B', 'Perso', NULL, NULL, NULL, NULL)");
        $this->pdo->exec('INSERT INTO task_dependencies (task_id, required_task_id) VALUES (1, 2)');
        $cols = 'id, title, category, due_date, recurrence_interval, recurrence_unit, description, status, completed_at, created_at, updated_at';
        $before = $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll();

        self::assertSame(['0003_task_project_conclusions'], $this->migrateTo('0003_task_project_conclusions'));

        self::assertSame($before, $this->pdo->query("SELECT $cols FROM tasks ORDER BY id")->fetchAll(), 'colonnes existantes inchangées');
        foreach ($this->pdo->query('SELECT project, conclusions FROM tasks')->fetchAll() as $row) {
            self::assertNull($row['project']);
            self::assertNull($row['conclusions']);
        }
        self::assertSame(['1>2'], $this->links());
    }
}
