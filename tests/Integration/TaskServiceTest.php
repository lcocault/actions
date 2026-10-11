<?php

declare(strict_types=1);

namespace Actions\Tests\Integration;

use Actions\Application\CyclicDependency;
use Actions\Application\DomainError;
use Actions\Application\InvalidDependency;
use Actions\Application\InvalidTask;
use Actions\Application\RequiredTaskNotFound;
use Actions\Application\TaskIsRequired;
use Actions\Application\TaskNotFound;
use Actions\Application\TaskService;
use Actions\Bootstrap;
use Actions\Infrastructure\Migrator;

/** Cas d'usage des tâches contre PostgreSQL réel (base actions_test, remigrée dans setUp). */
final class TaskServiceTest extends DbTestCase
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
        return $this->svc->create(['title' => $title, 'category' => 'Pro'] + $extra)['id'];
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }

    private function updatedAt(int $id): string
    {
        $s = $this->pdo->prepare('SELECT updated_at::text FROM tasks WHERE id = ?');
        $s->execute([$id]);

        return (string) $s->fetchColumn();
    }

    private function ageUpdatedAt(int $id): string
    {
        $this->pdo->prepare("UPDATE tasks SET updated_at = '2000-01-01 00:00:00+00' WHERE id = ?")->execute([$id]);

        return $this->updatedAt($id);
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

    // Critère 1

    public function testCreateMinimalReturnsIdAndUnsetFields(): void
    {
        $t = $this->svc->create(['title' => 'Payer la taxe', 'category' => 'Perso']);
        self::assertIsInt($t['id']);
        self::assertSame('Payer la taxe', $t['title']);
        self::assertSame('Perso', $t['category']);
        self::assertNull($t['due_date']);
        self::assertNull($t['recurrence']);
        self::assertNull($t['estimated_minutes']);
        self::assertNull($t['description']);
        self::assertSame([], $t['required_actions']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $t['created_at']);
        self::assertSame($t, $this->svc->get($t['id']));
    }

    public function testCreateFullTaskAndStoresTitleAsIs(): void
    {
        $t = $this->svc->create([
            'title' => '  Rapport é ', 'category' => 'Pro', 'due_date' => '2020-02-29',
            'recurrence' => ['interval' => 3, 'unit' => 'month'], 'estimated_minutes' => 90, 'description' => '',
        ]);
        self::assertSame('  Rapport é ', $t['title']);
        self::assertSame('2020-02-29', $t['due_date']);
        self::assertSame(['interval' => 3, 'unit' => 'month'], $t['recurrence']);
        self::assertSame(90, $t['estimated_minutes']);
        self::assertSame('', $t['description']);
    }

    public function testPastDueDateAccepted(): void
    {
        self::assertSame('1999-12-31', $this->svc->create(['title' => 'a', 'category' => 'Pro', 'due_date' => '1999-12-31'])['due_date']);
    }

    // Critère 2 : un code distinct par règle, sans écriture

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidCreations(): array
    {
        $ok = ['title' => 'a', 'category' => 'Pro'];

        return [
            'titre vide' => [['title' => ''] + $ok, 'title_blank'],
            'titre blanc' => [['title' => '   '] + $ok, 'title_blank'],
            'titre tabulations' => [['title' => "\t\t"] + $ok, 'title_blank'],
            'titre retours' => [['title' => "\n\r\n"] + $ok, 'title_blank'],
            'titre espace insécable' => [['title' => "\u{00A0}\u{2003}"] + $ok, 'title_blank'],
            'titre trop long' => [['title' => str_repeat('x', 256)] + $ok, 'title_too_long'],
            'texte invalide' => [['title' => "a\0"] + $ok, 'text_invalid'],
            'catégorie inconnue' => [['category' => 'Autre'] + $ok, 'category_invalid'],
            'estimation nulle' => [['estimated_minutes' => 0] + $ok, 'estimated_minutes_invalid'],
            'estimation négative' => [['estimated_minutes' => -1] + $ok, 'estimated_minutes_invalid'],
            'estimation décimale' => [['estimated_minutes' => 1.5] + $ok, 'field_type_invalid'],
            'date impossible' => [['due_date' => '2026-02-30'] + $ok, 'due_date_invalid'],
            'intervalle nul' => [['due_date' => '2026-10-11', 'recurrence' => ['interval' => 0, 'unit' => 'day']] + $ok, 'recurrence_interval_invalid'],
            'intervalle négatif' => [['due_date' => '2026-10-11', 'recurrence' => ['interval' => -2, 'unit' => 'day']] + $ok, 'recurrence_interval_invalid'],
            'unité inconnue' => [['due_date' => '2026-10-11', 'recurrence' => ['interval' => 1, 'unit' => 'décade']] + $ok, 'recurrence_unit_invalid'],
            'périodicité à moitié' => [['due_date' => '2026-10-11', 'recurrence' => ['interval' => 1]] + $ok, 'recurrence_incomplete'],
            'périodicité sans date' => [['recurrence' => ['interval' => 1, 'unit' => 'day']] + $ok, 'recurrence_requires_due_date'],
            'clé inconnue' => [['titel' => 'x'] + $ok, 'field_unknown'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCreations')]
    public function testInvalidCreationRefusedWithoutWrite(array $data, string $code): void
    {
        $this->refused(InvalidTask::class, $code, fn () => $this->svc->create($data));
        self::assertSame(0, $this->rows('tasks'));
    }

    public function testInvalidCreationDetailsNameTheField(): void
    {
        $e = $this->refused(InvalidTask::class, 'category_invalid', fn () => $this->svc->create(['title' => 'a', 'category' => 'x']));
        self::assertSame(['field' => 'category'], $e->details());
    }

    // Critère 3 : liens

    public function testAddRequiredActionAndReadIt(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $t = $this->svc->addRequiredAction($a, $b);
        self::assertSame([['id' => $b, 'title' => 'B']], $t['required_actions']);
        self::assertSame($t['required_actions'], $this->svc->get($a)['required_actions']);
        self::assertSame([], $this->svc->get($b)['required_actions']);
    }

    public function testRequiredActionsSortedById(): void
    {
        $a = $this->make('A');
        $c = $this->make('C');
        $b = $this->make('B');
        $this->svc->addRequiredAction($a, $b);
        $this->svc->addRequiredAction($a, $c);
        self::assertSame([$c, $b], array_column($this->svc->get($a)['required_actions'], 'id'));
    }

    public function testDirectCycleRefusedWithoutLink(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->svc->addRequiredAction($a, $b);
        $e = $this->refused(CyclicDependency::class, 'dependency_cycle', fn () => $this->svc->addRequiredAction($b, $a));
        self::assertSame(['task_id' => $b, 'required_task_id' => $a], $e->details());
        self::assertSame(1, $this->rows('task_dependencies'));
        self::assertSame([], $this->svc->get($b)['required_actions']);
    }

    public function testIndirectCycleRefusedWithoutLink(): void
    {
        [$a, $b, $c] = [$this->make('A'), $this->make('B'), $this->make('C')];
        $this->svc->addRequiredAction($a, $b);
        $this->svc->addRequiredAction($b, $c);
        $this->refused(CyclicDependency::class, 'dependency_cycle', fn () => $this->svc->addRequiredAction($c, $a));
        self::assertSame(2, $this->rows('task_dependencies'));
    }

    public function testDiamondAccepted(): void
    {
        [$a, $b, $c, $d] = [$this->make('A'), $this->make('B'), $this->make('C'), $this->make('D')];
        foreach ([[$a, $b], [$a, $c], [$b, $d], [$c, $d]] as [$x, $y]) {
            $this->svc->addRequiredAction($x, $y);
        }
        self::assertSame(4, $this->rows('task_dependencies'));
    }

    public function testSelfDependencyRefusedWithoutLink(): void
    {
        $a = $this->make('A');
        $e = $this->refused(InvalidDependency::class, 'dependency_self', fn () => $this->svc->addRequiredAction($a, $a));
        self::assertSame(['task_id' => $a], $e->details());
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testUnknownRequiredTaskRefused(): void
    {
        $a = $this->make('A');
        $e = $this->refused(RequiredTaskNotFound::class, 'required_task_not_found', fn () => $this->svc->addRequiredAction($a, 9999));
        self::assertSame(['required_task_id' => 9999], $e->details());
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testUnknownTaskRefusedBeforeRequiredTask(): void
    {
        $b = $this->make('B');
        $e = $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->addRequiredAction(9999, $b));
        self::assertSame(['id' => 9999], $e->details());
        // les deux inconnues : la tâche est contrôlée en premier
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->addRequiredAction(9998, 9999));
        // (A, A) avec A inconnue : TaskNotFound
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->addRequiredAction(9999, 9999));
    }

    public function testAddExistingLinkIsIdempotent(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $first = $this->svc->addRequiredAction($a, $b);
        $before = $this->ageUpdatedAt($a);
        $again = $this->svc->addRequiredAction($a, $b);
        self::assertSame($first['required_actions'], $again['required_actions']);
        self::assertSame(1, $this->rows('task_dependencies'));
        self::assertSame($before, $this->updatedAt($a), 'un ajout sans effet ne touche pas updated_at');
    }

    // Retrait

    public function testRemoveRequiredAction(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->svc->addRequiredAction($a, $b);
        self::assertSame([], $this->svc->removeRequiredAction($a, $b)['required_actions']);
        self::assertSame(0, $this->rows('task_dependencies'));
    }

    public function testRemoveMissingLinkIsIdempotent(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $before = $this->ageUpdatedAt($a);
        self::assertSame([], $this->svc->removeRequiredAction($a, $b)['required_actions']);
        self::assertSame([], $this->svc->removeRequiredAction($a, 9999)['required_actions']);
        self::assertSame($before, $this->updatedAt($a));
    }

    public function testRemoveOnUnknownTaskRefused(): void
    {
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->removeRequiredAction(9999, 1));
    }

    // Critère 4 : suppression

    public function testDeleteRequiredTaskRefusedWithDependents(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $c = $this->make('C');
        $this->svc->addRequiredAction($c, $a);
        $this->svc->addRequiredAction($b, $a);
        $e = $this->refused(TaskIsRequired::class, 'task_is_required', fn () => $this->svc->delete($a));
        self::assertSame(['id' => $a, 'dependents' => [['id' => $b, 'title' => 'B'], ['id' => $c, 'title' => 'C']]], $e->details());
        self::assertSame(3, $this->rows('tasks'));
        self::assertSame(2, $this->rows('task_dependencies'));
        self::assertSame($a, $this->svc->get($a)['id']);
    }

    public function testDeleteTaskWithoutDependentsRemovesItsOwnLinks(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->svc->addRequiredAction($a, $b);
        $this->svc->delete($a);
        self::assertSame(1, $this->rows('tasks'));
        self::assertSame(0, $this->rows('task_dependencies'));
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->get($a));
        $this->svc->get($b);
    }

    public function testDeleteSucceedsAfterLinkRemoved(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $this->svc->addRequiredAction($b, $a);
        $this->refused(TaskIsRequired::class, 'task_is_required', fn () => $this->svc->delete($a));
        $this->svc->removeRequiredAction($b, $a);
        $this->svc->delete($a);
        self::assertSame(1, $this->rows('tasks'));
    }

    public function testDeleteUnknownIdIsTaskNotFound(): void
    {
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->delete(9999));
    }

    // Critère 5 : lecture et liste

    public function testGetUnknownIdIsTaskNotFound(): void
    {
        $e = $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->get(9999));
        self::assertSame(['id' => 9999], $e->details());
    }

    public function testListOrderedByDueDateThenIdNullsLast(): void
    {
        $none = $this->make('sans date');
        $late = $this->make('tard', ['due_date' => '2030-01-01']);
        $early = $this->make('tôt', ['due_date' => '2020-01-01']);
        $same = $this->make('même jour', ['due_date' => '2030-01-01']);
        $none2 = $this->make('sans date 2');
        self::assertSame([$early, $late, $same, $none, $none2], array_column($this->svc->list(), 'id'));
    }

    public function testListEmptyAndWithRequiredActions(): void
    {
        self::assertSame([], $this->svc->list());
        $a = $this->make('A');
        $b = $this->make('B');
        $this->svc->addRequiredAction($a, $b);
        $byId = array_column($this->svc->list(), null, 'id');
        self::assertSame([['id' => $b, 'title' => 'B']], $byId[$a]['required_actions']);
        self::assertSame([], $byId[$b]['required_actions']);
    }

    // Mise à jour et critère 6

    public function testUpdatePartialKeepsOtherFields(): void
    {
        $id = $this->make('A', ['due_date' => '2026-10-11', 'estimated_minutes' => 30, 'description' => 'd']);
        $t = $this->svc->update($id, ['title' => 'B']);
        self::assertSame('B', $t['title']);
        self::assertSame('2026-10-11', $t['due_date']);
        self::assertSame(30, $t['estimated_minutes']);
        self::assertSame('d', $t['description']);
        self::assertSame('Pro', $t['category']);
    }

    public function testUpdateNullClearsOptionalFields(): void
    {
        $id = $this->make('A', ['due_date' => '2026-10-11', 'estimated_minutes' => 30, 'description' => 'd',
            'recurrence' => ['interval' => 1, 'unit' => 'week']]);
        $t = $this->svc->update($id, ['recurrence' => null, 'due_date' => null, 'estimated_minutes' => null, 'description' => null]);
        self::assertNull($t['recurrence']);
        self::assertNull($t['due_date']);
        self::assertNull($t['estimated_minutes']);
        self::assertNull($t['description']);
        $row = $this->pdo->query('SELECT recurrence_interval, recurrence_unit FROM tasks')->fetch();
        self::assertSame([null, null], array_values($row));
    }

    public function testUpdateSetsRecurrenceTogether(): void
    {
        $id = $this->make('A', ['due_date' => '2026-10-11']);
        self::assertSame(['interval' => 2, 'unit' => 'year'], $this->svc->update($id, ['recurrence' => ['interval' => 2, 'unit' => 'year']])['recurrence']);
    }

    public function testUpdateClearingDueDateOfRecurringTaskRefused(): void
    {
        $id = $this->make('A', ['due_date' => '2026-10-11', 'recurrence' => ['interval' => 1, 'unit' => 'day']]);
        $this->refused(InvalidTask::class, 'recurrence_requires_due_date', fn () => $this->svc->update($id, ['due_date' => null]));
        self::assertSame('2026-10-11', $this->svc->get($id)['due_date']);
    }

    public function testUpdateValidatesMergedStateForRecurrenceWithoutDueDate(): void
    {
        $id = $this->make('A');
        $this->refused(InvalidTask::class, 'recurrence_requires_due_date', fn () => $this->svc->update($id, ['recurrence' => ['interval' => 1, 'unit' => 'day']]));
        // en même temps que la date : accepté
        $t = $this->svc->update($id, ['recurrence' => ['interval' => 1, 'unit' => 'day'], 'due_date' => '2026-10-11']);
        self::assertSame(['interval' => 1, 'unit' => 'day'], $t['recurrence']);
    }

    public function testUpdateUnknownKeyRefusedWithoutWrite(): void
    {
        $id = $this->make('A');
        $before = $this->ageUpdatedAt($id);
        foreach (['id', 'required_actions', 'created_at', 'updated_at', 'status', 'titel'] as $key) {
            $this->refused(InvalidTask::class, 'field_unknown', fn () => $this->svc->update($id, [$key => 'x']));
        }
        self::assertSame($before, $this->updatedAt($id));
    }

    public function testUpdateInvalidValueRefusedWithoutWrite(): void
    {
        $id = $this->make('A');
        $before = $this->ageUpdatedAt($id);
        $this->refused(InvalidTask::class, 'title_blank', fn () => $this->svc->update($id, ['title' => "\t\n"]));
        $this->refused(InvalidTask::class, 'category_invalid', fn () => $this->svc->update($id, ['category' => 'Autre']));
        self::assertSame('A', $this->svc->get($id)['title']);
        self::assertSame($before, $this->updatedAt($id));
    }

    public function testUpdateUnknownIdIsTaskNotFound(): void
    {
        $this->refused(TaskNotFound::class, 'task_not_found', fn () => $this->svc->update(9999, ['title' => 'x']));
    }

    public function testUpdateAdvancesUpdatedAt(): void
    {
        $id = $this->make('A');
        $old = $this->ageUpdatedAt($id);
        $this->svc->update($id, ['title' => 'B']);
        self::assertGreaterThan(strtotime($old), strtotime($this->updatedAt($id)));
    }

    public function testUpdateWithoutEffectualChangeDoesNotWrite(): void
    {
        $id = $this->make('A', ['estimated_minutes' => 5]);
        $old = $this->ageUpdatedAt($id);
        $this->svc->update($id, ['title' => 'A', 'estimated_minutes' => 5]);
        $this->svc->update($id, []);
        self::assertSame($old, $this->updatedAt($id));
    }

    public function testEffectiveLinkChangesAdvanceUpdatedAtOfTheTask(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $old = $this->ageUpdatedAt($a);
        $oldB = $this->ageUpdatedAt($b);
        $this->svc->addRequiredAction($a, $b);
        self::assertGreaterThan(strtotime($old), strtotime($this->updatedAt($a)));
        self::assertSame($oldB, $this->updatedAt($b));
        $old = $this->ageUpdatedAt($a);
        $this->svc->removeRequiredAction($a, $b);
        self::assertGreaterThan(strtotime($old), strtotime($this->updatedAt($a)));
    }

    // Sécurité : valeurs hostiles stockées littéralement (requêtes paramétrées)

    public function testSqlMetacharactersAreStoredLiterally(): void
    {
        $title = "x'); DROP TABLE tasks; --";
        $t = $this->svc->create(['title' => $title, 'category' => 'Pro', 'description' => "'; DELETE FROM tasks; --"]);
        self::assertSame($title, $this->svc->get($t['id'])['title']);
        self::assertSame(1, $this->rows('tasks'));
    }

    public function testUnreachableDatabaseIsDatabaseUnavailable(): void
    {
        $svc = new TaskService(new \Actions\Application\TaskRepository(
            static fn (): \PDO => throw new \PDOException('connexion refusée'),
        ));
        $this->expectException(\Actions\Application\DatabaseUnavailable::class);
        $svc->get(1);
    }
}
