<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\DomainError;
use Actions\Application\InvalidStatusTransition;
use Actions\Application\InvalidTask;
use Actions\Application\NextDueDate;
use Actions\Application\NextDueDateOutOfRange;
use Actions\Application\RequiredActionsNotDone;
use Actions\Application\TaskIsRequired;
use Actions\Application\TaskNotFound;
use Actions\Application\TaskRepository;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Infrastructure\Database;
use Actions\Infrastructure\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;

/** Cycle de vie des tâches (tranche B) : TaskService contre PostgreSQL réel (base actions_test, remigrée dans setUp). */
final class TaskStatusServiceTest extends DbTestCase
{
    private TaskService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS task_dependencies, tasks, schema_migrations CASCADE');
        $this->pdo->exec('DROP FUNCTION IF EXISTS task_dependencies_check_cycle()');
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->migrate();
        $this->svc = Bootstrap::taskService($this->config);
    }

    private function make(string $title = 't', array $extra = []): int
    {
        return $this->svc->create($extra + ['title' => $title, 'category' => 'Pro'])['id'];
    }

    /** Place une tâche dans un état donné par SQL direct (sans passer par le service). */
    private function force(int $id, string $status): void
    {
        $completed = $status === 'done' ? 'now()' : 'NULL';
        $this->pdo->exec("UPDATE tasks SET status = '$status', completed_at = $completed WHERE id = $id");
    }

    private function ageUpdatedAt(int $id): void
    {
        $this->pdo->exec("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00' WHERE id = $id");
    }

    /** @return array<string,mixed> ligne brute complète */
    private function raw(int $id): array
    {
        return $this->pdo->query("SELECT * FROM tasks WHERE id = $id")->fetch();
    }

    private function taskCount(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tasks')->fetchColumn();
    }

    private function links(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM task_dependencies')->fetchColumn();
    }

    /** @template T of DomainError @param class-string<T> $class @return T */
    private function refused(string $class, string $code, callable $fn): DomainError
    {
        try {
            $fn();
        } catch (DomainError $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->code());

            return $e;
        }
        self::fail("$class ($code) attendue.");
    }

    // --- création et forme ---

    public function testCreatedTaskIsTodoWithoutCompletion(): void
    {
        $task = $this->svc->create(['title' => 'x', 'category' => 'Pro']);
        self::assertSame('todo', $task['status']);
        self::assertNull($task['completed_at']);
    }

    public function testCreateRejectsStatusAndCompletedAt(): void
    {
        foreach (['status', 'completed_at'] as $key) {
            $this->refused(InvalidTask::class, 'field_unknown', fn () => $this->svc->create(['title' => 'x', 'category' => 'Pro', $key => 'done']));
        }
        self::assertSame(0, $this->taskCount());
    }

    public function testUpdateKeepsStatusUnknownAsOrdinaryField(): void
    {
        $id = $this->make();
        $this->refused(InvalidTask::class, 'field_unknown', fn () => $this->svc->update($id, ['status' => 'done']));
    }

    // --- transitions ---

    /** @return iterable<string,array{string,string}> */
    public static function allowed(): iterable
    {
        yield 'todo > in_progress' => ['todo', 'in_progress'];
        yield 'in_progress > done' => ['in_progress', 'done'];
        yield 'in_progress > todo' => ['in_progress', 'todo'];
        yield 'done > todo' => ['done', 'todo'];
    }

    #[DataProvider('allowed')]
    public function testAllowedTransition(string $from, string $to): void
    {
        $id = $this->make();
        $this->force($id, $from);
        $this->ageUpdatedAt($id);
        $before = $this->raw($id)['updated_at'];

        $result = $this->svc->changeStatus($id, $to);

        self::assertSame($to, $result['task']['status']);
        self::assertNull($result['next_occurrence']);
        self::assertSame($to === 'done', $result['task']['completed_at'] !== null);
        self::assertNotSame($before, $this->raw($id)['updated_at'], 'updated_at avance');
        self::assertSame($to, $this->raw($id)['status']);
        self::assertSame(1, $this->taskCount());
    }

    public function testCompletedAtIsSetOnDoneAndClearedOnReopening(): void
    {
        $id = $this->make();
        $this->svc->changeStatus($id, 'in_progress');
        $done = $this->svc->changeStatus($id, 'done')['task'];
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $done['completed_at']);
        $todo = $this->svc->changeStatus($id, 'todo')['task'];
        self::assertNull($todo['completed_at']);
        self::assertNull($this->raw($id)['completed_at']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function refusedTransitions(): iterable
    {
        yield 'todo > done' => ['todo', 'done'];
        yield 'done > in_progress' => ['done', 'in_progress'];
        yield 'todo > todo' => ['todo', 'todo'];
        yield 'in_progress > in_progress' => ['in_progress', 'in_progress'];
        yield 'done > done' => ['done', 'done'];
    }

    #[DataProvider('refusedTransitions')]
    public function testRefusedTransitionLeavesTaskUntouched(string $from, string $to): void
    {
        $id = $this->make();
        $this->force($id, $from);
        $this->ageUpdatedAt($id);
        $before = $this->raw($id);

        $e = $this->refused(InvalidStatusTransition::class, 'status_transition_invalid', fn () => $this->svc->changeStatus($id, $to));

        self::assertSame(['id' => $id, 'from' => $from, 'to' => $to], $e->details());
        self::assertSame($before, $this->raw($id), 'ligne et updated_at inchangés');
        self::assertSame(1, $this->taskCount());
    }

    public function testUnknownTaskIsNotFoundBeforeValueValidation(): void
    {
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->changeStatus(999, 'bidon'));
    }

    public function testInvalidStatusValues(): void
    {
        $id = $this->make();
        $this->ageUpdatedAt($id);
        $before = $this->raw($id);
        foreach ([null, 1, true, ['done'], 1.5] as $value) {
            $e = $this->refused(InvalidTask::class, 'field_type_invalid', fn () => $this->svc->changeStatus($id, $value));
            self::assertSame(['field' => 'status'], $e->details());
        }
        foreach (['', 'Done', 'DONE', 'terminee', ' done', 'doing'] as $value) {
            $e = $this->refused(InvalidTask::class, 'status_invalid', fn () => $this->svc->changeStatus($id, $value));
            self::assertSame(['field' => 'status'], $e->details());
        }
        self::assertSame($before, $this->raw($id));
    }

    // --- terminaison et actions requises ---

    public function testDoneRefusedWhileRequiredActionsAreNotDone(): void
    {
        [$main, $r1, $r2, $r3] = [$this->make('main'), $this->make('r1'), $this->make('r2'), $this->make('r3')];
        foreach ([$r3, $r1, $r2] as $r) {
            $this->svc->addRequiredAction($main, $r);
        }
        $this->force($r3, 'done');
        $this->force($r2, 'in_progress');
        $this->force($main, 'in_progress');
        $this->ageUpdatedAt($main);
        $before = $this->raw($main);

        $e = $this->refused(RequiredActionsNotDone::class, 'required_actions_not_done', fn () => $this->svc->changeStatus($main, 'done'));

        self::assertSame($main, $e->details()['id']);
        self::assertSame(
            [['id' => $r1, 'title' => 'r1', 'status' => 'todo'], ['id' => $r2, 'title' => 'r2', 'status' => 'in_progress']],
            $e->details()['required_actions'],
        );
        self::assertSame($before, $this->raw($main));
        self::assertSame(4, $this->taskCount());
    }

    public function testRefusedDoneCreatesNoOccurrenceForRecurringTask(): void
    {
        $main = $this->make('main', ['due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month']]);
        $req = $this->make('req');
        $this->svc->addRequiredAction($main, $req);
        $this->force($main, 'in_progress');

        $this->refused(RequiredActionsNotDone::class, 'required_actions_not_done', fn () => $this->svc->changeStatus($main, 'done'));

        self::assertSame(2, $this->taskCount());
        self::assertSame('in_progress', $this->raw($main)['status']);
    }

    public function testDoneSucceedsWithoutRequiredActionsOrWhenAllAreDone(): void
    {
        $alone = $this->make();
        $this->force($alone, 'in_progress');
        self::assertSame('done', $this->svc->changeStatus($alone, 'done')['task']['status']);

        $main = $this->make('main');
        $req = $this->make('req');
        $this->svc->addRequiredAction($main, $req);
        $this->force($req, 'done');
        $this->force($main, 'in_progress');
        self::assertSame('done', $this->svc->changeStatus($main, 'done')['task']['status']);
    }

    public function testStartingWithUnfinishedRequiredActionsIsAllowed(): void
    {
        $main = $this->make('main');
        $this->svc->addRequiredAction($main, $this->make('req'));
        self::assertSame('in_progress', $this->svc->changeStatus($main, 'in_progress')['task']['status']);
    }

    public function testAddingUnfinishedRequiredActionToDoneTaskIsAllowed(): void
    {
        $main = $this->make('main');
        $this->force($main, 'done');
        $task = $this->svc->addRequiredAction($main, $this->make('req'));
        self::assertSame('done', $task['status']);
        self::assertCount(1, $task['required_actions']);
    }

    // --- terminaison d'une tâche périodique ---

    public function testDoneOnRecurringTaskKeepsItAndCreatesNextOccurrence(): void
    {
        $id = $this->make('Loyer', [
            'category' => 'Perso', 'due_date' => '2026-01-31', 'recurrence' => ['interval' => 1, 'unit' => 'month'],
            'estimated_minutes' => 15, 'description' => 'virement',
        ]);
        $req = $this->make('req');
        $this->svc->addRequiredAction($id, $req);
        $this->force($req, 'done');
        $this->force($id, 'in_progress');

        $result = $this->svc->changeStatus($id, 'done');

        self::assertSame('done', $result['task']['status']);
        self::assertSame('done', $this->raw($id)['status']);
        self::assertSame('2026-01-31', $result['task']['due_date']);
        $next = $result['next_occurrence'];
        self::assertNotNull($next);
        self::assertNotSame($id, $next['id']);
        self::assertSame(
            ['title' => 'Loyer', 'category' => 'Perso', 'due_date' => '2026-02-28', 'recurrence' => ['interval' => 1, 'unit' => 'month'],
                'estimated_minutes' => 15, 'description' => 'virement', 'required_actions' => [], 'status' => 'todo', 'completed_at' => null],
            array_intersect_key($next, array_flip(['title', 'category', 'due_date', 'recurrence', 'estimated_minutes', 'description', 'required_actions', 'status', 'completed_at'])),
        );
        self::assertSame(3, $this->taskCount());
        self::assertSame(1, $this->links(), 'aucun lien copié : le lien reste sur l\'ancienne occurrence');
        self::assertSame($next, $this->svc->get($next['id']));
    }

    public function testDoneOnNonRecurringTaskCreatesNoOccurrence(): void
    {
        $id = $this->make('x', ['due_date' => '2026-01-31']);
        $this->force($id, 'in_progress');
        $result = $this->svc->changeStatus($id, 'done');
        self::assertNull($result['next_occurrence']);
        self::assertSame(1, $this->taskCount());
    }

    public function testNextDueDateIsFromStoredDateEvenIfInThePast(): void
    {
        $id = $this->make('x', ['due_date' => '2000-01-01', 'recurrence' => ['interval' => 1, 'unit' => 'week']]);
        $this->force($id, 'in_progress');
        self::assertSame('2000-01-08', $this->svc->changeStatus($id, 'done')['next_occurrence']['due_date']);
    }

    public function testNextDueDateOutOfRangeIsRefusedWithoutAnyWrite(): void
    {
        $id = $this->make('x', ['due_date' => '9999-12-31', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->force($id, 'in_progress');
        $this->ageUpdatedAt($id);
        $before = $this->raw($id);

        $e = $this->refused(NextDueDateOutOfRange::class, 'next_due_date_out_of_range', fn () => $this->svc->changeStatus($id, 'done'));

        self::assertSame(['id' => $id, 'due_date' => '9999-12-31'], $e->details());
        self::assertSame($before, $this->raw($id));
        self::assertSame(1, $this->taskCount());
    }

    public function testSecondTerminationIsRefusedAndOnlyOneOccurrenceExists(): void
    {
        $id = $this->make('x', ['due_date' => '2026-01-01', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->force($id, 'in_progress');
        $this->svc->changeStatus($id, 'done');
        $this->refused(InvalidStatusTransition::class, 'status_transition_invalid', fn () => $this->svc->changeStatus($id, 'done'));
        self::assertSame(2, $this->taskCount());
    }

    public function testReopeningDoesNotTouchTheExistingOccurrence(): void
    {
        $id = $this->make('x', ['due_date' => '2026-01-01', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->force($id, 'in_progress');
        $next = $this->svc->changeStatus($id, 'done')['next_occurrence'];
        $this->svc->changeStatus($id, 'todo');
        self::assertSame($next, $this->svc->get($next['id']));
        self::assertSame(2, $this->taskCount());
    }

    /** Oracle : NextDueDate doit coïncider avec l'arithmétique de dates de PostgreSQL (date + make_interval). */
    public function testNextDueDateMatchesPostgreSqlOracle(): void
    {
        $dates = ['2026-01-31', '2028-02-29', '2026-12-31', '2026-03-31', '2024-02-29', '2096-02-29', '2026-05-30'];
        $stmt = $this->pdo->prepare("SELECT to_char(CAST(:d AS date) + make_interval(years => :y, months => :m, days => :j), 'YYYY-MM-DD')");
        foreach ($dates as $date) {
            foreach ([1, 2, 5, 12, 13, 100] as $n) {
                foreach ([['month', 0, $n, 0], ['year', $n, 0, 0], ['day', 0, 0, $n], ['week', 0, 0, 7 * $n]] as [$unit, $y, $m, $j]) {
                    $stmt->execute(['d' => $date, 'y' => $y, 'm' => $m, 'j' => $j]);
                    self::assertSame((string) $stmt->fetchColumn(), NextDueDate::compute($date, $n, $unit), "$date + $n $unit");
                }
            }
        }
    }

    // --- patch ---

    public function testPatchWithStatusAndOtherFieldIsRefusedAfterExistenceCheck(): void
    {
        $id = $this->make();
        $this->ageUpdatedAt($id);
        $before = $this->raw($id);
        $e = $this->refused(InvalidTask::class, 'status_not_alone', fn () => $this->svc->patch($id, ['status' => 'in_progress', 'title' => 'y']));
        self::assertSame(['field' => 'status'], $e->details());
        self::assertSame($before, $this->raw($id));
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->patch(999, ['status' => 'done', 'title' => 'y']));
    }

    public function testPatchRoutesStatusAloneAndOtherFields(): void
    {
        $id = $this->make();
        $r = $this->svc->patch($id, ['status' => 'in_progress']);
        self::assertSame('in_progress', $r['task']['status']);
        self::assertNull($r['next_occurrence']);

        $r = $this->svc->patch($id, ['title' => 'nouveau']);
        self::assertSame('nouveau', $r['task']['title']);
        self::assertSame('in_progress', $r['task']['status']);
        self::assertNull($r['next_occurrence']);
    }

    public function testPatchInvalidStatusValues(): void
    {
        $id = $this->make();
        $this->refused(InvalidTask::class, 'field_type_invalid', fn () => $this->svc->patch($id, ['status' => null]));
        $this->refused(InvalidTask::class, 'status_invalid', fn () => $this->svc->patch($id, ['status' => 'x']));
    }

    public function testOtherFieldsOfADoneTaskStayEditable(): void
    {
        $id = $this->make();
        $this->force($id, 'done');
        $task = $this->svc->update($id, ['title' => 'corrigé']);
        self::assertSame('corrigé', $task['title']);
        self::assertSame('done', $task['status']);
        self::assertNotNull($task['completed_at']);
    }

    // --- list ---

    public function testListFiltersByStatusAndCategory(): void
    {
        $a = $this->make('a', ['due_date' => '2026-03-01']);
        $b = $this->svc->create(['title' => 'b', 'category' => 'Perso', 'due_date' => '2026-01-01'])['id'];
        $c = $this->make('c');
        $d = $this->svc->create(['title' => 'd', 'category' => 'Perso'])['id'];
        $this->force($b, 'in_progress');
        $this->force($c, 'done');
        $this->force($d, 'done');
        $ids = static fn (array $tasks): array => array_column($tasks, 'id');

        self::assertSame([$b, $a, $c, $d], $ids($this->svc->list()), 'sans filtre : tout, tri inchangé');
        self::assertSame([$a], $ids($this->svc->list(['status' => 'todo'])));
        self::assertSame([$b], $ids($this->svc->list(['status' => 'in_progress'])));
        self::assertSame([$c, $d], $ids($this->svc->list(['status' => 'done'])));
        self::assertSame([$b, $d], $ids($this->svc->list(['category' => 'Perso'])));
        self::assertSame([$d], $ids($this->svc->list(['status' => 'done', 'category' => 'Perso'])));
        self::assertSame([], $this->svc->list(['status' => 'todo', 'category' => 'Perso']));
    }

    public function testListRejectsInvalidFilters(): void
    {
        $this->make();
        foreach ([['status', 'bidon', 'status_invalid'], ['status', '', 'status_invalid'], ['status', 'Done', 'status_invalid'],
            ['category', 'pro', 'category_invalid'], ['category', '', 'category_invalid'],
            ['status', ['done'], 'field_type_invalid'], ['category', null, 'field_type_invalid'], ['status', 1, 'field_type_invalid']] as [$field, $value, $code]) {
            $e = $this->refused(InvalidTask::class, $code, fn () => $this->svc->list([$field => $value]));
            self::assertSame(['field' => $field], $e->details());
        }
        $e = $this->refused(InvalidTask::class, 'field_unknown', fn () => $this->svc->list(['priority' => 'x']));
        self::assertSame(['field' => 'priority'], $e->details());
    }

    public function testFilterValuesAreBoundNotInterpolated(): void
    {
        $this->make();
        $this->refused(InvalidTask::class, 'category_invalid', fn () => $this->svc->list(['category' => "Pro' OR '1'='1"]));
        self::assertCount(1, $this->svc->list());
    }

    // --- suppression ---

    public function testDeleteOfADoneRequiredTaskIsRefusedAndOfAFreeDoneTaskSucceeds(): void
    {
        [$main, $req] = [$this->make('main'), $this->make('req')];
        $this->svc->addRequiredAction($main, $req);
        $this->force($req, 'done');
        $this->refused(TaskIsRequired::class, 'task_is_required', fn () => $this->svc->delete($req));
        self::assertSame(2, $this->taskCount());

        $free = $this->make('free');
        $this->force($free, 'done');
        $this->svc->delete($free);
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->get($free));
    }

    public function testLinksStayOnTheOldOccurrenceAfterCompletion(): void
    {
        [$dependent, $recurring] = [$this->make('dep'), $this->make('rec', ['due_date' => '2026-01-01', 'recurrence' => ['interval' => 1, 'unit' => 'day']])];
        $this->svc->addRequiredAction($dependent, $recurring);
        $this->force($recurring, 'in_progress');
        $next = $this->svc->changeStatus($recurring, 'done')['next_occurrence'];
        self::assertSame([$recurring], array_column($this->svc->get($dependent)['required_actions'], 'id'));
        $this->refused(TaskIsRequired::class, 'task_is_required', fn () => $this->svc->delete($recurring));
        $this->svc->delete($next['id']); // l'occurrence n'a aucun lien : supprimable
    }

    // --- concurrence déterministe, deux connexions ---

    /** Service dont la connexion échoue vite (55P03) au lieu d'attendre un verrou. */
    private function serviceWithLockTimeout(): TaskService
    {
        $pdo = Database::connect($this->config);
        $pdo->exec("SET lock_timeout = '500ms'");

        return new TaskService(new TaskRepository(static fn (): \PDO => $pdo));
    }

    public function testConcurrentTerminationWaitsForRowLockThenIsRefused(): void
    {
        $id = $this->make('x', ['due_date' => '2026-01-01', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->force($id, 'in_progress');
        $c1 = Database::connect($this->config);
        $svc2 = $this->serviceWithLockTimeout();

        // Connexion 1 : termine la tâche et son occurrence, sans valider; elle tient le verrou de ligne.
        $c1->beginTransaction();
        $c1->exec("SELECT 1 FROM tasks WHERE id = $id FOR UPDATE");
        $c1->exec("UPDATE tasks SET status = 'done', completed_at = now() WHERE id = $id");
        $c1->exec("INSERT INTO tasks (title, category, due_date, recurrence_interval, recurrence_unit) VALUES ('x', 'Pro', '2026-01-02', 1, 'day')");

        try {
            $svc2->changeStatus($id, 'done');
            self::fail('La seconde demande doit attendre le verrou (55P03).');
        } catch (\PDOException $e) {
            self::assertSame('55P03', $e->errorInfo[0], $e->getMessage());
        }

        $c1->commit();

        // Après validation, la seconde demande relit l'état `done` : erreur de transition, aucune seconde occurrence.
        $this->refused(InvalidStatusTransition::class, 'status_transition_invalid', fn () => $svc2->changeStatus($id, 'done'));
        self::assertSame(2, $this->taskCount());
    }

    public function testReopeningARequiredActionWaitsForTheTerminationLock(): void
    {
        [$main, $req] = [$this->make('main'), $this->make('req')];
        $this->svc->addRequiredAction($main, $req);
        $this->force($req, 'done');
        $this->force($main, 'in_progress');
        $c1 = Database::connect($this->config);
        $svc2 = $this->serviceWithLockTimeout();

        // Connexion 1 : terminaison de $main en cours (FOR SHARE sur $req, non validée).
        $c1->beginTransaction();
        $c1->exec("SELECT 1 FROM tasks WHERE id = $main FOR UPDATE");
        $c1->exec("SELECT 1 FROM tasks WHERE id = $req FOR SHARE");

        try {
            $svc2->changeStatus($req, 'todo');
            self::fail('La réouverture doit attendre le verrou partagé (55P03).');
        } catch (\PDOException $e) {
            self::assertSame('55P03', $e->errorInfo[0], $e->getMessage());
        }
        $c1->rollBack();
        self::assertSame('todo', $svc2->changeStatus($req, 'todo')['task']['status']);
    }
}
