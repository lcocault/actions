<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\InvalidTask;
use Actions\Application\TaskUrgencyFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Filtre d'urgence du portail : calcul pur sur des tâches construites à la main, jour fourni. */
final class TaskUrgencyFilterTest extends TestCase
{
    private const TODAY = '2026-10-18';

    /** @return list<array{id:int,status:string,due_date:?string}> */
    private static function tasks(): array
    {
        return [
            ['id' => 1, 'status' => 'todo', 'due_date' => '2026-10-17'],        // late
            ['id' => 2, 'status' => 'in_progress', 'due_date' => '2026-10-18'], // week (J)
            ['id' => 3, 'status' => 'todo', 'due_date' => '2026-10-24'],        // week (J+6)
            ['id' => 4, 'status' => 'todo', 'due_date' => '2026-10-25'],        // month (J+7)
            ['id' => 5, 'status' => 'todo', 'due_date' => '2026-11-17'],        // month (dernier jour)
            ['id' => 6, 'status' => 'todo', 'due_date' => '2026-11-18'],        // later (mois exact)
            ['id' => 7, 'status' => 'done', 'due_date' => '2026-10-18'],        // Terminée : jamais retenue
            ['id' => 8, 'status' => 'todo', 'due_date' => null],                // sans date
            ['id' => 9, 'status' => 'done', 'due_date' => null],                // Terminée sans date
            ['id' => 10, 'status' => 'in_progress', 'due_date' => null],        // sans date
        ];
    }

    /** @param list<array<string,mixed>> $tasks @return list<int> */
    private static function ids(array $tasks): array
    {
        return array_column($tasks, 'id');
    }

    public function testEachWindowOnItsBounds(): void
    {
        self::assertSame([1], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'late', false, self::TODAY)));
        self::assertSame([2, 3], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'week', false, self::TODAY)));
        self::assertSame([4, 5], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'month', false, self::TODAY)));
        self::assertSame([6], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'later', false, self::TODAY)));
    }

    public function testDoneIsNeverKept(): void
    {
        foreach (['late', 'week', 'month', 'later'] as $u) {
            self::assertNotContains(7, self::ids(TaskUrgencyFilter::apply(self::tasks(), $u, false, self::TODAY)));
        }
        self::assertNotContains(9, self::ids(TaskUrgencyFilter::apply(self::tasks(), null, true, self::TODAY)));
    }

    public function testNoDueDateKeepsOpenTasksWithoutDateInOrder(): void
    {
        self::assertSame([8, 10], self::ids(TaskUrgencyFilter::apply(self::tasks(), null, true, self::TODAY)));
    }

    public function testBothFiltersAreAnded(): void
    {
        self::assertSame([], TaskUrgencyFilter::apply(self::tasks(), 'week', true, self::TODAY));
    }

    public function testNoFilterReturnsTheListUnchanged(): void
    {
        self::assertSame(self::tasks(), TaskUrgencyFilter::apply(self::tasks(), null, false, self::TODAY));
    }

    public function testDayChangesTheWindows(): void
    {
        self::assertSame([1, 2, 3, 4, 5], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'late', false, '2026-11-18')), 'au 18/11 : seules les échéances passées sont en retard');
        self::assertSame([6], self::ids(TaskUrgencyFilter::apply(self::tasks(), 'week', false, '2026-11-18')), 'au 18/11 : la date du jour reste dans la semaine');
        self::assertSame([], TaskUrgencyFilter::apply(self::tasks(), 'later', false, '2026-11-18'));
    }

    /** @return array<string,array{mixed}> */
    public static function invalid(): array
    {
        return [
            'inconnue' => ['bogus'],
            'vide' => [''],
            'casse' => ['WEEK'],
            'tableau' => [['week']],
            'entier' => [1],
            'booléen' => [true],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidUrgencyIsRefused(mixed $value): void
    {
        try {
            TaskUrgencyFilter::apply(self::tasks(), $value, false, self::TODAY);
            self::fail('urgency_invalid attendu');
        } catch (InvalidTask $e) {
            self::assertSame('urgency_invalid', $e->code());
            self::assertSame('urgency', $e->details()['field']);
        }
    }
}
