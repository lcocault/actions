<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Infrastructure\Database;
use Actions\Infrastructure\Migrator;

/** Migration 0001 sur base vide (actions_test), une règle par test : SQLSTATE + nom de contrainte. */
final class TaskSchemaTest extends DbTestCase
{
    private const MIGRATIONS = __DIR__ . '/../../database/migrations';

    /** @var list<\PDO> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        self::assertSame(['0001_tasks_and_dependencies'], (new Migrator($this->pdo, self::MIGRATIONS))->migrate());
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
        $o += ['category' => 'Pro', 'due_date' => null, 'recurrence_interval' => null, 'recurrence_unit' => null,
            'estimated_minutes' => null, 'description' => null];
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, category, due_date, recurrence_interval, recurrence_unit, estimated_minutes, description)'
            . ' VALUES (:t, :category, :due_date, :recurrence_interval, :recurrence_unit, :estimated_minutes, :description) RETURNING id'
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
        $id = $this->task('Titre', ['category' => 'Perso']);
        $row = $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch();
        self::assertSame('Perso', $row['category']);
        foreach (['due_date', 'recurrence_interval', 'recurrence_unit', 'estimated_minutes', 'description'] as $c) {
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
        $this->assertViolation('23502', 'title', fn () => $this->pdo->exec("INSERT INTO tasks (category) VALUES ('Pro')"));
        $this->assertViolation('23502', 'category', fn () => $this->pdo->exec("INSERT INTO tasks (title) VALUES ('x')"));
    }

    public function testBlankOrTooLongTitleIsRejected(): void
    {
        foreach (['', '   ', str_repeat('a', 256)] as $title) {
            $this->assertViolation('23514', 'tasks_title_not_blank', fn () => $this->task($title));
        }
    }

    public function testCategoryOutsidePerimeterIsRejected(): void
    {
        foreach (['pro', 'PERSO', 'Autre', ''] as $category) {
            $this->assertViolation('23514', 'tasks_category_valid', fn () => $this->task('x', ['category' => $category]));
        }
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
        self::assertSame(['applied' => ['0001_tasks_and_dependencies'], 'pending' => []], (new Migrator($this->pdo, self::MIGRATIONS))->status());
        self::assertSame('garde', $this->pdo->query("SELECT title FROM tasks WHERE id = $id")->fetchColumn());
    }
}
